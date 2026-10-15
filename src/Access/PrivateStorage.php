<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

use CRNube\Docs\Access\DocumentAccess;
use CRNube\Docs\Domain\DocumentPostType;

/**
 * Keeps the files of restricted documents out of public reach.
 *
 * WordPress serves everything in uploads/ to anyone who knows the URL. So when a document
 * becomes restricted its file is moved to uploads/crnd-private-{random}/, a folder with
 * a random name (unguessable even on servers that ignore .htaccess) and an .htaccess
 * that denies all requests. Files there are only delivered by the download controller,
 * after the access check. When the document is public again the file moves back.
 */
final class PrivateStorage
{
    private const FOLDER_OPTION = 'crnd_private_folder';
    private const META_ORIGINAL = '_crnd_public_path';
    private const TEST_OPTION   = 'crnd_private_check';

    /** @var array<int, true> documents already synced in this request */
    private array $done = [];

    public function register(): void
    {
        add_action('crnd_access_changed', [$this, 'syncMany']);
        // Category changes and file replacements can change where a file belongs.
        add_action('set_object_terms', function (int $objectId, array $terms, array $ttIds, string $taxonomy): void {
            if ($taxonomy === \CRNube\Docs\Domain\CategoryTaxonomy::NAME && DocumentAccess::isDocument($objectId)) {
                unset($this->done[$objectId]);
                $this->sync($objectId);
            }
        }, 20, 4);
        add_action('updated_post_meta', function (int $metaId, int $postId, string $key): void {
            if ($key === DocumentPostType::META_FILE_ID && DocumentAccess::isDocument($postId)) {
                unset($this->done[$postId]);
                $this->sync($postId);
            }
        }, 20, 3);
        add_action('save_post_' . DocumentPostType::NAME, fn (int $postId) => $this->sync($postId), 30);
    }

    /** @param list<int> $postIds */
    public function syncMany(array $postIds): void
    {
        foreach ($postIds as $postId) {
            unset($this->done[$postId]);
            $this->sync((int) $postId);
        }
    }

    /** Puts the document's file where its access says it belongs. */
    public function sync(int $postId): void
    {
        if (isset($this->done[$postId]) || !DocumentAccess::isDocument($postId)) {
            return;
        }
        $this->done[$postId] = true;

        $fileId = (int) get_post_meta($postId, DocumentPostType::META_FILE_ID, true);
        if ($fileId <= 0) {
            return;
        }
        $restricted = DocumentAccess::forPost($postId)['restricted'];
        $isPrivate  = self::isPrivateFile($fileId);

        if ($restricted && !$isPrivate) {
            $this->move($fileId, true);
        } elseif (!$restricted && $isPrivate && !$this->usedByOtherRestricted($fileId, $postId)) {
            $this->move($fileId, false);
        }
    }

    public static function folder(): string
    {
        $folder = (string) get_option(self::FOLDER_OPTION);
        if (!preg_match('/^crnd-private-[a-f0-9]{16}$/', $folder)) {
            $folder = 'crnd-private-' . bin2hex(random_bytes(8));
            update_option(self::FOLDER_OPTION, $folder, false);
        }
        return $folder;
    }

    public static function isPrivateFile(int $fileId): bool
    {
        return str_starts_with((string) get_post_meta($fileId, '_wp_attached_file', true), self::folder() . '/');
    }

    /** Creates the folder with its deny rules; safe to call any time. */
    public static function prepareFolder(): string
    {
        $dir = trailingslashit(wp_get_upload_dir()['basedir']) . self::folder();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $rules = "# CRNube Docs: files of restricted documents. Never served directly.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            . "Options -Indexes\n";
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', $rules);
        }
        if (!is_file($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!is_file($dir . '/web.config')) {
            file_put_contents($dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        }
        // phpcs:enable
        return $dir;
    }

    /**
     * Checks over HTTP that a file in the private folder cannot be downloaded. The result
     * is kept for a day: true (protected), false (exposed), or null if it could not be tested.
     */
    public static function selfTest(bool $force = false): ?bool
    {
        $cached = get_transient(self::TEST_OPTION);
        if (!$force && is_array($cached)) {
            return $cached['ok'];
        }
        $dir  = self::prepareFolder();
        $name = 'crnd-check.txt';
        file_put_contents($dir . '/' . $name, 'private'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $url      = trailingslashit(wp_get_upload_dir()['baseurl']) . self::folder() . '/' . $name;
        $response = wp_remote_get($url, ['timeout' => 8, 'sslverify' => false, 'redirection' => 0]);
        wp_delete_file($dir . '/' . $name);

        $ok = is_wp_error($response)
            ? null
            : !(wp_remote_retrieve_response_code($response) === 200 && trim(wp_remote_retrieve_body($response)) === 'private');
        set_transient(self::TEST_OPTION, ['ok' => $ok], DAY_IN_SECONDS);
        return $ok;
    }

    /** Moves the file (and its image sizes) into or out of the private folder. */
    private function move(int $fileId, bool $toPrivate): void
    {
        $uploads  = trailingslashit(wp_get_upload_dir()['basedir']);
        $relative = (string) get_post_meta($fileId, '_wp_attached_file', true);
        if ($relative === '' || !is_file($uploads . $relative)) {
            return;
        }

        if ($toPrivate) {
            self::prepareFolder();
            $target = self::folder() . '/' . $relative;
            update_post_meta($fileId, self::META_ORIGINAL, $relative);
        } else {
            $original = (string) get_post_meta($fileId, self::META_ORIGINAL, true);
            $target   = $original !== '' ? $original : substr($relative, strlen(self::folder()) + 1);
        }

        $from = dirname($uploads . $relative);
        $to   = dirname($uploads . $target);
        wp_mkdir_p($to);
        $target = $this->freeName($to, wp_basename($target), dirname($target));

        $files = [wp_basename($relative)];
        $meta  = wp_get_attachment_metadata($fileId);
        if (is_array($meta) && !empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $files[] = (string) $size['file'];
                }
            }
        }
        if (is_array($meta) && !empty($meta['original_image'])) {
            $files[] = (string) $meta['original_image'];
        }

        foreach (array_unique($files) as $index => $file) {
            $source = $from . '/' . $file;
            // The main file may have been renamed to avoid a clash; the sizes keep their names.
            $dest = $to . '/' . ($index === 0 ? wp_basename($target) : $file);
            if (is_file($source) && !is_file($dest)) {
                rename($source, $dest); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            }
        }

        update_post_meta($fileId, '_wp_attached_file', $target);
        if (is_array($meta) && isset($meta['file'])) {
            $meta['file'] = $target;
            wp_update_attachment_metadata($fileId, $meta);
        }
        if (!$toPrivate) {
            delete_post_meta($fileId, self::META_ORIGINAL);
        }
        clean_post_cache($fileId);
        \CRNube\Docs\Support\Cache::flush();
    }

    /** "x.pdf" or, if taken, "x-1.pdf", as a path relative to uploads. */
    private function freeName(string $dir, string $name, string $relativeDir): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $file = $name;
        for ($i = 1; is_file($dir . '/' . $file); $i++) {
            $file = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
        }
        return ltrim(($relativeDir === '.' ? '' : $relativeDir . '/') . $file, '/');
    }

    /** A file shared by two documents stays private while any of them is restricted. */
    private function usedByOtherRestricted(int $fileId, int $postId): bool
    {
        $others = get_posts([
            'post_type'      => DocumentPostType::NAME,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 20,
            'post__not_in'   => [$postId],
            'meta_key'       => DocumentPostType::META_FILE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => (string) $fileId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);
        foreach ($others as $other) {
            if (DocumentAccess::forPost((int) $other)['restricted']) {
                return true;
            }
        }
        return false;
    }
}
