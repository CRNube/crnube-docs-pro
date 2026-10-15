<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Access\DocumentAccess;
use CRNube\Docs\Capabilities;
use CRNube\Docs\Domain\DocumentPostType;
use CRNube\DocsPro\Access\AccessSettings;
use CRNube\DocsPro\Access\Groups;

/**
 * The "Who can open it" box on the document screen.
 */
final class AccessMetaBox
{
    private const NONCE = 'crnd_access_nonce';

    public function register(): void
    {
        add_action('add_meta_boxes_' . DocumentPostType::NAME, [$this, 'addBox']);
        // Before CRNube Docs' own save (priority 10), so the file is moved with the new access.
        add_action('save_post_' . DocumentPostType::NAME, [$this, 'save'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addBox(): void
    {
        if (current_user_can(Capabilities::MANAGE_ACCESS)) {
            add_meta_box('crnd-access', __('Who can open it', 'crnube-docs-pro'), [$this, 'render'], DocumentPostType::NAME, 'side', 'default');
        }
    }

    public function enqueue(string $hook): void
    {
        $screen = get_current_screen();
        if (in_array($hook, ['post.php', 'post-new.php'], true) && $screen !== null && $screen->post_type === DocumentPostType::NAME) {
            wp_enqueue_style('crnd-pro-admin', CRND_PRO_URL . 'assets/css/admin.css', [], \CRNube\DocsPro\Pro::assetVersion('assets/css/admin.css'));
        }
    }

    public function save(int $postId): void
    {
        if (!isset($_POST[self::NONCE], $_POST['crnd_access']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId) || !current_user_can(Capabilities::MANAGE_ACCESS)) {
            return;
        }
        AccessSettings::saveForPost(
            $postId,
            sanitize_key(wp_unslash($_POST['crnd_access'])),
            array_map('absint', (array) wp_unslash($_POST['crnd_access_groups'] ?? []))
        );
    }

    public function render(\WP_Post $post): void
    {
        $mode      = DocumentAccess::modeOf($post->ID);
        $selected  = DocumentAccess::groupsOf($post->ID);
        $groups    = Groups::names();
        $effective = DocumentAccess::forPost($post->ID);
        $options   = [
            'inherit' => ['category', __('Same as its category', 'crnube-docs-pro'), __('Recommended. Changes with the category.', 'crnube-docs-pro')],
            'public'  => ['unlock', __('Everyone (public)', 'crnube-docs-pro'), __('Anyone visiting the site.', 'crnube-docs-pro')],
            'private' => ['lock', __('Only members', 'crnube-docs-pro'), __('People in the groups you choose.', 'crnube-docs-pro')],
        ];
        $names = array_values(array_intersect_key($groups, array_flip($effective['groups'])));
        ?>
        <div class="crnd-accessbox">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <p class="crnd-accessbox__now <?php echo $effective['restricted'] ? 'is-private' : 'is-public'; ?>">
                <span class="dashicons dashicons-<?php echo $effective['restricted'] ? 'lock' : 'unlock'; ?>" aria-hidden="true"></span>
                <?php
                if (!$effective['restricted']) {
                    esc_html_e('Now: public.', 'crnube-docs-pro');
                } else {
                    echo esc_html($names === []
                        ? __('Now: only members (any group).', 'crnube-docs-pro')
                        /* translators: %s: group names */
                        : sprintf(__('Now: only %s.', 'crnube-docs-pro'), implode(', ', $names)));
                }
                ?>
            </p>
            <?php foreach ($options as $value => [$icon, $label, $hint]) : ?>
                <label class="crnd-accessbox__option">
                    <input type="radio" name="crnd_access" value="<?php echo esc_attr($value); ?>" <?php checked($mode, $value); ?>>
                    <span class="crnd-accessbox__card">
                        <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span>
                            <strong><?php echo esc_html($label); ?></strong>
                            <small><?php echo esc_html($hint); ?></small>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>

            <fieldset class="crnd-accessbox__groups" data-crnd-access-groups <?php echo $mode === 'private' ? '' : 'hidden'; ?>>
                <legend><?php esc_html_e('Of these groups (none checked: any member)', 'crnube-docs-pro'); ?></legend>
                <?php if ($groups === []) : ?>
                    <p class="description"><?php esc_html_e('There are no groups yet. Create them in Documents › Access.', 'crnube-docs-pro'); ?></p>
                <?php endif; ?>
                <?php foreach ($groups as $id => $name) : ?>
                    <label><input type="checkbox" name="crnd_access_groups[]" value="<?php echo (int) $id; ?>" <?php checked(in_array($id, $selected, true)); ?>> <?php echo esc_html($name); ?></label>
                <?php endforeach; ?>
            </fieldset>
        </div>
        <script>
        document.querySelectorAll('input[name="crnd_access"]').forEach(function (r) {
            r.addEventListener('change', function () {
                document.querySelector('[data-crnd-access-groups]').hidden = r.value !== 'private';
            });
        });
        </script>
        <?php
    }
}
