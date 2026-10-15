<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Analytics;

use CRNube\Docs\Analytics\UserAgent;
use CRNube\Docs\Domain\Document;
use CRNube\Docs\Settings\Settings;

/**
 * Keeps the history behind the reports: one row per view or download that CRNube Docs
 * counted (bots, prefetches and repeats are already left out by its counter).
 *
 * Privacy by design (Costa Rica's Ley 8968): the IP address is never stored. The visitor
 * key is an HMAC of IP + browser with a salt that changes every day, so a person can be
 * counted once per day but cannot be followed from one day to the next nor traced back.
 * Visitors that send "Global Privacy Control" or "Do Not Track" are still counted, but
 * nothing about their device or location is kept.
 */
final class Recorder
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action('crnd_hit', [$this, 'record'], 10, 5);
    }

    public function record(Document $document, string $mode, ?\WP_User $user, string $visitor, UserAgent $agent): void
    {
        $private = $this->settings->get('respect_gpc') && $this->sendsPrivacySignal();

        global $wpdb;
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            EventsTable::name(),
            [
                'document_id' => $document->id,
                'event'       => $mode === 'view' ? EventsTable::EVENT_VIEW : EventsTable::EVENT_DOWNLOAD,
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'visitor'     => $visitor,
                'user_id'     => $user !== null ? $user->ID : 0,
                'device'      => $private ? '' : $agent->device(),
                'browser'     => $private ? '' : $agent->browser(),
                'os'          => $private ? '' : $agent->os(),
                'country'     => $private ? '' : $this->country(),
                'source'      => $this->source(),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function sendsPrivacySignal(): bool
    {
        return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1' || ($_SERVER['HTTP_DNT'] ?? '') === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    /**
     * Two-letter country code, if the server or CDN provides one (Cloudflare and many
     * hosts do). No external lookup is made.
     */
    private function country(): string
    {
        $raw = '';
        foreach (['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY'] as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
                break;
            }
        }
        $country = strtoupper((string) apply_filters('crnd_visitor_country', $raw));
        return preg_match('/^[A-Z]{2}$/', $country) && !in_array($country, ['XX', 'T1'], true) ? $country : '';
    }

    /**
     * Where the click came from: the path of a page on this site (without query string),
     * or the host of another site. Never the full external URL.
     */
    private function source(): string
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($referer === '') {
            return '';
        }
        $host = (string) wp_parse_url($referer, PHP_URL_HOST);
        if ($host === (string) wp_parse_url(home_url(), PHP_URL_HOST)) {
            return substr((string) (wp_parse_url($referer, PHP_URL_PATH) ?: '/'), 0, 191);
        }
        return substr($host, 0, 191);
    }
}
