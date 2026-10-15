<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

/**
 * Tables of the protected area: the access groups, and who belongs to each one until
 * when. Members are ordinary WordPress users.
 */
final class GroupsTable
{
    private const VERSION_OPTION = 'crnd_access_db_version';
    private const VERSION        = '1';

    public static function groups(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crnd_groups';
    }

    public static function members(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crnd_group_members';
    }

    public static function install(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::groups() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            description text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};");

        // expires_at NULL: the membership does not expire.
        dbDelta('CREATE TABLE ' . self::members() . " (
            group_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (group_id,user_id),
            KEY user_id (user_id)
        ) {$charset};");

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function uninstall(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table names only
        $wpdb->query('DROP TABLE IF EXISTS ' . self::members());
        $wpdb->query('DROP TABLE IF EXISTS ' . self::groups());
        // phpcs:enable
        delete_option(self::VERSION_OPTION);
    }
}
