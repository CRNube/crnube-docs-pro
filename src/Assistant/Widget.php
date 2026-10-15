<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\Docs\Frontend\Assets;
use CRNube\Docs\Frontend\TemplateLoader;
use CRNube\Docs\Plugin;

/**
 * [crnube_docs_assistant category="estados-financieros" title="Pregunte sobre los estados financieros"]
 *
 * The chat box of the assistant. Shown only when the assistant is on and configured;
 * otherwise nothing (editors see why).
 */
final class Widget
{
    public const SHORTCODE = 'crnube_docs_assistant';
    public const HANDLE    = 'crnd-assistant';

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_action('elementor/frontend/after_register_styles', [self::class, 'registerAssets']);
    }

    public static function registerAssets(): void
    {
        wp_register_style(self::HANDLE, CRND_PRO_URL . 'assets/css/assistant.css', [Assets::HANDLE], \CRNube\DocsPro\Pro::assetVersion('assets/css/assistant.css'));
        wp_register_script(self::HANDLE, CRND_PRO_URL . 'assets/js/assistant.js', [], \CRNube\DocsPro\Pro::assetVersion('assets/js/assistant.js'), ['in_footer' => true, 'strategy' => 'defer']);
        if (Settings::turnstileEnabled()) {
            // Cloudflare's script, loaded only when Turnstile is configured.
            wp_register_script('crnd-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, ['in_footer' => true, 'strategy' => 'defer']); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current version
        }
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render(array|string $atts = []): string
    {
        $atts = shortcode_atts(['category' => '', 'title' => '', 'class' => ''], is_array($atts) ? $atts : [], self::SHORTCODE);

        if (!Settings::isReady()) {
            return current_user_can('manage_crnd_settings')
                ? '<p class="crnd-notice">' . esc_html__('CRNube Docs: the assistant is off or not configured (Documents › Assistant). Only editors see this message.', 'crnube-docs-pro') . '</p>'
                : '';
        }

        $categories = [];
        if ((string) $atts['category'] !== '') {
            $categories = Plugin::instance()->documents->resolveCategoryIds(explode(',', (string) $atts['category']));
            if ($categories === []) {
                return '';
            }
        }

        if (!wp_style_is(self::HANDLE, 'registered')) {
            self::registerAssets();
        }
        Assets::enqueue();
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
        if (Settings::turnstileEnabled()) {
            wp_enqueue_script('crnd-turnstile');
        }

        $config = [
            'endpoint'   => esc_url_raw(rest_url('crnd/v1/assistant/ask')),
            'categories' => implode(',', $categories),
            'turnstile'  => Settings::turnstileEnabled() ? (string) Settings::get('turnstile_site') : '',
            'maxLength'  => Answerer::MAX_QUESTION,
            'i18n'       => [
                'placeholder' => __('Ask about the documents…', 'crnube-docs-pro'),
                'send'        => __('Ask', 'crnube-docs-pro'),
                'thinking'    => __('Looking in the documents…', 'crnube-docs-pro'),
                'sources'     => __('Sources', 'crnube-docs-pro'),
                /* translators: %s: page number */
                'page'        => __('p. %s', 'crnube-docs-pro'),
                'error'       => __('The assistant could not answer right now. Please try again in a moment.', 'crnube-docs-pro'),
                'you'         => __('You', 'crnube-docs-pro'),
                'assistant'   => __('Assistant', 'crnube-docs-pro'),
                /* translators: %d: number of questions left */
                'left'        => __('Questions left for now: %d', 'crnube-docs-pro'),
                'clear'       => __('New conversation', 'crnube-docs-pro'),
            ],
        ];

        ob_start();
        TemplateLoader::include('assistant.php', [
            'id'          => 'crnd-assistant-' . wp_unique_id(),
            'title'       => (string) $atts['title'] !== '' ? (string) $atts['title'] : ((string) Settings::get('title') !== '' ? (string) Settings::get('title') : __('Ask about our documents', 'crnube-docs-pro')),
            'welcome'     => (string) Settings::get('welcome') !== '' ? (string) Settings::get('welcome') : __('Ask a question and I will look for the answer in the published documents, with the source of each fact.', 'crnube-docs-pro'),
            'suggestions' => Settings::suggestions(),
            'config'      => $config,
            'class'       => sanitize_html_class((string) $atts['class']),
        ], CRND_PRO_DIR . 'templates/');
        return (string) ob_get_clean();
    }
}
