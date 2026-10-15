<?php
declare(strict_types=1);

namespace CRNube\DocsPro\License;

/**
 * Updates from the license server, through the "Update URI" header (WordPress 5.8+):
 * WordPress asks `update_plugins_crnube.com` instead of WordPress.org.
 *
 * Every package is checked before it is installed: its SHA-256 must carry a valid
 * Ed25519 signature made offline with the CRNube release key (CRND_PRO_RELEASE_KEY).
 * A package that fails the check is deleted and the update stops.
 */
final class Updater
{
    public const CACHE = 'crnd_pro_update';

    private const HOST = 'crnube.com';

    public function __construct(private readonly License $license)
    {
    }

    public function register(): void
    {
        add_filter('update_plugins_' . self::HOST, [$this, 'offer'], 10, 3);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 4);
        add_action('in_plugin_update_message-' . plugin_basename(CRND_PRO_FILE), [$this, 'updateMessage'], 10, 2);
    }

    /**
     * @param array<string, mixed>|false $update
     * @param array<string, mixed>       $pluginData
     * @return array<string, mixed>|false
     */
    public function offer(array|false $update, array $pluginData, string $pluginFile): array|false
    {
        if ($pluginFile !== plugin_basename(CRND_PRO_FILE)) {
            return $update;
        }
        $info = $this->info();
        if ($info === null) {
            return $update;
        }
        return [
            'id'           => self::HOST . '/' . License::PRODUCT,
            'slug'         => License::PRODUCT,
            'plugin'       => $pluginFile,
            'version'      => $info['version'],
            'url'          => 'https://crnube.com',
            'package'      => $info['package'],
            'tested'       => $info['tested'],
            'requires'     => $info['requires'],
            'requires_php' => $info['requires_php'],
        ];
    }

    /** The "View details" window of the plugins screen. */
    public function details(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== License::PRODUCT) {
            return $result;
        }
        $info = $this->info();
        if ($info === null) {
            return $result;
        }
        return (object) [
            'name'          => 'CRNube Docs Pro',
            'slug'          => License::PRODUCT,
            'version'       => $info['version'],
            'author'        => '<a href="https://crnube.com">CRNube</a>',
            'homepage'      => 'https://crnube.com',
            'requires'      => $info['requires'],
            'tested'        => $info['tested'],
            'requires_php'  => $info['requires_php'],
            'last_updated'  => $info['date'],
            'download_link' => $info['package'],
            'sections'      => [
                'description' => esc_html__('Protected area and reports for CRNube Docs.', 'crnube-docs-pro'),
                'changelog'   => wp_kses_post(wpautop($info['changelog'])),
            ],
        ];
    }

    /**
     * Downloads our packages here, to check their signature before WordPress unzips them.
     *
     * @param array<string, mixed> $hookExtra
     */
    public function download(mixed $reply, string $package, object $upgrader, array $hookExtra = []): mixed
    {
        if ($reply !== false || !str_starts_with($package, License::server())) {
            return $reply;
        }
        $info = $this->info(true);
        if ($info === null || $info['signature'] === '') {
            return new \WP_Error('crnd_unsigned', __('The update has no signature, so it was not installed.', 'crnube-docs-pro'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!self::verify($file, $info['signature'])) {
            wp_delete_file($file);
            return new \WP_Error('crnd_bad_signature', __('The update did not pass the security check (its signature does not match), so it was not installed. Contact CRNube.', 'crnube-docs-pro'));
        }
        return $file;
    }

    /** Ed25519 signature of the file's SHA-256, made with the CRNube release key. */
    public static function verify(string $file, string $signature): bool
    {
        try {
            $key = sodium_base642bin(CRND_PRO_RELEASE_KEY, SODIUM_BASE64_VARIANT_ORIGINAL);
            $sig = sodium_base642bin($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
            if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }
            $digest = hash_file('sha256', $file, true);
            return $digest !== false && sodium_crypto_sign_verify_detached($sig, $digest, $key);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $pluginData
     * @param object               $response
     */
    public function updateMessage(array $pluginData, object $response): void
    {
        if (empty($response->package)) {
            echo ' ' . esc_html__('Activate or renew the license in Documents › License to update.', 'crnube-docs-pro');
        }
    }

    /**
     * The latest release, from the server, cached for 12 hours (1 hour after a failure).
     *
     * @return array{version: string, package: string, signature: string, tested: string, requires: string, requires_php: string, changelog: string, date: string}|null
     */
    private function info(bool $fresh = false): ?array
    {
        $cached = $fresh ? false : get_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached['version'] !== '' ? $cached : null;
        }

        $answer = $this->license->updateInfo();
        $info   = ['version' => '', 'package' => '', 'signature' => '', 'tested' => '', 'requires' => '', 'requires_php' => '', 'changelog' => '', 'date' => ''];
        if (is_array($answer) && preg_match('/^\d+\.\d+\.\d+$/', (string) ($answer['version'] ?? ''))) {
            $package = esc_url_raw((string) ($answer['package'] ?? ''));
            $info    = [
                'version'      => (string) $answer['version'],
                // Only packages from the license server itself.
                'package'      => str_starts_with($package, License::server()) ? $package : '',
                'signature'    => (string) ($answer['signature'] ?? ''),
                'tested'       => sanitize_text_field((string) ($answer['tested'] ?? '')),
                'requires'     => sanitize_text_field((string) ($answer['requires'] ?? '')),
                'requires_php' => sanitize_text_field((string) ($answer['requires_php'] ?? '')),
                'changelog'    => (string) ($answer['changelog'] ?? ''),
                'date'         => sanitize_text_field((string) ($answer['date'] ?? '')),
            ];
        }
        set_transient(self::CACHE, $info, $info['version'] !== '' ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $info['version'] !== '' ? $info : null;
    }
}
