<?php
/**
 * Runs when CRNube Docs Pro is deleted from the WordPress admin.
 *
 * Always removes the license and the add-on's own options. The view/download history and
 * the access groups are removed only if "Delete all documents and categories" is on in
 * the CRNube Docs settings: without it they wait, in case the add-on is installed again.
 * Member accounts stay (they are people's WordPress users) but lose the member role
 * only together with the groups. Private files stay in their folder for the admin to
 * review.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$crnd_settings = get_option('crnd_settings', []);

if (is_array($crnd_settings) && !empty($crnd_settings['delete_data'])) {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, names only
    foreach (['crnd_events', 'crnd_group_members', 'crnd_groups', 'crnd_ai_usage'] as $crnd_table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$crnd_table}");
    }
    // phpcs:enable
    delete_option('crnd_db_version');
    delete_option('crnd_ai_db_version');
    // The text the assistant prepared from each document.
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_crnd_ai_text', '_crnd_ai_status', '_crnd_ai_hash', '_crnd_ai_error', '_crnd_ai_attempts', '_crnd_ai_partial')"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    delete_option('crnd_access_db_version');
    delete_option('crnd_private_folder');
    remove_role('crnd_member');
}

wp_clear_scheduled_hook('crnd_purge_events');
wp_clear_scheduled_hook('crnd_pro_license_check');
wp_clear_scheduled_hook('crnd_ai_purge');
wp_unschedule_hook('crnd_ai_index');
wp_unschedule_hook('crnd_ai_index_batch');
// The assistant settings hold encrypted API keys: never left behind.
delete_option('crnd_ai_settings');
delete_option('crnd_ai_index_version');
delete_option('crnd_pro_license');
delete_option('crnd_pro_version');
delete_transient('crnd_pro_update');
