<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

/**
 * Access groups and their members. All dates are stored in UTC.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 * -- custom tables; values always go through $wpdb->prepare(), only table names are interpolated.
 */
final class Groups
{
    /** Members of the protected area: they can sign in and open documents, nothing else. */
    public const MEMBER_ROLE = 'crnd_member';

    /** @var array<int, list<int>> user ID => active group IDs, for the current request */
    private static array $activeCache = [];

    /** @return list<array{id: int, name: string, description: string, members: int, active: int}> */
    public static function all(): array
    {
        global $wpdb;
        $g = GroupsTable::groups();
        $m = GroupsTable::members();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.id, g.name, g.description,
                    COUNT(m.user_id) AS members,
                    SUM(m.user_id IS NOT NULL AND (m.expires_at IS NULL OR m.expires_at > %s)) AS active
             FROM {$g} g LEFT JOIN {$m} m ON m.group_id = g.id
             GROUP BY g.id, g.name, g.description ORDER BY g.name",
            gmdate('Y-m-d H:i:s')
        ));
        return array_map(static fn (object $r): array => [
            'id'          => (int) $r->id,
            'name'        => (string) $r->name,
            'description' => (string) $r->description,
            'members'     => (int) $r->members,
            'active'      => (int) $r->active,
        ], (array) $rows);
    }

    /** @return array<int, string> group ID => name */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $group) {
            $names[$group['id']] = $group['name'];
        }
        return $names;
    }

    public static function exists(int $groupId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . GroupsTable::groups() . ' WHERE id = %d', $groupId));
    }

    public static function create(string $name, string $description = ''): int
    {
        global $wpdb;
        $wpdb->insert(GroupsTable::groups(), [
            'name'        => $name,
            'description' => $description,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ], ['%s', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    public static function update(int $groupId, string $name, string $description): void
    {
        global $wpdb;
        $wpdb->update(GroupsTable::groups(), ['name' => $name, 'description' => $description], ['id' => $groupId], ['%s', '%s'], ['%d']);
    }

    public static function delete(int $groupId): void
    {
        global $wpdb;
        $wpdb->delete(GroupsTable::members(), ['group_id' => $groupId], ['%d']);
        $wpdb->delete(GroupsTable::groups(), ['id' => $groupId], ['%d']);
        self::$activeCache = [];
        do_action('crnd_group_deleted', $groupId);
    }

    /**
     * @return list<array{user_id: int, name: string, email: string, expires_at: string, active: bool, added: string, last_login: string}>
     */
    public static function members(int $groupId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.user_id, m.expires_at, m.created_at, u.display_name, u.user_email
             FROM ' . GroupsTable::members() . " m JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE m.group_id = %d ORDER BY u.display_name",
            $groupId
        ));
        $now = gmdate('Y-m-d H:i:s');
        return array_map(static fn (object $r): array => [
            'user_id'    => (int) $r->user_id,
            'name'       => (string) $r->display_name,
            'email'      => (string) $r->user_email,
            'expires_at' => $r->expires_at ? self::toLocalDate((string) $r->expires_at) : '',
            'active'     => $r->expires_at === null || (string) $r->expires_at > $now,
            'added'      => self::toLocalDate((string) $r->created_at),
            'last_login' => (string) get_user_meta((int) $r->user_id, Otp::META_LAST_LOGIN, true),
        ], (array) $rows);
    }

    /**
     * Adds (or renews) a membership. $expires is a local date "Y-m-d" (access ends at the
     * end of that day) or '' for no expiry.
     */
    public static function addMember(int $groupId, int $userId, string $expires = ''): void
    {
        global $wpdb;
        $table = GroupsTable::members();
        $now   = gmdate('Y-m-d H:i:s');
        if ($expires === '') {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (group_id, user_id, expires_at, created_at) VALUES (%d, %d, NULL, %s)
                 ON DUPLICATE KEY UPDATE expires_at = NULL",
                $groupId,
                $userId,
                $now
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (group_id, user_id, expires_at, created_at) VALUES (%d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
                $groupId,
                $userId,
                self::endOfLocalDay($expires),
                $now
            ));
        }
        unset(self::$activeCache[$userId]);
    }

    public static function removeMember(int $groupId, int $userId): void
    {
        global $wpdb;
        $wpdb->delete(GroupsTable::members(), ['group_id' => $groupId, 'user_id' => $userId], ['%d', '%d']);
        unset(self::$activeCache[$userId]);
    }

    /** @return list<int> Groups the user belongs to today. */
    public static function activeGroupsOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (!isset(self::$activeCache[$userId])) {
            global $wpdb;
            self::$activeCache[$userId] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                'SELECT group_id FROM ' . GroupsTable::members() . ' WHERE user_id = %d AND (expires_at IS NULL OR expires_at > %s)',
                $userId,
                gmdate('Y-m-d H:i:s')
            )));
        }
        return self::$activeCache[$userId];
    }

    /**
     * Whether the user may open something restricted to $groupIds. An empty list means
     * "any member": someone with at least one active membership.
     *
     * @param list<int> $groupIds
     */
    public static function userIsIn(int $userId, array $groupIds): bool
    {
        $active = self::activeGroupsOf($userId);
        if ($active === []) {
            return false;
        }
        return $groupIds === [] || array_intersect($groupIds, $active) !== [];
    }

    /**
     * Finds the user with this email, or creates one with the "Document member" role.
     * The account has a random password nobody knows: members sign in with a code.
     */
    public static function userForEmail(string $email, string $name = ''): int|\WP_Error
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return new \WP_Error('crnd_bad_email', __('The email address is not valid.', 'crnube-docs-pro'));
        }
        $user = get_user_by('email', $email);
        if ($user instanceof \WP_User) {
            return $user->ID;
        }

        $login = sanitize_user(strstr($email, '@', true) ?: $email, true);
        $base  = $login !== '' ? $login : 'miembro';
        for ($i = 1; username_exists($login) || $login === ''; $i++) {
            $login = $base . $i;
        }

        $id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(32, true, true),
            'display_name' => $name !== '' ? $name : $email,
            'first_name'   => $name,
            'role'         => self::MEMBER_ROLE,
        ]);
        return is_wp_error($id) ? $id : (int) $id;
    }

    private static function endOfLocalDay(string $date): string
    {
        return (new \DateTimeImmutable($date . ' 23:59:59', wp_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function toLocalDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d');
    }
}
