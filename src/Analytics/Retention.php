<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Analytics;

use CRNube\Docs\Settings\Settings;

/**
 * Deletes events older than the retention period set in the settings, once a day.
 * Keeping data only as long as needed is part of the data protection rules.
 */
final class Retention
{
    public const HOOK = 'crnd_purge_events';

    private const BATCH = 5000;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, function (): void {
            $this->purge();
        });
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function purge(): int
    {
        $months = (int) $this->settings->get('retention_months');
        if ($months <= 0) {
            return 0; // Keep forever.
        }

        global $wpdb;
        $table   = EventsTable::name();
        $cutoff  = gmdate('Y-m-d H:i:s', strtotime("-{$months} months"));
        $deleted = 0;

        // In batches, so a large first purge does not lock the table for long.
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
            $rows     = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::BATCH));
            $deleted += $rows;
        } while ($rows === self::BATCH);

        return $deleted;
    }
}
