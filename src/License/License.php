<?php
declare(strict_types=1);

namespace CRNube\DocsPro\License;

/**
 * The license of this site and the conversation with the license server
 * (crnube.com, plugin "CRNube Licencias").
 *
 * The key is stored in a non-autoloaded option and only ever sent to the license server,
 * over HTTPS. A valid license gives updates and support; it is checked once a day, and a
 * server that cannot be reached never changes the stored status.
 */
final class License
{
    public const PRODUCT = 'crnube-docs-pro';

    private const OPTION     = 'crnd_pro_license';
    private const CRON       = 'crnd_pro_license_check';
    private const KEY_FORMAT = '/^[A-Z0-9]{4,8}(-[A-Z0-9]{4,8}){2,6}$/';

    /** Statuses the server can answer with. */
    public const STATUSES = ['valid', 'expired', 'revoked', 'invalid_key', 'site_limit', 'wrong_product', 'not_activated'];

    public function register(): void
    {
        add_action(self::CRON, [$this, 'check']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /**
     * @return array{key: string, status: string, expires: string, customer: string, sites_used: int, sites_max: int, checked: int, error: string}
     */
    public function data(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        return [
            'key'        => (string) ($stored['key'] ?? ''),
            'status'     => (string) ($stored['status'] ?? ''),
            'expires'    => (string) ($stored['expires'] ?? ''),
            'customer'   => (string) ($stored['customer'] ?? ''),
            'sites_used' => (int) ($stored['sites_used'] ?? 0),
            'sites_max'  => (int) ($stored['sites_max'] ?? 0),
            'checked'    => (int) ($stored['checked'] ?? 0),
            'error'      => (string) ($stored['error'] ?? ''),
        ];
    }

    public function key(): string
    {
        return $this->data()['key'];
    }

    /** Active and not past its expiry date (the server is the authority; this is a local guard). */
    public function isValid(): bool
    {
        $data = $this->data();
        if ($data['status'] !== 'valid') {
            return false;
        }
        return $data['expires'] === '' || $data['expires'] >= gmdate('Y-m-d');
    }

    /** "CRND-••••-••••-••••-7KQ2": enough to recognize the key, not to reuse it. */
    public function maskedKey(): string
    {
        $key = $this->key();
        if ($key === '') {
            return '';
        }
        $parts = explode('-', $key);
        $last  = (string) array_pop($parts);
        $first = (string) array_shift($parts);
        return implode('-', [$first, ...array_fill(0, count($parts), '••••'), $last]);
    }

    public static function normalizeKey(string $key): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', trim($key)));
    }

    public static function isWellFormed(string $key): bool
    {
        return (bool) preg_match(self::KEY_FORMAT, $key);
    }

    /** Registers this site under the key. */
    public function activate(string $key): string|\WP_Error
    {
        $key = self::normalizeKey($key);
        if (!self::isWellFormed($key)) {
            return new \WP_Error('invalid_key', __('That does not look like a license key. Copy it again from the email you received.', 'crnube-docs-pro'));
        }
        $answer = $this->request('activate', $key);
        if (is_wp_error($answer)) {
            return $answer;
        }
        $this->store($key, $answer);
        $this->afterChange();
        return (string) $answer['status'];
    }

    /** Frees this site's slot on the server and forgets the key. */
    public function deactivate(): void
    {
        $key = $this->key();
        if ($key !== '') {
            // Forgotten here even if the server cannot be reached; the slot can be freed from crnube.com.
            $this->request('deactivate', $key);
        }
        delete_option(self::OPTION);
        $this->afterChange();
    }

    /** The daily check (and "Check now"). Network errors keep the last known status. */
    public function check(): void
    {
        $key = $this->key();
        if ($key === '') {
            return;
        }
        $answer = $this->request('check', $key);
        if (is_wp_error($answer)) {
            if (!in_array($answer->get_error_code(), self::STATUSES, true)) {
                $this->update(['error' => $answer->get_error_message()]);
                return;
            }
            $answer = ['status' => $answer->get_error_code()];
        }
        $before = $this->data()['status'];
        $this->store($key, $answer);
        if ($before !== (string) $answer['status']) {
            $this->afterChange();
        }
    }

    /**
     * Update details for this version (and, with a valid license, a download link).
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function updateInfo(): array|\WP_Error
    {
        $key = $this->key();
        if ($key === '') {
            return new \WP_Error('no_key', __('No license key.', 'crnube-docs-pro'));
        }
        return $this->request('update', $key);
    }

    public static function server(): string
    {
        $base = defined('CRND_PRO_LICENSE_SERVER') ? (string) constant('CRND_PRO_LICENSE_SERVER') : 'https://crnube.com';
        /**
         * Base URL of the WordPress site running "CRNube Licencias".
         *
         * @param string $base
         */
        $base = (string) apply_filters('crnd_pro_license_server', $base);
        return trailingslashit($base) . 'wp-json/crnube-licenses/v1/';
    }

    /** The key travels only over HTTPS (plain HTTP is allowed to a local test server only). */
    public static function serverIsSafe(): bool
    {
        $url  = self::server();
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        return str_starts_with($url, 'https://') || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }

    /** This site, as the server counts it. */
    public static function site(): string
    {
        return untrailingslashit((string) preg_replace('#^https?://(www\.)?#i', '', strtolower(home_url())));
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function request(string $endpoint, string $key): array|\WP_Error
    {
        if (!self::serverIsSafe()) {
            return new \WP_Error('insecure_server', self::message('insecure_server'));
        }
        global $wp_version;
        $response = wp_remote_post(self::server() . $endpoint, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'    => (string) wp_json_encode([
                'key'     => $key,
                'product' => self::PRODUCT,
                'site'    => self::site(),
                'version' => CRND_PRO_VERSION,
                'wp'      => (string) $wp_version,
                'php'     => PHP_VERSION,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('unreachable', self::message('unreachable'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new \WP_Error('bad_response', self::message('bad_response'));
        }
        if ($code >= 400) {
            $error = sanitize_key((string) ($body['code'] ?? 'error'));
            return new \WP_Error($error, self::message($error));
        }
        return $body;
    }

    /** What each server answer means, in words for the site owner. */
    public static function message(string $status): string
    {
        return match ($status) {
            'valid'         => __('Active. Updates and support are on.', 'crnube-docs-pro'),
            'expired'       => __('The license expired. Everything keeps working, but updates stop until it is renewed.', 'crnube-docs-pro'),
            'revoked'       => __('This license was cancelled. Contact CRNube.', 'crnube-docs-pro'),
            'invalid_key'   => __('The license key does not exist. Check that it was copied completely.', 'crnube-docs-pro'),
            'site_limit'    => __('The license is already used on as many sites as it allows. Free a site from crnube.com or ask for more.', 'crnube-docs-pro'),
            'wrong_product' => __('This key is for another product.', 'crnube-docs-pro'),
            'not_activated' => __('This site is no longer registered under the license. Activate it again.', 'crnube-docs-pro'),
            'rate_limited'  => __('Too many attempts. Wait an hour and try again.', 'crnube-docs-pro'),
            'unreachable'   => __('The license server could not be reached. Try again in a few minutes.', 'crnube-docs-pro'),
            'bad_response'  => __('The license server gave an unexpected answer.', 'crnube-docs-pro'),
            'insecure_server' => __('The license server must use HTTPS.', 'crnube-docs-pro'),
            default         => __('The license could not be checked.', 'crnube-docs-pro'),
        };
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function store(string $key, array $answer): void
    {
        $status = (string) ($answer['status'] ?? '');
        update_option(self::OPTION, [
            'key'        => $key,
            'status'     => in_array($status, self::STATUSES, true) ? $status : 'invalid_key',
            'expires'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($answer['expires'] ?? '')) ? (string) $answer['expires'] : '',
            'customer'   => sanitize_text_field((string) ($answer['customer'] ?? '')),
            'sites_used' => (int) ($answer['sites_used'] ?? 0),
            'sites_max'  => (int) ($answer['sites_max'] ?? 0),
            'checked'    => time(),
            'error'      => '',
        ], false);
    }

    /** @param array<string, mixed> $changes */
    private function update(array $changes): void
    {
        update_option(self::OPTION, array_merge($this->data(), $changes), false);
    }

    /** Update offers depend on the license: ask WordPress to look again. */
    private function afterChange(): void
    {
        delete_transient(Updater::CACHE);
        delete_site_transient('update_plugins');
    }
}
