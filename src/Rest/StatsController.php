<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Rest;

use CRNube\Docs\Capabilities;
use CRNube\Docs\Rest\DocumentsController;
use CRNube\DocsPro\Analytics\Stats;

/**
 * crnd/v1/stats: the numbers behind the reports screen.
 */
final class StatsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    /** @return array<string, array<string, mixed>> */
    public static function filterArgs(): array
    {
        $date = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'];
        return [
            'from'     => $date,
            'to'       => $date,
            'category' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            'document' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
        ];
    }

    public function routes(): void
    {
        $permission = static fn (): bool => current_user_can(Capabilities::VIEW_REPORTS);

        register_rest_route(DocumentsController::NAMESPACE, '/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'summary'],
            'permission_callback' => $permission,
            'args'                => self::filterArgs(),
        ]);

        register_rest_route(DocumentsController::NAMESPACE, '/stats/events', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'events'],
            'permission_callback' => $permission,
            'args'                => self::filterArgs() + [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(DocumentsController::NAMESPACE, '/stats/documents', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'documents'],
            'permission_callback' => $permission,
            'args'                => self::filterArgs() + [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
    }

    /**
     * Builds the query from the request: the last 30 days unless a period is given; a
     * reversed period is put in order.
     *
     * @param \WP_REST_Request|array<string, mixed> $request
     */
    public static function statsFrom(\WP_REST_Request|array $request): Stats
    {
        $today = wp_date('Y-m-d');
        $to    = !empty($request['to']) ? (string) $request['to'] : $today;
        $from  = !empty($request['from']) ? (string) $request['from'] : wp_date('Y-m-d', strtotime('-29 days'));
        if (strtotime($from) === false || strtotime($to) === false) {
            [$from, $to] = [wp_date('Y-m-d', strtotime('-29 days')), $today];
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return new Stats($from, $to, (int) ($request['category'] ?? 0), (int) ($request['document'] ?? 0));
    }

    public function summary(\WP_REST_Request $request): \WP_REST_Response
    {
        $stats = self::statsFrom($request);

        $breakdowns = [];
        foreach (Stats::DIMENSIONS as $dimension) {
            $breakdowns[$dimension] = $stats->breakdown($dimension);
        }

        return new \WP_REST_Response([
            'from'       => $stats->from,
            'to'         => $stats->to,
            'totals'     => $stats->totals(),
            'previous'   => $stats->previous()->totals(),
            'series'     => $stats->series(),
            'top'        => $stats->topDocuments(10),
            'categories' => $stats->byCategory(8),
            'breakdowns' => $breakdowns,
            'hours'      => $stats->hours(),
        ]);
    }

    public function events(\WP_REST_Request $request): \WP_REST_Response
    {
        $stats   = self::statsFrom($request);
        $perPage = (int) $request['per_page'];
        $total   = $stats->countEvents();

        $response = new \WP_REST_Response($stats->events($perPage, ((int) $request['page'] - 1) * $perPage));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $perPage)));
        return $response;
    }

    public function documents(\WP_REST_Request $request): \WP_REST_Response
    {
        $stats   = self::statsFrom($request);
        $perPage = (int) $request['per_page'];
        return new \WP_REST_Response($stats->topDocuments($perPage, ((int) $request['page'] - 1) * $perPage));
    }
}
