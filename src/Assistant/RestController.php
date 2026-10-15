<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\Docs\Capabilities;
use CRNube\Docs\Domain\CategoryTaxonomy;
use CRNube\Docs\Rest\CategoriesController;
use CRNube\Docs\Rest\DocumentsController;
use CRNube\DocsPro\Admin\AssistantPage;

/**
 * POST crnd/v1/assistant/ask: the public question endpoint of the widget. Open to
 * visitors by design; Guard (per-visitor limits, Turnstile) and Usage (spending caps)
 * are what protect it.
 */
final class RestController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        // Whether each document and category is used by the assistant, in the library.
        add_filter('crnd_rest_document', [$this, 'documentItem'], 10, 2);
        add_filter('crnd_rest_document_args', [$this, 'documentArgs']);
        add_filter('crnd_rest_document_update', [$this, 'updateDocument'], 10, 3);
        add_filter('crnd_rest_category', [$this, 'categoryItem'], 10, 2);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function documentItem(array $item, \WP_Post $post): array
    {
        $item['ai_mode']     = Scope::modeOf($post->ID);
        $item['ai_included'] = Scope::includes($post->ID);
        return $item;
    }

    /**
     * @param array<string, array<string, mixed>> $args
     * @return array<string, array<string, mixed>>
     */
    public function documentArgs(array $args): array
    {
        $args['ai_mode'] = ['type' => 'string', 'enum' => Scope::MODES];
        return $args;
    }

    public function updateDocument(?\WP_Error $error, int $postId, \WP_REST_Request $request): ?\WP_Error
    {
        if ($error !== null || !$request->has_param('ai_mode')) {
            return $error;
        }
        if (!current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return new \WP_Error('crnd_cannot_manage_assistant', __('You cannot change what the assistant uses.', 'crnube-docs-pro'), ['status' => 403]);
        }
        Scope::saveForPost($postId, (string) $request['ai_mode']);
        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function categoryItem(array $item, \WP_Term $term): array
    {
        $item['ai_mode']     = Scope::categoryMode($term->term_id);
        $item['ai_excluded'] = Scope::categorySetting($term->term_id)['mode'] === 'exclude';
        return $item;
    }

    /** Leaves a category (and what is below it) out of the assistant, or back in. */
    public function categoryScope(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request['id'];
        if (!term_exists($id, CategoryTaxonomy::NAME)) {
            return new \WP_Error('crnd_not_found', __('The category does not exist.', 'crnube-docs-pro'), ['status' => 404]);
        }
        Scope::saveForCategory($id, (string) $request['mode']);
        return (new CategoriesController())->index();
    }

    public function routes(): void
    {
        register_rest_route(DocumentsController::NAMESPACE, '/assistant/ask', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'ask'],
            'permission_callback' => '__return_true',
            'args'                => [
                'question'   => ['type' => 'string', 'required' => true, 'minLength' => 3, 'maxLength' => Answerer::MAX_QUESTION, 'sanitize_callback' => 'sanitize_text_field'],
                'history'    => [
                    'type'     => 'array',
                    'default'  => [],
                    'maxItems' => 6,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => ['q' => ['type' => 'string', 'maxLength' => 2000], 'a' => ['type' => 'string', 'maxLength' => 4000]],
                    ],
                ],
                'categories' => ['type' => 'string', 'default' => '', 'pattern' => '^[0-9,]*$', 'maxLength' => 200],
                'turnstile'  => ['type' => 'string', 'default' => '', 'maxLength' => 2048],
            ],
        ]);
        register_rest_route(DocumentsController::NAMESPACE, '/categories/(?P<id>\d+)/assistant', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'categoryScope'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_SETTINGS),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'mode' => ['type' => 'string', 'required' => true, 'enum' => Scope::TERM_MODES],
            ],
        ]);
        // The admin screen's live numbers while documents are being prepared.
        register_rest_route(DocumentsController::NAMESPACE, '/assistant/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_SETTINGS),
        ]);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $counts  = Indexer::counts();
        $summary = Usage::summary();
        $day     = (int) round((float) Settings::get('daily_budget') * 1000000);
        $month   = (int) round((float) Settings::get('monthly_budget') * 1000000);
        return [
            'indexed' => $counts['indexed'],
            'pending' => $counts['pending'],
            'failed'  => $counts['failed'],
            'total'   => $counts['indexed'] + $counts['pending'] + $counts['failed'],
            'working' => Settings::isReady() && $counts['pending'] > 0 && (wp_next_scheduled(Indexer::HOOK_BATCH) || wp_next_scheduled(Indexer::HOOK_ONE)),
            'today'   => ['spent' => AssistantPage::money($summary['today']), 'ratio' => AssistantPage::ratio($summary['today'], $day)],
            'month'   => ['spent' => AssistantPage::money($summary['month']), 'ratio' => AssistantPage::ratio($summary['month'], $month)],
        ];
    }

    public function ask(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Settings::isReady()) {
            return new \WP_REST_Response(['status' => 'off', 'answer' => __('The assistant is not available.', 'crnube-docs-pro'), 'sources' => [], 'left' => 0], 503);
        }
        if (!Guard::human((string) $request['turnstile'])) {
            return new \WP_REST_Response(['status' => 'challenge', 'answer' => __('Please confirm you are not a robot and ask again.', 'crnube-docs-pro'), 'sources' => [], 'left' => 0], 403);
        }

        $history = [];
        foreach ((array) $request['history'] as $turn) {
            if (is_array($turn)) {
                $history[] = ['q' => sanitize_text_field((string) ($turn['q'] ?? '')), 'a' => sanitize_textarea_field((string) ($turn['a'] ?? ''))];
            }
        }
        $categories = array_values(array_filter(array_map('absint', explode(',', (string) $request['categories']))));

        $reply = (new Answerer())->ask((string) $request['question'], $history, array_slice($categories, 0, 20), Guard::visitor());
        $code  = match ($reply['status']) {
            'limited' => 429,
            'budget'  => 503,
            'error'   => 502,
            default   => 200,
        };
        $response = new \WP_REST_Response($reply, $code);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
