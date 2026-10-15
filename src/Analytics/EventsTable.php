<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Analytics;

/**
 * The table that stores one row per view or download. It holds no IP address and no
 * full user agent: only a daily-rotating anonymous visitor key and the parsed device,
 * browser, system and country.
 */
final class EventsTable
{
    public const EVENT_VIEW     = 1;
    public const EVENT_DOWNLOAD = 2;

    private const VERSION_OPTION = 'crnd_db_version';
    private const VERSION        = '1';

    public static function name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crnd_events';
    }

    public static function install(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        // dbDelta needs this exact layout: two spaces after PRIMARY KEY, one field per line.
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            event tinyint(3) unsigned NOT NULL,
            created_at datetime NOT NULL,
            visitor char(16) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            device varchar(10) NOT NULL DEFAULT '',
            browser varchar(20) NOT NULL DEFAULT '',
            os varchar(20) NOT NULL DEFAULT '',
            country char(2) NOT NULL DEFAULT '',
            source varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY document_time (document_id,created_at),
            KEY time_event (created_at,event)
        ) {$charset};");

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::name()); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only
        delete_option(self::VERSION_OPTION);
    }
}
