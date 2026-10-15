<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\Docs\Domain\CategoryTaxonomy;
use CRNube\Docs\Domain\DocumentPostType;

/**
 * Picks the parts of the documents that can answer a question, without embeddings or an
 * external index: documents are first ranked by title, year and category (a question
 * about "el balance 2024" points at the 2024 financial statements), then their pages are
 * scored by the question's words (BM25). Years named in the question are always
 * covered, so "compare 2024 with 2025" brings both.
 *
 * phpcs:disable WordPress.DB.SlowDBQuery, WordPress.WP.PostsPerPage -- one bounded query of the site's documents per question
 */
final class Retriever
{
    private const CANDIDATES = 12;
    private const MAX_CHARS  = 40000;
    private const MAX_PARTS  = 8;
    private const CHUNK      = 3000;

    private const STOPWORDS = [
        'a', 'al', 'algo', 'como', 'con', 'cual', 'cuales', 'cuando', 'cuanto', 'cuanta', 'cuantos', 'cuantas', 'de', 'del', 'donde',
        'el', 'ella', 'en', 'entre', 'es', 'esa', 'ese', 'eso', 'esta', 'este', 'esto', 'fue', 'fueron', 'ha', 'hay', 'la', 'las',
        'le', 'lo', 'los', 'mas', 'me', 'mi', 'muy', 'no', 'o', 'para', 'pero', 'por', 'que', 'quien', 'se', 'segun', 'ser', 'si',
        'sin', 'sobre', 'son', 'su', 'sus', 'tiene', 'un', 'una', 'uno', 'y', 'ya', 'yo', 'dame', 'dime', 'decir', 'puedes', 'favor',
        'cual', 'compara', 'comparar', 'comparalo', 'año', 'ano', 'anos', 'the', 'of', 'and', 'what', 'how', 'is',
    ];

    /**
     * @param list<int> $categories Only documents in these categories (and below); empty: all.
     * @return list<array{id: int, title: string, year: string, page: string, text: string}>
     */
    public function excerpts(string $question, array $categories = []): array
    {
        $terms = self::terms($question);
        preg_match_all('/\b(19|20)\d{2}\b/', $question, $years);
        $years = array_values(array_unique($years[0]));

        $documents = $this->candidates($terms, $years, $categories);
        if ($documents === []) {
            return [];
        }

        // Pages (or blocks) of the candidate documents.
        $chunks = [];
        foreach ($documents as $doc) {
            foreach (self::chunks((string) get_post_meta($doc['id'], Indexer::META_TEXT, true)) as $chunk) {
                $chunks[] = $doc + $chunk + ['terms' => self::terms($chunk['text'])];
            }
        }
        $scores = self::bm25($chunks, $terms);
        foreach ($chunks as $i => $chunk) {
            // A document that matched by title or year lifts all its pages a little.
            $scores[$i] += $chunk['meta'] * 0.5;
        }
        arsort($scores);

        $picked = [];
        $chars  = 0;
        $take   = static function (int $i) use (&$picked, &$chars, $chunks): void {
            if (isset($picked[$i]) || count($picked) >= self::MAX_PARTS || $chars + strlen($chunks[$i]['text']) > self::MAX_CHARS) {
                return;
            }
            $picked[$i] = true;
            $chars     += strlen($chunks[$i]['text']);
        };
        // The best page of each year asked about, then the best pages overall.
        foreach ($years as $year) {
            foreach (array_keys($scores) as $i) {
                if ($chunks[$i]['year'] === $year || str_contains($chunks[$i]['title'], $year)) {
                    $take($i);
                    break;
                }
            }
        }
        // Then the pages that share words with the question. A question that matches
        // nothing gets no excerpts, so it is answered without calling the provider.
        foreach (array_keys($scores) as $i) {
            if ($scores[$i] <= 0) {
                break;
            }
            $take($i);
        }

        $out = [];
        foreach (array_keys($picked) as $i) {
            $out[] = ['id' => $chunks[$i]['id'], 'title' => $chunks[$i]['title'], 'year' => $chunks[$i]['year'], 'page' => $chunks[$i]['page'], 'text' => $chunks[$i]['text']];
        }
        // Grouped by document, pages in order, so the excerpts read naturally.
        usort($out, static fn (array $a, array $b): int => [$a['id'], (int) $a['page']] <=> [$b['id'], (int) $b['page']]);
        return $out;
    }

    /**
     * Prepared public documents in scope, ranked by how well their title, year and
     * categories match.
     *
     * @param list<string> $terms
     * @param list<string> $years
     * @param list<int>    $categories
     * @return list<array{id: int, title: string, year: string, meta: float}>
     */
    private function candidates(array $terms, array $years, array $categories): array
    {
        $args = [
            'post_type'      => DocumentPostType::NAME,
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => Indexer::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
            'meta_value'     => 'indexed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
        ];
        if ($categories !== []) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
            $args['tax_query'] = [['taxonomy' => CategoryTaxonomy::NAME, 'terms' => $categories, 'include_children' => true]];
        }

        $ranked = [];
        foreach (get_posts($args) as $post) {
            if (!Scope::includes($post->ID)) {
                continue;
            }
            $year  = DocumentPostType::yearOf($post);
            $names = wp_get_post_terms($post->ID, CategoryTaxonomy::NAME, ['fields' => 'names']);
            $words = self::terms($post->post_title . ' ' . (is_array($names) ? implode(' ', $names) : ''));
            $meta  = (float) count(array_intersect($terms, $words));
            if ($years !== [] && in_array($year, $years, true)) {
                $meta += 3;
            }
            $ranked[] = ['id' => $post->ID, 'title' => get_the_title($post), 'year' => $year, 'meta' => $meta];
        }
        // Stable: equal scores keep the newest first.
        usort($ranked, static fn (array $a, array $b): int => $b['meta'] <=> $a['meta']);
        return array_slice($ranked, 0, self::CANDIDATES);
    }

    /**
     * The document's pages ([[página N]] markers from the transcription), or blocks of
     * about 3,000 characters when there are none.
     *
     * @return list<array{page: string, text: string}>
     */
    private static function chunks(string $text): array
    {
        $parts = preg_split('/^\[\[(?:página|pagina|page|hoja) (\d+)\]\]\s*$/mu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = [];
        if (is_array($parts) && count($parts) > 1) {
            for ($i = 1; $i < count($parts); $i += 2) {
                $page = trim((string) ($parts[$i + 1] ?? ''));
                foreach (self::split($page) as $piece) {
                    $out[] = ['page' => (string) $parts[$i], 'text' => $piece];
                }
            }
            return $out;
        }
        foreach (self::split($text) as $piece) {
            $out[] = ['page' => '', 'text' => $piece];
        }
        return $out;
    }

    /** @return list<string> Pieces of at most CHUNK characters, cut at line breaks. */
    private static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (strlen($text) <= self::CHUNK * 1.5) {
            return [$text];
        }
        $pieces  = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            if ($current !== '' && strlen($current) + strlen($line) > self::CHUNK) {
                $pieces[] = $current;
                $current  = '';
            }
            $current .= $line . "\n";
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }
        return $pieces;
    }

    /**
     * @param list<array{terms: list<string>}> $chunks
     * @param list<string>                     $query
     * @return array<int, float>
     */
    private static function bm25(array $chunks, array $query): array
    {
        $n      = max(1, count($chunks));
        $avg    = max(1, array_sum(array_map(static fn (array $c): int => count($c['terms']), $chunks)) / $n);
        $df     = [];
        $counts = [];
        foreach ($chunks as $i => $chunk) {
            $counts[$i] = array_count_values($chunk['terms']);
            foreach (array_keys($counts[$i]) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }
        $scores = [];
        foreach ($chunks as $i => $chunk) {
            $score = 0.0;
            $len   = count($chunk['terms']);
            foreach (array_unique($query) as $term) {
                $tf = $counts[$i][$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }
                $idf    = log(1 + ($n - ($df[$term] ?? 0) + 0.5) / (($df[$term] ?? 0) + 0.5));
                $score += $idf * ($tf * 2.2) / ($tf + 1.2 * (0.25 + 0.75 * $len / $avg));
            }
            $scores[$i] = $score;
        }
        return $scores;
    }

    /**
     * Lowercase words without accents or stopwords; long words are cut to 6 letters, a
     * crude stem that matches "excedente" with "excedentes".
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $text  = strtolower(remove_accents($text));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = [];
        foreach ($words as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = ctype_digit($word) ? $word : substr($word, 0, 6);
        }
        return $out;
    }
}
