<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

use CRNube\Docs\Access\DocumentAccess;
use CRNube\Docs\Domain\CategoryTaxonomy;

/**
 * Saves who may open a document or a category. CRNube Docs reads these settings
 * (DocumentAccess::resolve()); only the protected area writes them, because only it
 * knows the groups.
 */
final class AccessSettings
{
    /**
     * @param array<mixed> $groups
     */
    public static function saveForPost(int $postId, string $mode, array $groups): void
    {
        $mode = in_array($mode, DocumentAccess::MODES, true) ? $mode : 'inherit';
        update_post_meta($postId, DocumentAccess::META_ACCESS, $mode);
        update_post_meta($postId, DocumentAccess::META_GROUPS, $mode === 'private' ? self::validGroups($groups) : []);
        do_action('crnd_access_changed', [$postId]);
    }

    /**
     * @param array<mixed> $groups
     */
    public static function saveForCategory(int $termId, string $mode, array $groups): void
    {
        $mode = in_array($mode, DocumentAccess::TERM_MODES, true) ? $mode : '';
        update_term_meta($termId, DocumentAccess::TERM_ACCESS, $mode);
        update_term_meta($termId, DocumentAccess::TERM_GROUPS, $mode === 'private' ? self::validGroups($groups) : []);

        // Every document in this category or below may have changed.
        $children = get_term_children($termId, CategoryTaxonomy::NAME);
        $terms    = [$termId, ...(is_array($children) ? array_map('intval', $children) : [])];
        $posts    = get_objects_in_term($terms, CategoryTaxonomy::NAME);
        do_action('crnd_access_changed', is_array($posts) ? array_map('intval', $posts) : []);
    }

    /**
     * @param array<mixed> $groups
     * @return list<int>
     */
    private static function validGroups(array $groups): array
    {
        $valid = array_keys(Groups::names());
        return array_values(array_intersect(array_unique(array_map('intval', $groups)), $valid));
    }
}
