<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

/**
 * Every call to the AI provider, with its cost: the source of the spending caps and of
 * the usage screen. Questions are kept (if the setting is on) for the retention period,
 * to show the most frequent ones; the visitor is only a daily-rotating hash.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 * -- own table; values always go through $wpdb->prepare(), only the table name is interpolated.
 */
final class Usage
{
    private const VERSION_OPTION = 'crnd_ai_db_version';
    private const VERSION        = '1';
    public const PURGE_HOOK      = 'crnd_ai_purge';

    /** Statuses of a logged question. */
    public const ANSWERED = 'answered';
    public const CACHED   = 'cached';
    public const LIMITED  = 'limited';
    public const BUDGET   = 'budget';
    public const ERROR    = 'error';
    public const INDEXED  = 'indexed';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'crnd_ai_usage';
    }

    public static function install(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            status varchar(16) NOT NULL,
            provider varchar(16) NOT NULL DEFAULT '',
            model varchar(100) NOT NULL DEFAULT '',
            input_tokens int(10) unsigned NOT NULL DEFAULT 0,
            output_tokens int(10) unsigned NOT NULL DEFAULT 0,
            cost bigint(20) unsigned NOT NULL DEFAULT 0,
            visitor char(16) NOT NULL DEFAULT '',
            question varchar(400) NOT NULL DEFAULT '',
            documents varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY status (status)
        ) {$wpdb->get_charset_collate()};");
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table()); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        delete_option(self::VERSION_OPTION);
    }

    /**
     * @param list<int> $documents
     */
    public static function log(string $status, int $inputTokens = 0, int $outputTokens = 0, int $cost = 0, string $visitor = '', string $question = '', array $documents = []): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'status'        => $status,
            'provider'      => $cost > 0 ? Settings::provider() : '',
            'model'         => $cost > 0 ? Settings::model() : '',
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost'          => $cost,
            'visitor'       => $visitor,
            'question'      => Settings::get('save_questions') ? mb_substr($question, 0, 400) : '',
            'documents'     => substr(implode(',', $documents), 0, 191),
        ]);
    }

    /** Spent today (site time zone), in millionths of a dollar. */
    public static function spentToday(): int
    {
        return self::spentSince(self::localMidnight('today'));
    }

    public static function spentThisMonth(): int
    {
        return self::spentSince(self::localMidnight('first day of this month'));
    }

    private static function spentSince(string $utc): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(cost), 0) FROM ' . self::table() . ' WHERE created_at >= %s', $utc));
    }

    /** UTC datetime of a local midnight ("today", "first day of this month"). */
    private static function localMidnight(string $when): string
    {
        $local = new \DateTimeImmutable($when . ' midnight', wp_timezone());
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * What is left of the tighter cap, in millionths of a dollar. A cap of 0 means the
     * assistant cannot spend at all.
     */
    public static function remaining(): int
    {
        $day   = (int) round((float) Settings::get('daily_budget') * 1000000) - self::spentToday();
        $month = (int) round((float) Settings::get('monthly_budget') * 1000000) - self::spentThisMonth();
        return max(0, min($day, $month));
    }

    /**
     * Numbers for the usage screen.
     *
     * @return array{today: int, month: int, questions_today: int, cached_today: int, blocked_today: int, errors_today: int}
     */
    public static function summary(): array
    {
        global $wpdb;
        $since = self::localMidnight('today');
        $rows  = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY status', $since), ARRAY_A);
        $count = [];
        foreach ((array) $rows as $row) {
            $count[(string) $row['status']] = (int) $row['n'];
        }
        return [
            'today'           => self::spentToday(),
            'month'           => self::spentThisMonth(),
            'questions_today' => ($count[self::ANSWERED] ?? 0) + ($count[self::CACHED] ?? 0),
            'cached_today'    => $count[self::CACHED] ?? 0,
            'blocked_today'   => ($count[self::LIMITED] ?? 0) + ($count[self::BUDGET] ?? 0),
            'errors_today'    => $count[self::ERROR] ?? 0,
        ];
    }

    /**
     * Most asked questions of the last 30 days.
     *
     * @return list<array{question: string, n: int}>
     */
    public static function topQuestions(int $limit = 15): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT question, COUNT(*) AS n FROM ' . self::table() . " WHERE question <> '' AND status IN (%s, %s) AND created_at >= %s GROUP BY question ORDER BY n DESC, MAX(created_at) DESC LIMIT %d",
            self::ANSWERED,
            self::CACHED,
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
            $limit
        ), ARRAY_A);
        return array_map(static fn (array $r): array => ['question' => (string) $r['question'], 'n' => (int) $r['n']], (array) $rows);
    }

    /**
     * The last questions, newest first.
     *
     * @return list<array<string, string>>
     */
    public static function recent(int $limit = 30): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT created_at, status, question, cost FROM ' . self::table() . ' WHERE status <> %s ORDER BY id DESC LIMIT %d',
            self::INDEXED,
            $limit
        ), ARRAY_A);
        return array_values((array) $rows);
    }

    public static function register(): void
    {
        add_action(self::PURGE_HOOK, [self::class, 'purge']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::PURGE_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
            }
        });
    }

    /** Removes rows past the retention period, never those of this month or the last one. */
    public static function purge(): void
    {
        global $wpdb;
        $days = max(7, (int) Settings::get('retention_days'));
        $limit = min(time() - $days * DAY_IN_SECONDS, (int) strtotime('first day of last month midnight'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', $limit)));
    }
}
