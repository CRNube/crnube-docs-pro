<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

/**
 * Who may ask, and how often. Visitors are anonymous, so the limits go by IP address,
 * kept only as an HMAC that changes every day (Ley 8968). The spending caps in Usage are
 * the real guarantee; these limits keep one visitor from using them up.
 */
final class Guard
{
    private const TURNSTILE_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function visitor(): string
    {
        /** This filter is documented in CRNube Docs' Analytics\Counter. */
        $ip = (string) apply_filters('crnd_visitor_ip', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')));
        return substr(hash_hmac('sha256', 'ai|' . $ip, wp_salt('auth') . gmdate('Y-m-d')), 0, 16);
    }

    /** Questions this visitor may still ask in this hour and today. */
    public static function left(string $visitor): int
    {
        $hour = (int) Settings::get('per_hour') - (int) get_transient(self::key($visitor, 'h'));
        $day  = (int) Settings::get('per_day') - (int) get_transient(self::key($visitor, 'd'));
        return max(0, min($hour, $day));
    }

    /** Counts a question that reached the provider or the cache. */
    public static function count(string $visitor): void
    {
        foreach (['h' => HOUR_IN_SECONDS, 'd' => DAY_IN_SECONDS] as $bucket => $ttl) {
            $key = self::key($visitor, $bucket);
            set_transient($key, (int) get_transient($key) + 1, $ttl);
        }
    }

    private static function key(string $visitor, string $bucket): string
    {
        return 'crnd_ai_' . $bucket . '_' . $visitor . '_' . ($bucket === 'h' ? gmdate('YmdH') : gmdate('Ymd'));
    }

    /** Cloudflare Turnstile: true when it is off, or the token is valid. */
    public static function human(string $token): bool
    {
        if (!Settings::turnstileEnabled()) {
            return true;
        }
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        $response = wp_remote_post(self::TURNSTILE_URL, [
            'timeout' => 10,
            'body'    => [
                'secret'   => Settings::turnstileSecret(),
                'response' => $token,
                'remoteip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            ],
        ]);
        if (is_wp_error($response)) {
            // Cloudflare unreachable: let the per-visitor limits and caps protect the bill.
            return true;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($body) && !empty($body['success']);
    }
}
