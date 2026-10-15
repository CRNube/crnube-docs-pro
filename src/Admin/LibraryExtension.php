<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Capabilities;
use CRNube\DocsPro\Access\Groups;
use CRNube\DocsPro\License\LicensePage;

/**
 * Adds the protected area and the reports to the library app of CRNube Docs: the
 * "Access" column, "Who can open it…" on each category, and links to the reports.
 */
final class LibraryExtension
{
    public function register(): void
    {
        add_filter('crnd_library_config', [$this, 'config']);
        add_action('crnd_library_enqueue', [$this, 'enqueue']);
        add_filter('crnd_submenu_order', [$this, 'submenuOrder']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function config(array $config): array
    {
        $canAccess = current_user_can(Capabilities::MANAGE_ACCESS);
        $config['reportsUrl']      = current_user_can(Capabilities::VIEW_REPORTS) ? ReportsPage::url() : '';
        $config['canManageAccess'] = $canAccess;
        $config['accessUrl']       = $canAccess ? admin_url('admin.php?page=' . AccessPage::SLUG) : '';
        $config['canManageAssistant'] = current_user_can(Capabilities::MANAGE_SETTINGS);
        $config['groups']          = $canAccess ? array_map(
            static fn (array $g): array => ['id' => $g['id'], 'name' => $g['name']],
            Groups::all()
        ) : [];
        return $config;
    }

    public function enqueue(): void
    {
        $asset = CRND_PRO_DIR . 'build/library-pro.asset.php';
        if (!is_readable($asset) || (!current_user_can(Capabilities::MANAGE_ACCESS) && !current_user_can(Capabilities::MANAGE_SETTINGS))) {
            return;
        }
        /** @var array{dependencies: list<string>, version: string} $meta */
        $meta = require $asset;
        wp_enqueue_script('crnd-library-pro', CRND_PRO_URL . 'build/library-pro.js', $meta['dependencies'], $meta['version'], true);
        wp_enqueue_style('crnd-library-pro', CRND_PRO_URL . 'build/library-pro.css', ['crnd-library'], $meta['version']);
        wp_set_script_translations('crnd-library-pro', 'crnube-docs-pro', CRND_PRO_DIR . 'languages');
    }

    /**
     * @param array<string, int> $weight
     * @return array<string, int>
     */
    public function submenuOrder(array $weight): array
    {
        return $weight + [
            ReportsPage::SLUG => 4,
            AccessPage::SLUG  => 5,
            LicensePage::SLUG => 10,
        ];
    }
}
