<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\DocsPro\Analytics\Labels;
use CRNube\DocsPro\Analytics\Stats;
use CRNube\Docs\Capabilities;
use CRNube\DocsPro\Rest\StatsController;

/**
 * CSV exports of the reports, through admin-post.php so the browser downloads a file:
 *
 * - "events": one row per view or download (date, time, document, device, ...);
 * - "summary": one row per document with its totals.
 *
 * The files use ";" and a UTF-8 BOM, which is what Excel in Spanish expects.
 */
final class ReportsExport
{
    public const ACTION = 'crnd_export_report';

    private const BATCH = 2000;

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!current_user_can(Capabilities::VIEW_REPORTS)) {
            wp_die(esc_html__('You do not have access to the reports.', 'crnube-docs-pro'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above
        $type  = sanitize_key(wp_unslash($_GET['type'] ?? 'events'));
        $stats = StatsController::statsFrom([
            'from'     => sanitize_text_field(wp_unslash($_GET['from'] ?? '')),
            'to'       => sanitize_text_field(wp_unslash($_GET['to'] ?? '')),
            'category' => absint($_GET['category'] ?? 0),
            'document' => absint($_GET['document'] ?? 0),
        ]);
        // phpcs:enable

        $name = sprintf('%s-%s-%s_%s.csv', sanitize_title(get_bloginfo('name')) ?: 'documentos', $type === 'summary' ? 'resumen' : 'detalle', $stats->from, $stats->to);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        if ($type === 'summary') {
            $this->summary($out, $stats);
        } else {
            $this->events($out, $stats);
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /** @param resource $out */
    private function events($out, Stats $stats): void
    {
        $this->row($out, [
            __('Date', 'crnube-docs-pro'), __('Time', 'crnube-docs-pro'), __('Document', 'crnube-docs-pro'), __('Document ID', 'crnube-docs-pro'),
            __('Action', 'crnube-docs-pro'), __('Device', 'crnube-docs-pro'), __('Browser', 'crnube-docs-pro'), __('System', 'crnube-docs-pro'),
            __('Country', 'crnube-docs-pro'), __('Came from', 'crnube-docs-pro'), __('User', 'crnube-docs-pro'), __('Anonymous visitor', 'crnube-docs-pro'),
        ]);
        for ($offset = 0; ; $offset += self::BATCH) {
            $events = $stats->events(self::BATCH, $offset);
            foreach ($events as $e) {
                [$date, $time] = explode(' ', (string) $e['date']) + ['', ''];
                $this->row($out, [
                    $date,
                    $time,
                    $e['title'],
                    $e['document_id'],
                    Labels::event((string) $e['event']),
                    Labels::get('device', (string) $e['device']),
                    Labels::get('browser', (string) $e['browser']),
                    Labels::get('os', (string) $e['os']),
                    Labels::get('country', (string) $e['country']),
                    $e['source'],
                    $e['user'],
                    $e['visitor'],
                ]);
            }
            if (count($events) < self::BATCH) {
                break;
            }
        }
    }

    /** @param resource $out */
    private function summary($out, Stats $stats): void
    {
        $this->row($out, [
            __('Document', 'crnube-docs-pro'), __('Document ID', 'crnube-docs-pro'), __('Downloads', 'crnube-docs-pro'),
            __('Views', 'crnube-docs-pro'), __('Total', 'crnube-docs-pro'), __('Visitors', 'crnube-docs-pro'), __('Last activity', 'crnube-docs-pro'),
        ]);
        for ($offset = 0; ; $offset += self::BATCH) {
            $rows = $stats->topDocuments(self::BATCH, $offset);
            foreach ($rows as $r) {
                $this->row($out, [$r['title'], $r['id'], $r['downloads'], $r['views'], $r['downloads'] + $r['views'], $r['visitors'], $r['last']]);
            }
            if (count($rows) < self::BATCH) {
                break;
            }
        }
    }

    /**
     * Writes one CSV row. Cells starting with = + - @ are prefixed with a quote so a
     * spreadsheet never runs them as formulas (CSV injection).
     *
     * @param resource          $out
     * @param list<string|int>  $cells
     */
    private function row($out, array $cells): void
    {
        $cells = array_map(static function ($cell): string {
            $cell = (string) $cell;
            return preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell;
        }, $cells);
        fputcsv($out, $cells, ';', '"', '\\');
    }
}
