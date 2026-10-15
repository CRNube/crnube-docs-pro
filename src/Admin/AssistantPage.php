<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Admin\LibraryPage;
use CRNube\Docs\Capabilities;
use CRNube\Docs\Domain\DocumentPostType;
use CRNube\DocsPro\Assistant\Indexer;
use CRNube\DocsPro\Assistant\Providers\ProviderError;
use CRNube\DocsPro\Assistant\Settings;
use CRNube\DocsPro\Assistant\Usage;
use CRNube\DocsPro\Assistant\Widget;

/**
 * Documents › Assistant: provider and key, spending caps, limits, the state of the
 * prepared documents, and what visitors ask.
 */
final class AssistantPage
{
    public const SLUG = 'crnd-assistant';

    private const ACTION = 'crnd_ai_admin';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 11);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('crnd_submenu_order', static fn (array $weight): array => $weight + [self::SLUG => 6]);
    }

    public function menu(): void
    {
        add_submenu_page(
            LibraryPage::SLUG,
            __('Document assistant', 'crnube-docs-pro'),
            __('Assistant', 'crnube-docs-pro'),
            Capabilities::MANAGE_SETTINGS,
            self::SLUG,
            [$this, 'render']
        );
    }

    /** @param array<string, string> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => self::SLUG] + $args, admin_url('admin.php'));
    }

    public function enqueue(string $hook): void
    {
        if (str_ends_with($hook, '_page_' . self::SLUG)) {
            // The switches come from CRNube Docs' admin styles.
            wp_enqueue_style('crnd-admin', CRND_URL . 'assets/css/admin.css', [], \CRNube\Docs\Frontend\Assets::version('assets/css/admin.css'));
            wp_enqueue_style('crnd-pro-admin', CRND_PRO_URL . 'assets/css/admin.css', ['crnd-admin'], \CRNube\DocsPro\Pro::assetVersion('assets/css/admin.css'));
        }
    }

    public function handle(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You cannot change the assistant.', 'crnube-docs-pro'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));

        if ($do === 'save') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is validated in Settings::save()
            $input = isset($_POST['ai']) && is_array($_POST['ai']) ? wp_unslash($_POST['ai']) : [];
            $was   = Settings::isReady();
            Settings::save($input);
            if (!$was && Settings::isReady()) {
                Indexer::queueAll();
            }
            $this->back('saved');
        }
        if ($do === 'test') {
            $this->back($this->test());
        }
        if ($do === 'index') {
            Indexer::queueAll();
            $this->back('indexing');
        }
        if ($do === 'retry') {
            $postId = absint($_POST['post'] ?? 0);
            if (get_post_type($postId) === DocumentPostType::NAME) {
                delete_post_meta($postId, Indexer::META_HASH);
                delete_post_meta($postId, Indexer::META_ATTEMPTS);
                delete_post_meta($postId, Indexer::META_PARTIAL);
                update_post_meta($postId, Indexer::META_STATUS, 'pending');
                Indexer::queueAll();
            }
            $this->back('indexing');
        }
        $this->back('');
    }

    /** A tiny real call, to check the key and the model. */
    private function test(): string
    {
        if (Settings::apiKey() === '' || Settings::model() === '') {
            return 'test_missing';
        }
        try {
            $result = Settings::client()->complete('Reply with the single word OK.', [['role' => 'user', 'text' => 'Test']], 200, 30);
            Usage::log(Usage::INDEXED, $result->inputTokens, $result->outputTokens, Settings::cost($result->inputTokens, $result->outputTokens));
            set_transient('crnd_ai_test_' . get_current_user_id(), sprintf('%s (%d + %d tokens)', trim($result->text) ?: '—', $result->inputTokens, $result->outputTokens), 120);
            return 'test_ok';
        } catch (ProviderError $e) {
            set_transient('crnd_ai_test_' . get_current_user_id(), $e->getMessage(), 120);
            return 'test_failed';
        }
    }

    private function back(string $msg): never
    {
        wp_safe_redirect(self::url($msg !== '' ? ['crnd_msg' => $msg] : []));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }
        $s       = Settings::all();
        $summary = Usage::summary();
        $counts  = Indexer::counts();
        $ready   = Settings::isReady();
        $working = $counts['pending'] > 0 && (wp_next_scheduled(Indexer::HOOK_BATCH) || wp_next_scheduled(Indexer::HOOK_ONE));
        $day     = (int) round((float) $s['daily_budget'] * 1000000);
        $month   = (int) round((float) $s['monthly_budget'] * 1000000);
        $total   = $counts['indexed'] + $counts['pending'] + $counts['failed'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $msg = sanitize_key(wp_unslash($_GET['crnd_msg'] ?? ''));

        [$state, $stateLabel] = match (true) {
            $ready                      => ['on', __('On', 'crnube-docs-pro')],
            (bool) $s['enabled']        => ['warn', __('On, not ready', 'crnube-docs-pro')],
            default                     => ['off', __('Off', 'crnube-docs-pro')],
        };
        ?>
        <div class="wrap crnd-ai">
            <header class="crnd-ai__head">
                <span class="crnd-ai__head-icon dashicons dashicons-format-chat" aria-hidden="true"></span>
                <div>
                    <h1 class="crnd-ai__title">
                        <?php esc_html_e('Document assistant', 'crnube-docs-pro'); ?>
                        <span class="crnd-ai__pill crnd-ai__pill--<?php echo esc_attr($state); ?>"><?php echo esc_html($stateLabel); ?></span>
                    </h1>
                    <p class="crnd-ai__intro"><?php esc_html_e('Visitors ask questions and the assistant answers only from the public documents, citing the document and page. It appears where you add the shortcode or the Elementor widget, and only while it is on and configured.', 'crnube-docs-pro'); ?></p>
                </div>
            </header>
            <hr class="wp-header-end">
            <?php $this->notice($msg); ?>

            <div class="crnd-ai__stats">
                <?php
                $this->stat('money-alt', __('Spent today', 'crnube-docs-pro'), self::money($summary['today']), sprintf(/* translators: %s: amount */ __('of %s', 'crnube-docs-pro'), self::money($day)), self::ratio($summary['today'], $day), 'spend', 'today');
                $this->stat('calendar-alt', __('Spent this month', 'crnube-docs-pro'), self::money($summary['month']), sprintf(/* translators: %s: amount */ __('of %s', 'crnube-docs-pro'), self::money($month)), self::ratio($summary['month'], $month), 'spend', 'month');
                /* translators: %d: answers served from the cache */
                $this->stat('format-chat', __('Questions today', 'crnube-docs-pro'), (string) $summary['questions_today'], sprintf(__('%d from the cache, at no cost', 'crnube-docs-pro'), $summary['cached_today']));
                /* translators: %d: failed answers */
                $this->stat('shield', __('Stopped by limits', 'crnube-docs-pro'), (string) $summary['blocked_today'], $summary['errors_today'] > 0 ? sprintf(__('%d errors', 'crnube-docs-pro'), $summary['errors_today']) : __('today', 'crnube-docs-pro'));
                /* translators: 1: prepared documents, 2: pending, 3: failed */
                $this->stat('media-document', __('Documents ready', 'crnube-docs-pro'), $counts['indexed'] . ' / ' . $total, sprintf(__('%1$d pending · %2$d failed', 'crnube-docs-pro'), $counts['pending'], $counts['failed']), $total > 0 ? $counts['indexed'] / $total : null, 'ok', 'docs');
                ?>
            </div>

            <div class="crnd-ai__columns">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crnd-ai__form">
                    <?php $this->hidden('save'); ?>

                    <section class="crnd-ai__card">
                        <label class="crnd-toggle crnd-ai__main-toggle">
                            <input type="checkbox" name="ai[enabled]" value="1" <?php checked((bool) $s['enabled']); ?>>
                            <span class="crnd-toggle__switch" aria-hidden="true"></span>
                            <span>
                                <strong><?php esc_html_e('Assistant on', 'crnube-docs-pro'); ?></strong>
                                <small><?php esc_html_e('Turn it off to hide it everywhere at once.', 'crnube-docs-pro'); ?></small>
                            </span>
                        </label>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('admin-network', __('AI provider', 'crnube-docs-pro'), __('Where the questions are sent. Each provider keeps its own key.', 'crnube-docs-pro')); ?>
                        <div class="crnd-ai__providers" role="radiogroup" aria-label="<?php esc_attr_e('AI provider', 'crnube-docs-pro'); ?>">
                            <?php foreach (['anthropic' => ['Claude', 'Anthropic'], 'openai' => ['ChatGPT', 'OpenAI'], 'gemini' => ['Gemini', 'Google']] as $value => [$name, $company]) : ?>
                                <label class="crnd-ai__provider-option">
                                    <input type="radio" name="ai[provider]" value="<?php echo esc_attr($value); ?>" <?php checked(Settings::provider(), $value); ?>>
                                    <span class="crnd-ai__provider-box">
                                        <strong><?php echo esc_html($name); ?></strong>
                                        <span><?php echo esc_html($company); ?></span>
                                        <?php if (Settings::hasKey($value)) : ?>
                                            <span class="crnd-ai__provider-key"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Key saved', 'crnube-docs-pro'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach (Settings::PROVIDERS as $provider) : ?>
                            <fieldset class="crnd-ai__provider" data-provider="<?php echo esc_attr($provider); ?>">
                                <div class="crnd-ai__field crnd-ai__field--wide">
                                    <label for="crnd-ai-key-<?php echo esc_attr($provider); ?>"><?php esc_html_e('API key', 'crnube-docs-pro'); ?></label>
                                    <input type="password" id="crnd-ai-key-<?php echo esc_attr($provider); ?>" name="ai[keys][<?php echo esc_attr($provider); ?>]" class="code" autocomplete="off"
                                        placeholder="<?php echo esc_attr(Settings::hasKey($provider) ? __('Saved (type to replace, "-" to remove)', 'crnube-docs-pro') : __('Paste the key', 'crnube-docs-pro')); ?>">
                                </div>
                                <div class="crnd-ai__grid">
                                    <div class="crnd-ai__field crnd-ai__field--wide">
                                        <label for="crnd-ai-model-<?php echo esc_attr($provider); ?>"><?php esc_html_e('Model', 'crnube-docs-pro'); ?></label>
                                        <input type="text" id="crnd-ai-model-<?php echo esc_attr($provider); ?>" name="ai[models][<?php echo esc_attr($provider); ?>]" list="crnd-ai-models-<?php echo esc_attr($provider); ?>" value="<?php echo esc_attr((string) ($s['models'][$provider] ?? '')); ?>" class="code" data-crnd-model>
                                        <datalist id="crnd-ai-models-<?php echo esc_attr($provider); ?>">
                                            <?php foreach (array_keys(Settings::MODELS[$provider]) as $model) : ?>
                                                <option value="<?php echo esc_attr($model); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                    <?php
                                    $this->unitField('ai[prices][' . $provider . '][0]', 'crnd-ai-in-' . $provider, __('Input price', 'crnube-docs-pro'), (string) ($s['prices'][$provider][0] ?? 0), '$', __('/ million tokens', 'crnube-docs-pro'), '0.001', 'data-crnd-price-in');
                                    $this->unitField('ai[prices][' . $provider . '][1]', 'crnd-ai-out-' . $provider, __('Output price', 'crnube-docs-pro'), (string) ($s['prices'][$provider][1] ?? 0), '$', __('/ million tokens', 'crnube-docs-pro'), '0.001', 'data-crnd-price-out');
                                    ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>
                        <p class="crnd-ai__help">
                            <?php esc_html_e('Prices are what the spending caps use: check them on the provider\'s pricing page. The key is stored encrypted; you can also define CRND_AI_API_KEY in wp-config.php. Set a spending limit in the provider\'s own console too.', 'crnube-docs-pro'); ?>
                            <?php if (Settings::keyFromConfig()) : ?>
                                <strong><?php esc_html_e('The key is being read from wp-config.php.', 'crnube-docs-pro'); ?></strong>
                            <?php endif; ?>
                        </p>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('chart-bar', __('Spending caps and limits', 'crnube-docs-pro'), __('When a cap is reached the assistant says it is not available and calls nothing until the next day or month. Visitors are counted by an anonymous key that changes every day; the IP is not stored.', 'crnube-docs-pro')); ?>
                        <div class="crnd-ai__grid">
                            <?php
                            $this->unitField('ai[daily_budget]', 'crnd-ai-daily_budget', __('Daily cap', 'crnube-docs-pro'), (string) $s['daily_budget'], '$', 'USD', '0.01');
                            $this->unitField('ai[monthly_budget]', 'crnd-ai-monthly_budget', __('Monthly cap', 'crnube-docs-pro'), (string) $s['monthly_budget'], '$', 'USD', '0.01');
                            $this->unitField('ai[per_hour]', 'crnd-ai-per_hour', __('Per visitor, per hour', 'crnube-docs-pro'), (string) $s['per_hour'], '', __('questions', 'crnube-docs-pro'));
                            $this->unitField('ai[per_day]', 'crnd-ai-per_day', __('Per visitor, per day', 'crnube-docs-pro'), (string) $s['per_day'], '', __('questions', 'crnube-docs-pro'));
                            $this->unitField('ai[max_answer]', 'crnd-ai-max_answer', __('Longest answer', 'crnube-docs-pro'), (string) $s['max_answer'], '', 'tokens');
                            ?>
                        </div>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('lock', __('Protection against bots (Cloudflare Turnstile)', 'crnube-docs-pro'), __('Free at dash.cloudflare.com › Turnstile. Usually invisible to people; it stops scripts from asking thousands of questions. Strongly recommended for a public assistant.', 'crnube-docs-pro'), Settings::turnstileEnabled() ? __('Active', 'crnube-docs-pro') : __('Not set', 'crnube-docs-pro'), Settings::turnstileEnabled() ? 'on' : 'warn'); ?>
                        <div class="crnd-ai__grid crnd-ai__grid--two">
                            <div class="crnd-ai__field">
                                <label for="crnd-ai-ts-site"><?php esc_html_e('Site key', 'crnube-docs-pro'); ?></label>
                                <input type="text" id="crnd-ai-ts-site" name="ai[turnstile_site]" value="<?php echo esc_attr((string) $s['turnstile_site']); ?>" class="code">
                            </div>
                            <div class="crnd-ai__field">
                                <label for="crnd-ai-ts-secret"><?php esc_html_e('Secret key', 'crnube-docs-pro'); ?></label>
                                <input type="password" id="crnd-ai-ts-secret" name="ai[turnstile_secret]" class="code" autocomplete="off"
                                    placeholder="<?php echo esc_attr(Settings::turnstileSecret() !== '' ? __('Saved (type to replace, "-" to remove)', 'crnube-docs-pro') : ''); ?>">
                            </div>
                        </div>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('editor-paragraph', __('Texts', 'crnube-docs-pro'), __('What visitors see in the chat box. Empty fields use the default text.', 'crnube-docs-pro')); ?>
                        <div class="crnd-ai__field crnd-ai__field--wide">
                            <label for="crnd-ai-title"><?php esc_html_e('Title', 'crnube-docs-pro'); ?></label>
                            <input type="text" id="crnd-ai-title" name="ai[title]" value="<?php echo esc_attr((string) $s['title']); ?>" placeholder="<?php esc_attr_e('Ask about our documents', 'crnube-docs-pro'); ?>">
                        </div>
                        <div class="crnd-ai__field crnd-ai__field--wide">
                            <label for="crnd-ai-welcome"><?php esc_html_e('Welcome text', 'crnube-docs-pro'); ?></label>
                            <textarea id="crnd-ai-welcome" name="ai[welcome]" rows="2" placeholder="<?php esc_attr_e('Ask a question and I will look for the answer in the published documents, with the source of each fact.', 'crnube-docs-pro'); ?>"><?php echo esc_textarea((string) $s['welcome']); ?></textarea>
                        </div>
                        <div class="crnd-ai__field crnd-ai__field--wide">
                            <label for="crnd-ai-suggestions"><?php esc_html_e('Suggested questions (one per line, up to 4)', 'crnube-docs-pro'); ?></label>
                            <textarea id="crnd-ai-suggestions" name="ai[suggestions]" rows="4" placeholder="<?php esc_attr_e('What was the surplus in 2024?', 'crnube-docs-pro'); ?>"><?php echo esc_textarea((string) $s['suggestions']); ?></textarea>
                        </div>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('privacy', __('Privacy', 'crnube-docs-pro')); ?>
                        <label class="crnd-toggle">
                            <input type="checkbox" name="ai[save_questions]" value="1" <?php checked((bool) $s['save_questions']); ?>>
                            <span class="crnd-toggle__switch" aria-hidden="true"></span>
                            <span><?php esc_html_e('Keep the questions to see what visitors look for', 'crnube-docs-pro'); ?></span>
                        </label>
                        <div class="crnd-ai__grid">
                            <?php $this->unitField('ai[retention_days]', 'crnd-ai-retention_days', __('Keep the history for', 'crnube-docs-pro'), (string) $s['retention_days'], '', __('days', 'crnube-docs-pro')); ?>
                        </div>
                    </section>

                    <div class="crnd-ai__savebar">
                        <?php submit_button(__('Save changes', 'crnube-docs-pro'), 'primary large', 'submit', false); ?>
                        <span class="crnd-ai__savebar-hint"><?php esc_html_e('Changes apply when you save.', 'crnube-docs-pro'); ?></span>
                    </div>
                </form>

                <aside class="crnd-ai__side">
                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('media-document', __('Documents', 'crnube-docs-pro')); ?>
                        <div class="crnd-ai__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) $total; ?>" aria-valuenow="<?php echo (int) $counts['indexed']; ?>" data-crnd-docs-bar>
                            <span style="width: <?php echo esc_attr((string) ($total > 0 ? round($counts['indexed'] / $total * 100) : 0)); ?>%"></span>
                        </div>
                        <p class="crnd-ai__progress-label" aria-live="polite">
                            <span data-crnd-docs-label>
                                <?php
                                /* translators: 1: prepared documents, 2: all documents */
                                echo esc_html(sprintf(__('%1$d of %2$d ready', 'crnube-docs-pro'), $counts['indexed'], $total));
                                ?>
                            </span>
                            <span class="crnd-ai__working" data-crnd-working <?php echo $working && $ready ? '' : 'hidden'; ?>><span class="spinner is-active" aria-hidden="true"></span><?php esc_html_e('Preparing…', 'crnube-docs-pro'); ?></span>
                        </p>
                        <?php if ($counts['excluded'] > 0) : ?>
                            <p class="crnd-ai__excluded">
                                <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                                <?php
                                /* translators: %d: number of documents */
                                echo esc_html(sprintf(_n('%d document left out of the assistant.', '%d documents left out of the assistant.', $counts['excluded'], 'crnube-docs-pro'), $counts['excluded']));
                                ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . LibraryPage::SLUG)); ?>"><?php esc_html_e('Change in the Library', 'crnube-docs-pro'); ?></a>
                            </p>
                        <?php endif; ?>

                        <details class="crnd-ai__why" <?php echo $counts['indexed'] === 0 ? 'open' : ''; ?>>
                            <summary><?php esc_html_e('Why are documents prepared?', 'crnube-docs-pro'); ?></summary>
                            <p><?php esc_html_e('The assistant does not read the PDFs every time someone asks: that would be slow and expensive. Instead, it reads each document once and keeps its text, tables included. When a visitor asks, it only looks at the pages that matter, so answers are fast and each question costs very little.', 'crnube-docs-pro'); ?></p>
                            <p><?php esc_html_e('It happens on its own: when you upload or change a document it is prepared again in the background. Turning the assistant off and on does not repeat it. Only documents that are ready can be used in answers.', 'crnube-docs-pro'); ?></p>
                        </details>

                        <div class="crnd-ai__actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php $this->hidden('index'); ?>
                                <button type="submit" class="button button-primary" <?php disabled(!$ready || $counts['pending'] === 0); ?>><?php esc_html_e('Prepare pending documents', 'crnube-docs-pro'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php $this->hidden('test'); ?>
                                <button type="submit" class="button"><?php esc_html_e('Test the connection', 'crnube-docs-pro'); ?></button>
                            </form>
                        </div>
                        <?php if (!$ready) : ?>
                            <p class="crnd-ai__help"><strong><?php esc_html_e('Turn the assistant on and save (with a key, a model and both prices) to prepare documents.', 'crnube-docs-pro'); ?></strong></p>
                        <?php endif; ?>
                        <?php $this->failed(); ?>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('shortcode', __('Add it to a page', 'crnube-docs-pro')); ?>
                        <?php foreach (['[' . Widget::SHORTCODE . ']', '[' . Widget::SHORTCODE . ' category="estados-financieros"]'] as $code) : ?>
                            <div class="crnd-ai__code">
                                <code><?php echo esc_html($code); ?></code>
                                <button type="button" class="button-link" data-crnd-copy="<?php echo esc_attr($code); ?>"><?php esc_html_e('Copy', 'crnube-docs-pro'); ?></button>
                            </div>
                        <?php endforeach; ?>
                        <p class="crnd-ai__help"><?php esc_html_e('Or the "Document assistant" widget in Elementor. With category, it only answers from those documents.', 'crnube-docs-pro'); ?></p>
                    </section>

                    <section class="crnd-ai__card">
                        <?php $this->cardHeader('format-status', __('Most asked (30 days)', 'crnube-docs-pro')); ?>
                        <?php $top = Usage::topQuestions(); ?>
                        <?php if ($top === []) : ?>
                            <p class="crnd-ai__help"><?php esc_html_e('No questions yet.', 'crnube-docs-pro'); ?></p>
                        <?php else : ?>
                            <ol class="crnd-ai__top">
                                <?php foreach ($top as $row) : ?>
                                    <li><span><?php echo esc_html($row['question']); ?></span><span class="crnd-ai__count"><?php echo (int) $row['n']; ?></span></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </section>
                </aside>
            </div>
        </div>
        <script>
        ( function () {
            var models = <?php echo wp_json_encode(Settings::MODELS); ?>;
            var copied = <?php echo wp_json_encode(__('Copied', 'crnube-docs-pro')); ?>;
            function show() {
                var current = document.querySelector( 'input[name="ai[provider]"]:checked' );
                document.querySelectorAll( '.crnd-ai__provider' ).forEach( function ( box ) {
                    box.hidden = ! current || box.getAttribute( 'data-provider' ) !== current.value;
                } );
            }
            document.querySelectorAll( 'input[name="ai[provider]"]' ).forEach( function ( r ) { r.addEventListener( 'change', show ); } );
            show();
            document.querySelectorAll( '.crnd-ai__provider' ).forEach( function ( box ) {
                var list = models[ box.getAttribute( 'data-provider' ) ] || {};
                box.querySelector( '[data-crnd-model]' ).addEventListener( 'change', function () {
                    var price = list[ this.value ];
                    if ( price ) {
                        box.querySelector( '[data-crnd-price-in]' ).value = price[ 0 ];
                        box.querySelector( '[data-crnd-price-out]' ).value = price[ 1 ];
                    }
                } );
            } );
            document.querySelectorAll( '[data-crnd-copy]' ).forEach( function ( button ) {
                button.addEventListener( 'click', function () {
                    var label = button.textContent;
                    navigator.clipboard.writeText( button.getAttribute( 'data-crnd-copy' ) ).then( function () {
                        button.textContent = copied;
                        setTimeout( function () { button.textContent = label; }, 1500 );
                    } );
                } );
            } );
            // Warn before leaving with unsaved changes.
            var form = document.querySelector( '.crnd-ai__form' ), dirty = false;
            form.addEventListener( 'input', function () { dirty = true; } );
            form.addEventListener( 'submit', function () { dirty = false; } );
            window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );

            // While documents are being prepared, the numbers update on their own.
            var live = <?php echo wp_json_encode([
                'url'     => esc_url_raw(rest_url('crnd/v1/assistant/status')),
                'nonce'   => wp_create_nonce('wp_rest'),
                'working' => $working && $ready,
                'failed'  => $counts['failed'],
                /* translators: 1: prepared documents, 2: all documents */
                'ready'   => __('%1$d of %2$d ready', 'crnube-docs-pro'),
                /* translators: 1: pending documents, 2: failed documents */
                'pending' => __('%1$d pending · %2$d failed', 'crnube-docs-pro'),
            ]); ?>;
            function fill( template, a, b ) { return template.replace( '%1$d', a ).replace( '%2$d', b ); }
            function meter( stat, ratio ) {
                var bar = stat && stat.querySelector( '[data-crnd-meter]' );
                if ( ! bar ) { return; }
                bar.firstElementChild.style.width = Math.round( Math.min( 1, ratio ) * 100 ) + '%';
                if ( stat.getAttribute( 'data-crnd-stat' ) !== 'docs' ) {
                    bar.className = 'crnd-ai__meter crnd-ai__meter--' + ( ratio >= 0.9 ? 'high' : ratio >= 0.6 ? 'mid' : 'low' );
                }
            }
            function poll() {
                fetch( live.url, { headers: { 'X-WP-Nonce': live.nonce }, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.ok ? r.json() : null; } )
                    .then( function ( s ) {
                        if ( ! s ) { return; }
                        var docs = document.querySelector( '[data-crnd-stat="docs"]' );
                        docs.querySelector( '[data-crnd-value]' ).textContent = s.indexed + ' / ' + s.total;
                        docs.querySelector( '[data-crnd-hint]' ).textContent = fill( live.pending, s.pending, s.failed );
                        meter( docs, s.total ? s.indexed / s.total : 0 );
                        document.querySelector( '[data-crnd-docs-label]' ).textContent = fill( live.ready, s.indexed, s.total );
                        document.querySelector( '[data-crnd-docs-bar] span' ).style.width = ( s.total ? Math.round( s.indexed / s.total * 100 ) : 0 ) + '%';
                        [ 'today', 'month' ].forEach( function ( key ) {
                            var stat = document.querySelector( '[data-crnd-stat="' + key + '"]' );
                            stat.querySelector( '[data-crnd-value]' ).textContent = s[ key ].spent;
                            meter( stat, s[ key ].ratio );
                        } );
                        document.querySelector( '[data-crnd-working]' ).hidden = ! s.working;
                        // A document that failed lists its error: show it once it is all done.
                        if ( ! s.working && s.failed !== live.failed && ! dirty ) {
                            window.location.reload();
                            return;
                        }
                        if ( s.working ) {
                            setTimeout( poll, 8000 );
                        }
                    } )
                    .catch( function () { setTimeout( poll, 20000 ); } );
            }
            if ( live.working ) {
                setTimeout( poll, 8000 );
            }
        } )();
        </script>
        <?php
    }

    private function notice(string $msg): void
    {
        $test  = (string) get_transient('crnd_ai_test_' . get_current_user_id());
        $texts = [
            'saved'        => [true, __('Changes saved.', 'crnube-docs-pro')],
            'indexing'     => [true, __('The documents are being prepared in the background. Reload this page in a few minutes.', 'crnube-docs-pro')],
            /* translators: %s: the provider's reply */
            'test_ok'      => [true, sprintf(__('The provider answered: %s', 'crnube-docs-pro'), $test)],
            /* translators: %s: error message */
            'test_failed'  => [false, sprintf(__('The test failed: %s', 'crnube-docs-pro'), $test)],
            'test_missing' => [false, __('Save a key and a model first.', 'crnube-docs-pro')],
        ];
        if (!isset($texts[$msg])) {
            if (!Settings::isReady() && (bool) Settings::get('enabled')) {
                printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html__('The assistant is on but not ready: it needs a key, a model and both prices.', 'crnube-docs-pro'));
            }
            return;
        }
        delete_transient('crnd_ai_test_' . get_current_user_id());
        [$ok, $text] = $texts[$msg];
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html($text));
    }

    private function failed(): void
    {
        $ids = get_posts([
            'post_type'      => DocumentPostType::NAME,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_key'       => Indexer::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- at most 20 rows, admin screen
            'meta_value'     => 'failed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);
        if ($ids === []) {
            return;
        }
        echo '<h3 class="crnd-ai__subtitle">' . esc_html__('Could not be prepared', 'crnube-docs-pro') . '</h3><ul class="crnd-ai__failed">';
        foreach ($ids as $id) {
            echo '<li><strong>' . esc_html(get_the_title((int) $id)) . '</strong><span>' . esc_html((string) get_post_meta((int) $id, Indexer::META_ERROR, true)) . '</span>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden('retry');
            echo '<input type="hidden" name="post" value="' . (int) $id . '"><button type="submit" class="button-link">' . esc_html__('Try again', 'crnube-docs-pro') . '</button></form></li>';
        }
        echo '</ul>';
    }

    private function hidden(string $do): void
    {
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
    }

    private function cardHeader(string $icon, string $title, string $hint = '', string $badge = '', string $badgeState = ''): void
    {
        ?>
        <header class="crnd-ai__card-head">
            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <div>
                <h2>
                    <?php echo esc_html($title); ?>
                    <?php if ($badge !== '') : ?>
                        <span class="crnd-ai__pill crnd-ai__pill--<?php echo esc_attr($badgeState); ?>"><?php echo esc_html($badge); ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($hint !== '') : ?>
                    <p><?php echo esc_html($hint); ?></p>
                <?php endif; ?>
            </div>
        </header>
        <?php
    }

    /** A number field with its unit before ($) or after (USD, questions, days). */
    private function unitField(string $name, string $id, string $label, string $value, string $prefix, string $suffix, string $step = '1', string $data = ''): void
    {
        ?>
        <div class="crnd-ai__field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <span class="crnd-ai__unit">
                <?php if ($prefix !== '') : ?>
                    <span class="crnd-ai__unit-pre" aria-hidden="true"><?php echo esc_html($prefix); ?></span>
                <?php endif; ?>
                <input type="number" min="0" step="<?php echo esc_attr($step); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $data !== '' ? esc_attr($data) : ''; ?>>
                <span class="crnd-ai__unit-post"><?php echo esc_html($suffix); ?></span>
            </span>
        </div>
        <?php
    }

    /**
     * @param float|null $ratio Share of a limit used (0–1), drawn as a bar.
     */
    private function stat(string $icon, string $label, string $value, string $hint = '', ?float $ratio = null, string $tone = 'spend', string $key = ''): void
    {
        $level = $ratio === null ? '' : ($tone === 'ok' ? 'ok' : ($ratio >= 0.9 ? 'high' : ($ratio >= 0.6 ? 'mid' : 'low')));
        ?>
        <div class="crnd-ai__stat" <?php echo $key !== '' ? 'data-crnd-stat="' . esc_attr($key) . '"' : ''; ?>>
            <span class="crnd-ai__stat-label"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span><?php echo esc_html($label); ?></span>
            <strong data-crnd-value><?php echo esc_html($value); ?></strong>
            <?php if ($hint !== '') : ?>
                <span class="crnd-ai__stat-hint" data-crnd-hint><?php echo esc_html($hint); ?></span>
            <?php endif; ?>
            <?php if ($ratio !== null) : ?>
                <span class="crnd-ai__meter crnd-ai__meter--<?php echo esc_attr($level); ?>" aria-hidden="true" data-crnd-meter><span style="width: <?php echo esc_attr((string) round(min(1, $ratio) * 100)); ?>%"></span></span>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function ratio(int $used, int $limit): float
    {
        return $limit > 0 ? $used / $limit : 1.0;
    }

    /** Millionths of a dollar as "$0.42". */
    public static function money(int $micros): string
    {
        return '$' . number_format($micros / 1000000, $micros > 0 && $micros < 10000 ? 4 : 2);
    }
}
