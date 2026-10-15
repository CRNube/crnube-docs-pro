<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Analytics;

use CRNube\Docs\Domain\CategoryTaxonomy;

/**
 * Aggregates the events table for the reports screen and the CSV exports. Every query
 * is filtered by the same period (site's local dates), and optionally by one document
 * or one category including its subcategories.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
 * -- custom table; every value goes through $wpdb->prepare(), only the table name and
 *    the fixed column/expression fragments of this class are interpolated. The filter
 *    values are passed with ...$this->params, which the placeholder count sniff cannot follow.
 */
final class Stats
{
    /** Columns that can be broken down. */
    public const DIMENSIONS = ['device', 'browser', 'os', 'country'];

    private readonly string $table;
    private readonly int $offset;
    private readonly string $where;

    /** @var list<mixed> */
    private readonly array $params;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $category = 0,
        public readonly int $document = 0,
    ) {
        global $wpdb;
        $this->table = EventsTable::name();

        $timezone     = wp_timezone();
        $this->offset = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));

        // The period is in the site's time zone; the table stores UTC.
        $start = (new \DateTimeImmutable($from . ' 00:00:00', $timezone))->setTimezone(new \DateTimeZone('UTC'));
        $end   = (new \DateTimeImmutable($to . ' 23:59:59', $timezone))->setTimezone(new \DateTimeZone('UTC'));

        $where  = ['e.created_at BETWEEN %s AND %s'];
        $params = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        if ($document > 0) {
            $where[]  = 'e.document_id = %d';
            $params[] = $document;
        } elseif ($category > 0) {
            $ttIds = $this->categoryTermTaxonomyIds($category);
            $ttIds = $ttIds === [] ? [0] : $ttIds;
            $where[] = "e.document_id IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN ("
                . implode(',', array_fill(0, count($ttIds), '%d')) . '))';
            $params  = [...$params, ...$ttIds];
        }

        $this->where  = implode(' AND ', $where);
        $this->params = $params;
    }

    /**
     * The period of the same length just before this one, to compare against.
     */
    public function previous(): self
    {
        $from = new \DateTimeImmutable($this->from);
        $to   = new \DateTimeImmutable($this->to);
        $days = (int) $from->diff($to)->days + 1;
        return new self(
            $from->modify("-{$days} days")->format('Y-m-d'),
            $from->modify('-1 day')->format('Y-m-d'),
            $this->category,
            $this->document
        );
    }

    /** @return array{downloads: int, views: int, visitors: int, documents: int} */
    public function totals(): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(e.event = %d) AS downloads, SUM(e.event = %d) AS views,
                    COUNT(DISTINCT e.visitor, DATE(DATE_ADD(e.created_at, INTERVAL %d SECOND))) AS visitors,
                    COUNT(DISTINCT e.document_id) AS documents
             FROM {$this->table} e WHERE {$this->where}",
            EventsTable::EVENT_DOWNLOAD,
            EventsTable::EVENT_VIEW,
            $this->offset,
            ...$this->params
        ));
        return [
            'downloads' => (int) ($row->downloads ?? 0),
            'views'     => (int) ($row->views ?? 0),
            'visitors'  => (int) ($row->visitors ?? 0),
            'documents' => (int) ($row->documents ?? 0),
        ];
    }

    /**
     * Downloads and views per day (or per month for periods longer than ~3 months),
     * with every bucket present, also the empty ones.
     *
     * @return array{granularity: string, points: list<array{date: string, downloads: int, views: int}>}
     */
    public function series(): array
    {
        global $wpdb;
        $days        = (int) (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days + 1;
        $granularity = $days > 92 ? 'month' : 'day';
        $format      = $granularity === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(DATE_ADD(e.created_at, INTERVAL %d SECOND), %s) AS bucket,
                    SUM(e.event = %d) AS downloads, SUM(e.event = %d) AS views
             FROM {$this->table} e WHERE {$this->where} GROUP BY bucket",
            $this->offset,
            $format,
            EventsTable::EVENT_DOWNLOAD,
            EventsTable::EVENT_VIEW,
            ...$this->params
        ));
        $byBucket = [];
        foreach ((array) $rows as $row) {
            $byBucket[$row->bucket] = $row;
        }

        $points = [];
        $cursor = new \DateTimeImmutable($this->from);
        $end    = new \DateTimeImmutable($this->to);
        $step   = $granularity === 'month' ? '+1 month' : '+1 day';
        if ($granularity === 'month') {
            $cursor = $cursor->modify('first day of this month');
        }
        while ($cursor <= $end) {
            $key      = $cursor->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $points[] = [
                'date'      => $key,
                'downloads' => (int) ($byBucket[$key]->downloads ?? 0),
                'views'     => (int) ($byBucket[$key]->views ?? 0),
            ];
            $cursor = $cursor->modify($step);
        }
        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * Documents ordered by total activity.
     *
     * @return list<array{id: int, title: string, downloads: int, views: int, visitors: int, last: string}>
     */
    public function topDocuments(int $limit = 10, int $offset = 0): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.document_id AS id, p.post_title AS title,
                    SUM(e.event = %d) AS downloads, SUM(e.event = %d) AS views,
                    COUNT(DISTINCT e.visitor, DATE(DATE_ADD(e.created_at, INTERVAL %d SECOND))) AS visitors, MAX(e.created_at) AS last
             FROM {$this->table} e LEFT JOIN {$wpdb->posts} p ON p.ID = e.document_id
             WHERE {$this->where}
             GROUP BY e.document_id, p.post_title
             ORDER BY (SUM(e.event = %d) + SUM(e.event = %d)) DESC, downloads DESC
             LIMIT %d OFFSET %d",
            EventsTable::EVENT_DOWNLOAD,
            EventsTable::EVENT_VIEW,
            $this->offset,
            ...[...$this->params, EventsTable::EVENT_DOWNLOAD, EventsTable::EVENT_VIEW, $limit, $offset]
        ));
        return array_map(fn (object $r): array => [
            'id'        => (int) $r->id,
            'title'     => $r->title !== null ? (string) $r->title : __('(deleted document)', 'crnube-docs-pro'),
            'downloads' => (int) $r->downloads,
            'views'     => (int) $r->views,
            'visitors'  => (int) $r->visitors,
            'last'      => $this->localTime((string) $r->last),
        ], (array) $rows);
    }

    /**
     * Activity per category the documents are filed in (a document in two categories
     * counts in both).
     *
     * @return list<array{id: int, name: string, downloads: int, views: int}>
     */
    public function byCategory(int $limit = 10): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id AS id, t.name, SUM(e.event = %d) AS downloads, SUM(e.event = %d) AS views
             FROM {$this->table} e
             JOIN {$wpdb->term_relationships} tr ON tr.object_id = e.document_id
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE {$this->where}
             GROUP BY t.term_id, t.name
             ORDER BY (SUM(e.event = %d) + SUM(e.event = %d)) DESC
             LIMIT %d",
            EventsTable::EVENT_DOWNLOAD,
            EventsTable::EVENT_VIEW,
            CategoryTaxonomy::NAME,
            ...[...$this->params, EventsTable::EVENT_DOWNLOAD, EventsTable::EVENT_VIEW, $limit]
        ));
        return array_map(static fn (object $r): array => [
            'id'        => (int) $r->id,
            'name'      => (string) $r->name,
            'downloads' => (int) $r->downloads,
            'views'     => (int) $r->views,
        ], (array) $rows);
    }

    /**
     * Events per value of one dimension (device, browser, os or country).
     *
     * @return list<array{key: string, count: int}>
     */
    public function breakdown(string $dimension): array
    {
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.{$dimension} AS k, COUNT(*) AS n FROM {$this->table} e WHERE {$this->where} GROUP BY e.{$dimension} ORDER BY n DESC LIMIT 12",
            ...$this->params
        ));
        return array_map(static fn (object $r): array => ['key' => (string) $r->k, 'count' => (int) $r->n], (array) $rows);
    }

    /** @return list<int> Events per hour of the day (0–23, site time). */
    public function hours(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT HOUR(DATE_ADD(e.created_at, INTERVAL %d SECOND)) AS h, COUNT(*) AS n FROM {$this->table} e WHERE {$this->where} GROUP BY h",
            $this->offset,
            ...$this->params
        ));
        $hours = array_fill(0, 24, 0);
        foreach ((array) $rows as $row) {
            $hours[(int) $row->h] = (int) $row->n;
        }
        return $hours;
    }

    /**
     * Individual events, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function events(int $limit, int $offset = 0): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, p.post_title AS title, u.display_name AS user_name
             FROM {$this->table} e
             LEFT JOIN {$wpdb->posts} p ON p.ID = e.document_id
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id AND e.user_id > 0
             WHERE {$this->where} ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d",
            ...[...$this->params, $limit, $offset]
        ));
        return array_map(fn (object $r): array => [
            'id'          => (int) $r->id,
            'date'        => $this->localTime((string) $r->created_at),
            'document_id' => (int) $r->document_id,
            'title'       => $r->title !== null ? (string) $r->title : __('(deleted document)', 'crnube-docs-pro'),
            'event'       => (int) $r->event === EventsTable::EVENT_VIEW ? 'view' : 'download',
            'device'      => (string) $r->device,
            'browser'     => (string) $r->browser,
            'os'          => (string) $r->os,
            'country'     => (string) $r->country,
            'source'      => (string) $r->source,
            'user'        => (string) ($r->user_name ?? ''),
            'visitor'     => (string) $r->visitor,
        ], (array) $rows);
    }

    public function countEvents(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} e WHERE {$this->where}", ...$this->params));
    }

    /** UTC "Y-m-d H:i:s" from the table to the site's local "Y-m-d H:i:s". */
    private function localTime(string $utc): string
    {
        if ($utc === '') {
            return '';
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    /** @return list<int> */
    private function categoryTermTaxonomyIds(int $termId): array
    {
        $children = get_term_children($termId, CategoryTaxonomy::NAME);
        $ids      = [$termId, ...array_map('intval', is_array($children) ? $children : [])];
        $ttIds = [];
        foreach ($ids as $id) {
            $term = get_term($id, CategoryTaxonomy::NAME);
            if ($term instanceof \WP_Term) {
                $ttIds[] = $term->term_taxonomy_id;
            }
        }
        return $ttIds;
    }
}
