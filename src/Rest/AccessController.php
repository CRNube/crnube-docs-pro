<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Rest;

use CRNube\Docs\Access\DocumentAccess;
use CRNube\Docs\Capabilities;
use CRNube\Docs\Domain\CategoryTaxonomy;
use CRNube\Docs\Rest\CategoriesController;
use CRNube\Docs\Rest\DocumentsController;
use CRNube\DocsPro\Access\AccessSettings;
use CRNube\DocsPro\Access\Groups;
use CRNube\DocsPro\Access\PrivateStorage;
use CRNube\DocsPro\Pro;

/**
 * crnd/v1/access: access groups and their members, for the "Access" admin screen; and
 * who may open each category and document, from the library. Everything needs the
 * access-management capability.
 */
final class AccessController
{
    private const IMPORT_MAX = 500;

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('crnd_rest_document_args', [$this, 'documentArgs']);
        add_filter('crnd_rest_document_update', [$this, 'updateDocument'], 10, 3);
    }

    /**
     * @param array<string, array<string, mixed>> $args
     * @return array<string, array<string, mixed>>
     */
    public function documentArgs(array $args): array
    {
        $args['access']        = ['type' => 'string', 'enum' => DocumentAccess::MODES];
        $args['access_groups'] = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'default' => []];
        return $args;
    }

    public function updateDocument(?\WP_Error $error, int $postId, \WP_REST_Request $request): ?\WP_Error
    {
        if ($error !== null || !$request->has_param('access')) {
            return $error;
        }
        if (!current_user_can(Capabilities::MANAGE_ACCESS)) {
            return new \WP_Error('crnd_cannot_manage_access', __('You cannot change who may open documents.', 'crnube-docs-pro'), ['status' => 403]);
        }
        // Runs before wp_update_post(): its save_post hook moves the file where the new access says.
        AccessSettings::saveForPost($postId, (string) $request['access'], (array) $request['access_groups']);
        return null;
    }

    /** Makes a category (and what is below it) public, private, or back to inheriting. */
    public function categoryAccess(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request['id'];
        if (!term_exists($id, CategoryTaxonomy::NAME)) {
            return new \WP_Error('crnd_not_found', __('The category does not exist.', 'crnube-docs-pro'), ['status' => 404]);
        }
        AccessSettings::saveForCategory($id, (string) $request['access'], (array) $request['groups']);
        return (new CategoriesController())->index();
    }

    public function routes(): void
    {
        $ns    = DocumentsController::NAMESPACE;
        $can   = static fn (): bool => current_user_can(Capabilities::MANAGE_ACCESS);
        $group = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $text  = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'];
        $date  = ['type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$', 'default' => ''];

        register_rest_route($ns, '/categories/(?P<id>\d+)/access', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'categoryAccess'],
            'permission_callback' => $can,
            'args'                => [
                'id'     => ['type' => 'integer', 'required' => true],
                'access' => ['type' => 'string', 'required' => true, 'enum' => DocumentAccess::TERM_MODES],
                'groups' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'default' => []],
            ],
        ]);
        register_rest_route($ns, '/access', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'overview'],
            'permission_callback' => $can,
        ]);
        register_rest_route($ns, '/access/storage-check', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => static fn (): array => ['protected' => PrivateStorage::selfTest(true)],
            'permission_callback' => $can,
        ]);
        register_rest_route($ns, '/access/groups', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createGroup'],
            'permission_callback' => $can,
            'args'                => ['name' => $text + ['required' => true, 'minLength' => 1], 'description' => $text + ['default' => '']],
        ]);
        register_rest_route($ns, '/access/groups/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'updateGroup'],
                'permission_callback' => $can,
                'args'                => $group + ['name' => $text + ['minLength' => 1], 'description' => $text],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteGroup'],
                'permission_callback' => $can,
                'args'                => $group,
            ],
        ]);
        register_rest_route($ns, '/access/groups/(?P<id>\d+)/members', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'members'],
                'permission_callback' => $can,
                'args'                => $group,
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'addMembers'],
                'permission_callback' => $can,
                'args'                => $group + [
                    'members' => [
                        'type'     => 'array',
                        'required' => true,
                        'minItems' => 1,
                        'maxItems' => self::IMPORT_MAX,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'email'   => ['type' => 'string'],
                                'name'    => ['type' => 'string'],
                                'expires' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'notify'  => ['type' => 'boolean', 'default' => false],
                ],
            ],
        ]);
        register_rest_route($ns, '/access/groups/(?P<id>\d+)/members/(?P<user>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'updateMember'],
                'permission_callback' => $can,
                'args'                => $group + ['user' => ['type' => 'integer', 'required' => true], 'expires' => $date],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'removeMember'],
                'permission_callback' => $can,
                'args'                => $group + ['user' => ['type' => 'integer', 'required' => true]],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $portal = Pro::instance()->portal->url();
        return [
            'groups'  => Groups::all(),
            'storage' => ['protected' => PrivateStorage::selfTest()],
            'portal'  => $portal,
        ];
    }

    public function createGroup(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = Groups::create(trim((string) $request['name']), (string) $request['description']);
        return new \WP_REST_Response(['id' => $id, 'groups' => Groups::all()], 201);
    }

    public function updateGroup(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request['id'];
        $current = $this->group($id);
        if ($current === null) {
            return $this->notFound();
        }
        Groups::update(
            $id,
            $request->has_param('name') ? trim((string) $request['name']) : $current['name'],
            $request->has_param('description') ? (string) $request['description'] : $current['description']
        );
        return new \WP_REST_Response(['groups' => Groups::all()]);
    }

    public function deleteGroup(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($this->group((int) $request['id']) === null) {
            return $this->notFound();
        }
        Groups::delete((int) $request['id']);
        return new \WP_REST_Response(['groups' => Groups::all()]);
    }

    public function members(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($this->group((int) $request['id']) === null) {
            return $this->notFound();
        }
        return new \WP_REST_Response(Groups::members((int) $request['id']));
    }

    /**
     * Adds people by email (one, or a whole CSV import). Unknown emails get a member
     * account; existing users keep their role and only gain the membership.
     */
    public function addMembers(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $groupId = (int) $request['id'];
        $group   = $this->group($groupId);
        if ($group === null) {
            return $this->notFound();
        }

        $added   = 0;
        $created = 0;
        $failed  = [];
        foreach ((array) $request['members'] as $row) {
            $email   = sanitize_email((string) ($row['email'] ?? ''));
            $name    = sanitize_text_field((string) ($row['name'] ?? ''));
            $expires = $this->date((string) ($row['expires'] ?? ''));
            if ($expires === null) {
                $failed[] = ['email' => $email, 'reason' => __('The expiry date must be YYYY-MM-DD.', 'crnube-docs-pro')];
                continue;
            }

            $existed = $email !== '' && get_user_by('email', $email) instanceof \WP_User;
            $userId  = Groups::userForEmail($email, $name);
            if (is_wp_error($userId)) {
                $failed[] = ['email' => $email, 'reason' => $userId->get_error_message()];
                continue;
            }
            Groups::addMember($groupId, $userId, $expires);
            $added++;
            if (!$existed) {
                $created++;
            }
            if ($request['notify']) {
                $this->invite($userId, $group['name']);
            }
        }

        return new \WP_REST_Response([
            'added'   => $added,
            'created' => $created,
            'failed'  => $failed,
            'members' => Groups::members($groupId),
            'groups'  => Groups::all(),
        ]);
    }

    public function updateMember(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $groupId = (int) $request['id'];
        if ($this->group($groupId) === null) {
            return $this->notFound();
        }
        $expires = $this->date((string) $request['expires']);
        if ($expires === null) {
            return new \WP_Error('crnd_bad_date', __('The expiry date must be YYYY-MM-DD.', 'crnube-docs-pro'), ['status' => 400]);
        }
        Groups::addMember($groupId, (int) $request['user'], $expires);
        return new \WP_REST_Response(['members' => Groups::members($groupId), 'groups' => Groups::all()]);
    }

    public function removeMember(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $groupId = (int) $request['id'];
        if ($this->group($groupId) === null) {
            return $this->notFound();
        }
        Groups::removeMember($groupId, (int) $request['user']);
        return new \WP_REST_Response(['members' => Groups::members($groupId), 'groups' => Groups::all()]);
    }

    /** Lets the person know they have access and where to sign in. */
    private function invite(int $userId, string $groupName): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return;
        }
        $site   = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $portal = Pro::instance()->portal->loginUrl();
        wp_mail(
            $user->user_email,
            /* translators: %s: site name */
            sprintf(__('You have access to the documents of %s', 'crnube-docs-pro'), $site),
            sprintf(
                /* translators: 1: person's name, 2: group name, 3: sign-in URL, 4: site name */
                __("Hello %1\$s,\n\nYou were given access to the documents of \"%2\$s\".\n\nTo enter, open %3\$s and type this email address. You will receive a code to sign in; no password is needed.\n\n%4\$s", 'crnube-docs-pro'),
                $user->display_name,
                $groupName,
                $portal,
                $site
            )
        );
    }

    /** '' (no expiry), a valid Y-m-d, or null if invalid. */
    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @return array{id: int, name: string, description: string, members: int, active: int}|null */
    private function group(int $id): ?array
    {
        foreach (Groups::all() as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }
        return null;
    }

    private function notFound(): \WP_Error
    {
        return new \WP_Error('crnd_not_found', __('The group does not exist.', 'crnube-docs-pro'), ['status' => 404]);
    }
}
