<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\Docs\Access\DocumentAccess;
use CRNube\Docs\Domain\DocumentPostType;
use CRNube\DocsPro\Assistant\Providers\ProviderError;

/**
 * Prepares each public document once, so questions only send the relevant parts:
 *
 * - PDF: the AI provider transcribes it (tables included, page by page). One call per
 *   document, paid once; it is done again only when the file changes.
 * - Word, Excel, text and CSV: read locally, at no cost.
 *
 * Restricted documents are never prepared: the assistant is public. The text is kept in
 * post meta and removed when a document is restricted, unpublished or deleted.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text messages, escaped where shown
 * phpcs:disable WordPress.DB.SlowDBQuery, WordPress.WP.PostsPerPage -- background job over the site's own documents
 */
final class Indexer
{
    public const META_TEXT   = '_crnd_ai_text';
    public const META_STATUS = '_crnd_ai_status';
    public const META_HASH   = '_crnd_ai_hash';
    public const META_ERROR  = '_crnd_ai_error';
    /** Runs that started on the document and never finished (the PHP process was cut). */
    public const META_ATTEMPTS = '_crnd_ai_attempts';
    /** Parts of a long PDF already transcribed, so a cut run continues instead of paying again. */
    public const META_PARTIAL = '_crnd_ai_partial';

    private const MAX_ATTEMPTS = 3;

    /** ProviderError status of a transcription paused to stay within the time limit. */
    private const PAUSED = -1;

    public const HOOK_ONE   = 'crnd_ai_index';
    public const HOOK_BATCH = 'crnd_ai_index_batch';

    /** Bumped whenever any document's text changes; cached answers depend on it. */
    public const VERSION_OPTION = 'crnd_ai_index_version';

    private const MAX_FILE  = 20 * 1024 * 1024;
    private const MAX_TEXT  = 400000;
    private const MAX_PARTS = 5;
    private const BATCH     = 3;

    private const TYPES = ['pdf', 'docx', 'xlsx', 'txt', 'csv'];

    public function register(): void
    {
        add_action(self::HOOK_ONE, [$this, 'runOne']);
        add_action(self::HOOK_BATCH, [$this, 'batch']);
        add_action('save_post_' . DocumentPostType::NAME, [$this, 'schedule'], 40);
        add_action('crnd_access_changed', function (array $postIds): void {
            foreach ($postIds as $postId) {
                $this->schedule((int) $postId);
            }
        });
        add_action('before_delete_post', [self::class, 'forget']);
        // Left out of (or back into) the assistant. A left-out document keeps the text
        // already paid for (the assistant just ignores it), so taking it back costs
        // nothing; cached answers are dropped either way.
        add_action(Scope::CHANGED, function (array $postIds): void {
            foreach ($postIds as $postId) {
                $this->schedule((int) $postId);
            }
            self::bumpVersion();
        });
        // The Library sets the file right after creating the document, so its save_post
        // runs before there is a file: preparing is scheduled when the file arrives.
        $fileSet = function (int $metaId, int $postId, string $key): void {
            if ($key === DocumentPostType::META_FILE_ID) {
                $this->schedule($postId);
            }
        };
        add_action('added_post_meta', $fileSet, 20, 3);
        add_action('updated_post_meta', $fileSet, 20, 3);
    }

    /** After a change: forget what no longer applies, and prepare it again in the background. */
    public function schedule(int $postId): void
    {
        if (wp_is_post_revision($postId) || get_post_type($postId) !== DocumentPostType::NAME) {
            return;
        }
        if (!Scope::includes($postId)) {
            return;
        }
        if (!self::eligible($postId)) {
            self::forget($postId);
            return;
        }
        // Editing the title or categories does not change the file: nothing to pay for.
        if (self::upToDate($postId)) {
            return;
        }
        // Marked even while the assistant is off, so turning it on prepares what changed.
        update_post_meta($postId, self::META_STATUS, 'pending');
        if (Settings::isReady() && !wp_next_scheduled(self::HOOK_ONE, [$postId])) {
            wp_schedule_single_event(time() + 30, self::HOOK_ONE, [$postId]);
        }
    }

    /** Prepared, and from the file as it is now. */
    private static function upToDate(int $postId): bool
    {
        if (get_post_meta($postId, self::META_STATUS, true) !== 'indexed' || get_post_meta($postId, self::META_TEXT, true) === '') {
            return false;
        }
        $path = (string) get_attached_file((int) get_post_meta($postId, DocumentPostType::META_FILE_ID, true));
        return $path !== '' && is_readable($path) && sha1_file($path) === get_post_meta($postId, self::META_HASH, true);
    }

    /**
     * One document from the background queue. A check of everything pending is booked
     * first, so if the host cuts this run short, what is left is picked up again.
     */
    public function runOne(int $postId): void
    {
        if (!wp_next_scheduled(self::HOOK_BATCH)) {
            wp_schedule_single_event(time() + 3 * MINUTE_IN_SECONDS, self::HOOK_BATCH);
        }
        $this->index($postId);
    }

    /**
     * Whether this request is near the host's time limit: long PDFs then stop between
     * two parts (already saved) and go on in the next run, instead of being cut.
     */
    private static function outOfTime(): bool
    {
        $limit  = (int) ini_get('max_execution_time');
        $budget = $limit > 0 ? $limit * 0.5 : 240;
        $start  = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- set by PHP, cast to float
        return microtime(true) - $start > $budget;
    }

    /** Queues every public document that is not prepared yet. */
    public static function queueAll(): void
    {
        if (!wp_next_scheduled(self::HOOK_BATCH)) {
            wp_schedule_single_event(time(), self::HOOK_BATCH);
        }
    }

    public function batch(): void
    {
        $pending = self::pending();
        if ($pending === []) {
            return;
        }
        // The next run is booked first: if the host's time limit cuts this one short,
        // the queue still goes on. With the spending cap reached, it waits an hour.
        if (!wp_next_scheduled(self::HOOK_BATCH)) {
            wp_schedule_single_event(time() + (Usage::remaining() > 0 ? 90 : HOUR_IN_SECONDS), self::HOOK_BATCH);
        }
        foreach (array_slice($pending, 0, self::BATCH) as $postId) {
            if (Usage::remaining() <= 0) {
                return;
            }
            $this->index($postId);
        }
        if (self::pending() === []) {
            wp_clear_scheduled_hook(self::HOOK_BATCH);
        }
    }

    /** @return list<int> Public documents with a supported file whose text is missing or outdated. */
    public static function pending(): array
    {
        $ids = get_posts([
            'post_type'      => DocumentPostType::NAME,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- admin batch, at most 500 rows
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => self::META_STATUS, 'compare' => 'NOT EXISTS'],
                ['key' => self::META_STATUS, 'value' => ['', 'pending'], 'compare' => 'IN'],
            ],
        ]);
        return array_values(array_filter(array_map('intval', $ids), [self::class, 'eligible']));
    }

    /**
     * @return array{indexed: int, pending: int, failed: int, skipped: int, excluded: int}
     */
    public static function counts(): array
    {
        $counts = ['indexed' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0, 'excluded' => 0];
        $ids    = get_posts(['post_type' => DocumentPostType::NAME, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($ids as $id) {
            if (!Scope::includes((int) $id)) {
                $counts['excluded']++;
                continue;
            }
            if (!self::eligible((int) $id)) {
                $counts['skipped']++;
                continue;
            }
            $status = (string) get_post_meta((int) $id, self::META_STATUS, true);
            $key    = in_array($status, ['indexed', 'failed'], true) ? $status : 'pending';
            $counts[$key]++;
        }
        return $counts;
    }

    /** Public, published, not left out of the assistant, with a readable file that is not too big. */
    public static function eligible(int $postId): bool
    {
        if (get_post_status($postId) !== 'publish' || DocumentAccess::forPost($postId)['restricted'] || !Scope::includes($postId)) {
            return false;
        }
        $fileId = (int) get_post_meta($postId, DocumentPostType::META_FILE_ID, true);
        $path   = $fileId > 0 ? (string) get_attached_file($fileId) : '';
        return $path !== ''
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TYPES, true)
            && is_readable($path)
            && (int) filesize($path) <= self::MAX_FILE;
    }

    public static function forget(int $postId): void
    {
        if (get_post_meta($postId, self::META_TEXT, true) !== '') {
            self::bumpVersion();
        }
        foreach ([self::META_TEXT, self::META_STATUS, self::META_HASH, self::META_ERROR, self::META_ATTEMPTS, self::META_PARTIAL] as $key) {
            delete_post_meta($postId, $key);
        }
    }

    public function index(int $postId): void
    {
        if (!Scope::includes($postId)) {
            return;
        }
        if (!self::eligible($postId)) {
            self::forget($postId);
            return;
        }
        $fileId = (int) get_post_meta($postId, DocumentPostType::META_FILE_ID, true);
        $path   = (string) get_attached_file($fileId);
        $hash   = (string) sha1_file($path);
        // The same file already prepared: never pay for it twice.
        if ($hash === get_post_meta($postId, self::META_HASH, true) && get_post_meta($postId, self::META_TEXT, true) !== '') {
            update_post_meta($postId, self::META_STATUS, 'indexed');
            return;
        }

        // A document that keeps stopping the process (too big for the host's time
        // limit) is set aside instead of blocking the queue forever.
        $attempts = (int) get_post_meta($postId, self::META_ATTEMPTS, true) + 1;
        if ($attempts > self::MAX_ATTEMPTS) {
            update_post_meta($postId, self::META_STATUS, 'failed');
            update_post_meta($postId, self::META_ERROR, __('Preparing it took longer than the server allows. Try a smaller file, or ask the host for a longer PHP time limit.', 'crnube-docs-pro'));
            delete_post_meta($postId, self::META_ATTEMPTS);
            return;
        }
        update_post_meta($postId, self::META_ATTEMPTS, $attempts);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            $text = match ($extension) {
                'pdf'   => $this->transcribe($postId, $path, $hash),
                'docx'  => OfficeText::docx($path),
                'xlsx'  => OfficeText::xlsx($path),
                default => (string) file_get_contents($path),
            };
        } catch (ProviderError $e) {
            delete_post_meta($postId, self::META_ATTEMPTS);
            if ($e->status === self::PAUSED) {
                // Paused between two parts to stay within the time limit: go on shortly.
                update_post_meta($postId, self::META_STATUS, 'pending');
                wp_schedule_single_event(time() + 30, self::HOOK_ONE, [$postId]);
                return;
            }
            $message = $e->getMessage();
            if ($e->status === 400) {
                $message = __('The provider could not read this PDF: it may be damaged, password protected, or not a real PDF.', 'crnube-docs-pro') . ' (' . $message . ')';
            }
            update_post_meta($postId, self::META_STATUS, $e->retryable ? 'pending' : 'failed');
            update_post_meta($postId, self::META_ERROR, $message);
            if ($e->retryable) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, self::HOOK_ONE, [$postId]);
            }
            return;
        }

        delete_post_meta($postId, self::META_ATTEMPTS);
        $text = trim(wp_check_invalid_utf8($text, true));
        if ($text === '') {
            update_post_meta($postId, self::META_STATUS, 'failed');
            update_post_meta($postId, self::META_ERROR, __('No text could be read from the file.', 'crnube-docs-pro'));
            return;
        }
        update_post_meta($postId, self::META_TEXT, wp_slash(mb_substr($text, 0, self::MAX_TEXT)));
        update_post_meta($postId, self::META_HASH, $hash);
        update_post_meta($postId, self::META_STATUS, 'indexed');
        delete_post_meta($postId, self::META_ERROR);
        self::bumpVersion();
    }

    /**
     * The PDF, transcribed by the provider. Long documents come in several parts: the
     * provider is asked to continue where it stopped.
     *
     * @throws ProviderError
     */
    private function transcribe(int $postId, string $path, string $hash): string
    {
        if (!Settings::isReady()) {
            throw new ProviderError(__('The assistant is not configured.', 'crnube-docs-pro'), true);
        }
        // A rough ceiling of what one part may cost, checked against the caps before spending.
        [$in, $out] = Settings::prices();
        $estimate   = (int) ceil(((int) filesize($path) / 4) * $in + 16000 * $out);
        if (Usage::remaining() < $estimate) {
            throw new ProviderError(__('The spending cap was reached; it will be prepared later.', 'crnube-docs-pro'), true);
        }

        @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed on every host
        $pdf    = base64_encode((string) file_get_contents($path)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API takes PDFs in base64
        $client = Settings::client();
        $system = 'You transcribe documents for a search index. Output only the transcription, never comments.';
        $ask    = "Transcribe this whole document as plain text, in its original language.\n"
            . "- Start each page with a line [[página N]].\n"
            . "- Write tables as Markdown tables, with every number exactly as printed (signs, parentheses, thousand separators, currency).\n"
            . "- Keep headings, notes and footnotes. Leave out page headers and footers that repeat on every page.\n"
            . '- Do not summarize, translate or explain anything.';

        // Parts paid for by an earlier run that was cut short (same file): continue from there.
        $saved = get_post_meta($postId, self::META_PARTIAL, true);
        $parts = is_array($saved) && ($saved['hash'] ?? '') === $hash ? array_values(array_map('strval', (array) $saved['parts'])) : [];

        $messages = [['role' => 'user', 'text' => $ask, 'pdf' => $pdf, 'filename' => basename($path)]];
        foreach ($parts as $done) {
            $messages[] = ['role' => 'assistant', 'text' => $done];
            $messages[] = ['role' => 'user', 'text' => 'Continue the transcription exactly where you stopped, without repeating anything.'];
        }

        $text = implode('', $parts);
        for ($part = count($parts); $part < self::MAX_PARTS; $part++) {
            if ($parts !== [] && self::outOfTime()) {
                throw new ProviderError('paused', true, self::PAUSED);
            }
            $result = $client->complete($system, $messages, 16000, 240);
            Usage::log(Usage::INDEXED, $result->inputTokens, $result->outputTokens, Settings::cost($result->inputTokens, $result->outputTokens), '', '', [$postId]);
            $text .= $result->text;
            if (!$result->truncated || Usage::remaining() < $estimate) {
                break;
            }
            $parts[]    = $result->text;
            $messages[] = ['role' => 'assistant', 'text' => $result->text];
            $messages[] = ['role' => 'user', 'text' => 'Continue the transcription exactly where you stopped, without repeating anything.'];
            update_post_meta($postId, self::META_PARTIAL, wp_slash(['hash' => $hash, 'parts' => $parts]));
        }
        delete_post_meta($postId, self::META_PARTIAL);
        return $text;
    }

    private static function bumpVersion(): void
    {
        update_option(self::VERSION_OPTION, (int) get_option(self::VERSION_OPTION, 0) + 1, false);
    }
}
