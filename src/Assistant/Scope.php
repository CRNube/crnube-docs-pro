<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\Docs\Domain\CategoryTaxonomy;

/**
 * Which public documents the assistant may use. All of them by default; the site can
 * leave some out:
 *
 * - a document: "inherit" (default), "include" or "exclude";
 * - a category: '' (inherits from its parent), "include" or "exclude", which applies to
 *   everything below it, like "Who can open it".
 *
 * A document in several categories is left out if any of them is excluded (the careful
 * choice). Left-out documents stay visible and downloadable on the site; only the
 * assistant ignores them. Text already prepared is kept, so taking a document back
 * costs nothing.
 */
final class Scope
{
    public const META      = '_crnd_ai_mode';
    public const TERM_META = 'crnd_ai_mode';

    public const MODES      = ['inherit', 'include', 'exclude'];
    public const TERM_MODES = ['', 'include', 'exclude'];

    /** Fired with the IDs of the documents whose inclusion may have changed. */
    public const CHANGED = 'crnd_ai_scope_changed';

    public static function includes(int $postId): bool
    {
        $own = self::modeOf($postId);
        if ($own !== 'inherit') {
            return $own === 'include';
        }
        $terms = get_the_terms($postId, CategoryTaxonomy::NAME);
        foreach (is_array($terms) ? $terms : [] as $term) {
            if (self::categorySetting($term->term_id)['mode'] === 'exclude') {
                return false;
            }
        }
        return true;
    }

    public static function modeOf(int $postId): string
    {
        $mode = (string) get_post_meta($postId, self::META, true);
        return in_array($mode, self::MODES, true) ? $mode : 'inherit';
    }

    /**
     * The effective setting of a category: its own, or the nearest ancestor's.
     *
     * @return array{mode: string, from: int}
     */
    public static function categorySetting(int $termId): array
    {
        $seen = [];
        while ($termId > 0 && !isset($seen[$termId])) {
            $seen[$termId] = true;
            $mode          = (string) get_term_meta($termId, self::TERM_META, true);
            if ($mode === 'include' || $mode === 'exclude') {
                return ['mode' => $mode, 'from' => $termId];
            }
            $term   = get_term($termId, CategoryTaxonomy::NAME);
            $termId = $term instanceof \WP_Term ? $term->parent : 0;
        }
        return ['mode' => 'include', 'from' => 0];
    }

    public static function saveForPost(int $postId, string $mode): void
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'inherit';
        if ($mode === 'inherit') {
            delete_post_meta($postId, self::META);
        } else {
            update_post_meta($postId, self::META, $mode);
        }
        do_action('crnd_ai_scope_changed', [$postId]);
    }

    public static function saveForCategory(int $termId, string $mode): void
    {
        $mode = in_array($mode, self::TERM_MODES, true) ? $mode : '';
        if ($mode === '') {
            delete_term_meta($termId, self::TERM_META);
        } else {
            update_term_meta($termId, self::TERM_META, $mode);
        }
        // Every document in this category or below may have changed.
        $children = get_term_children($termId, CategoryTaxonomy::NAME);
        $terms    = [$termId, ...(is_array($children) ? array_map('intval', $children) : [])];
        $posts    = get_objects_in_term($terms, CategoryTaxonomy::NAME);
        do_action('crnd_ai_scope_changed', is_array($posts) ? array_map('intval', $posts) : []);
    }

    public static function categoryMode(int $termId): string
    {
        $mode = (string) get_term_meta($termId, self::TERM_META, true);
        return in_array($mode, self::TERM_MODES, true) ? $mode : '';
    }
}
