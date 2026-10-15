<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Capabilities;
use CRNube\Docs\Domain\DocumentPostType;
use CRNube\DocsPro\Assistant\Scope;

/**
 * The "Assistant" box on the document screen: whether the document assistant may use
 * this document. Same look as "Who can open it".
 */
final class AssistantMetaBox
{
    private const NONCE = 'crnd_ai_scope_nonce';

    public function register(): void
    {
        add_action('add_meta_boxes_' . DocumentPostType::NAME, [$this, 'addBox']);
        add_action('save_post_' . DocumentPostType::NAME, [$this, 'save'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addBox(): void
    {
        if (current_user_can(Capabilities::MANAGE_SETTINGS)) {
            add_meta_box('crnd-ai-scope', __('Document assistant', 'crnube-docs-pro'), [$this, 'render'], DocumentPostType::NAME, 'side', 'low');
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
        if (!isset($_POST[self::NONCE], $_POST['crnd_ai_mode']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId) || !current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }
        $mode = sanitize_key(wp_unslash($_POST['crnd_ai_mode']));
        if ($mode !== Scope::modeOf($postId)) {
            Scope::saveForPost($postId, $mode);
        }
    }

    public function render(\WP_Post $post): void
    {
        $mode     = Scope::modeOf($post->ID);
        $included = Scope::includes($post->ID);
        $options  = [
            'inherit' => ['category', __('Same as its category', 'crnube-docs-pro'), __('Recommended. Changes with the category.', 'crnube-docs-pro')],
            'include' => ['format-chat', __('Use it', 'crnube-docs-pro'), __('The assistant may answer from it.', 'crnube-docs-pro')],
            'exclude' => ['hidden', __('Leave it out', 'crnube-docs-pro'), __('It stays on the site; only the assistant ignores it.', 'crnube-docs-pro')],
        ];
        ?>
        <div class="crnd-accessbox">
            <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
            <p class="crnd-accessbox__now <?php echo $included ? 'is-public' : 'is-off'; ?>">
                <span class="dashicons dashicons-<?php echo $included ? 'format-chat' : 'hidden'; ?>" aria-hidden="true"></span>
                <?php echo esc_html($included ? __('Now: the assistant uses it.', 'crnube-docs-pro') : __('Now: left out of the assistant.', 'crnube-docs-pro')); ?>
            </p>
            <?php foreach ($options as $value => [$icon, $label, $hint]) : ?>
                <label class="crnd-accessbox__option">
                    <input type="radio" name="crnd_ai_mode" value="<?php echo esc_attr($value); ?>" <?php checked($mode, $value); ?>>
                    <span class="crnd-accessbox__card">
                        <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <span>
                            <strong><?php echo esc_html($label); ?></strong>
                            <small><?php echo esc_html($hint); ?></small>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
