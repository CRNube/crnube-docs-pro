<?php
declare(strict_types=1);

namespace CRNube\DocsPro;

use CRNube\DocsPro\Access\Groups;
use CRNube\DocsPro\Access\GroupsTable;
use CRNube\DocsPro\Access\PrivateStorage;
use CRNube\DocsPro\Analytics\EventsTable;
use CRNube\DocsPro\Analytics\Retention;
use CRNube\DocsPro\Assistant\Indexer;
use CRNube\DocsPro\Assistant\Usage;
use CRNube\DocsPro\License\License;

/**
 * Tables, member role and private folder of the add-on. The tables keep the names they
 * had inside CRNube Docs up to 0.5, so existing groups and history carry over.
 */
final class Installer
{
    private const VERSION_OPTION = 'crnd_pro_version';

    public static function activate(): void
    {
        self::install();
    }

    public static function deactivate(): void
    {
        Retention::unschedule();
        License::unschedule();
        wp_clear_scheduled_hook(Usage::PURGE_HOOK);
        wp_unschedule_hook(Indexer::HOOK_ONE);
        wp_unschedule_hook(Indexer::HOOK_BATCH);
    }

    /** The activation hook does not run on updates, so this runs when the version changes. */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::VERSION_OPTION) !== CRND_PRO_VERSION) {
            self::install();
        }
    }

    private static function install(): void
    {
        EventsTable::install();
        GroupsTable::install();
        Usage::install();
        PrivateStorage::prepareFolder();
        if (!get_role(Groups::MEMBER_ROLE)) {
            add_role(Groups::MEMBER_ROLE, __('Document member', 'crnube-docs-pro'), ['read' => true]);
        }
        update_option(self::VERSION_OPTION, CRND_PRO_VERSION, false);
    }
}
