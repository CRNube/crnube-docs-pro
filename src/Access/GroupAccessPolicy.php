<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

use CRNube\Docs\Access\AccessPolicy;
use CRNube\Docs\Domain\Document;
use CRNube\Docs\Download\DownloadController;

/**
 * Public documents are open to everyone. Restricted ones only to members of the allowed
 * groups whose membership has not expired, and to the staff who manage documents.
 */
final class GroupAccessPolicy implements AccessPolicy
{
    public function canAccess(Document $document, ?\WP_User $user): bool
    {
        if (!$document->restricted) {
            return true;
        }
        if ($user === null || $user->ID === 0) {
            return false;
        }
        if (user_can($user, 'edit_crnd_documents')) {
            return true;
        }
        return Groups::userIsIn($user->ID, $document->groupIds);
    }

    public function deniedRedirect(Document $document): ?string
    {
        // Signed in but not allowed: a clear "no access" page, not a login loop.
        if (is_user_logged_in()) {
            return null;
        }
        $target = DownloadController::url($document->id, 'view');
        return (string) apply_filters('crnd_login_url', wp_login_url($target), $target);
    }
}
