<?php

/**
 * AdminController
 *
 * Controller for admin dashboard template management.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Exception\DuplicateRoleAssignmentException;
use OCA\MyDash\Exception\InvalidRoleAssignmentException;
use OCA\MyDash\Exception\ResourceException;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\ExportService;
use OCA\MyDash\Service\FeedRefreshService;
use OCA\MyDash\Service\FooterService;
use OCA\MyDash\Service\ImportService;
use OCA\MyDash\Service\ResourceService;
use OCA\MyDash\Service\RoleService;
use OCA\MyDash\Service\SetupWizardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for admin dashboard template management plus role-assignment
 * CRUD (REQ-ROLE-004, REQ-ROLE-006) and feed-refresh admin actions.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The admin surface
 *                                                legitimately covers
 *                                                templates, settings,
 *                                                group-priority order,
 *                                                role assignments,
 *                                                export/import,
 *                                                feed-refresh, footer
 *                                                customization,
 *                                                template-preview-image
 *                                                upload (REQ-TMPL-017),
 *                                                setup wizard
 *                                                (REQ-WIZ-008..009),
 *                                                and the self-introspection
 *                                                endpoint in one
 *                                                cohesive admin namespace.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Admin coordination
 *                                                  spans multiple
 *                                                  injected services
 *                                                  (templates, settings,
 *                                                  roles, export, import,
 *                                                  feed refresh, footer,
 *                                                  resource uploads,
 *                                                  setup wizard,
 *                                                  helpers) by design —
 *                                                  splitting the controller
 *                                                  would fragment the routing
 *                                                  surface.
 * @SuppressWarnings(PHPMD.Superglobals)             $_FILES is the only
 *                                                  multipart entry point.
 * @SuppressWarnings(PHPMD.LongVariable)             `footerBackgroundColor`
 *                                                  / `footerTextColor` mirror
 *                                                  the API contract field
 *                                                  names verbatim — shortening
 *                                                  would diverge from the
 *                                                  documented payload.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is a function
 *                                                  of the number of guarded
 *                                                  admin endpoints, not
 *                                                  nested branching inside
 *                                                  any single method.
 */
class AdminController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest             $request            The request.
     * @param AdminTemplateService $templateService    The admin template service.
     * @param AdminSettingsService $settingsService    The admin settings service.
     * @param IGroupManager        $groupManager       The Nextcloud group manager.
     * @param IUserSession         $userSession        The current user session.
     * @param ExportService        $exportService      ZIP export service
     *                                                 (REQ-EXIM-001..003).
     * @param ImportService        $importService      ZIP import service
     *                                                 (REQ-EXIM-004..008).
     * @param RoleService          $roleService        The MyDash role service
     *                                                 (REQ-ROLE-001..011).
     * @param FeedRefreshService   $feedRefresh        The background feed
     *                                                 refresh service used by
     *                                                 the on-demand admin
     *                                                 `refreshFeeds` action
     *                                                 (REQ-BGJOB-FEED-005).
     * @param FooterService        $footerService      Global footer settings + sanitiser
     *                                                 (REQ-FTR-001..010).
     * @param SetupWizardService   $setupWizardService Setup-wizard
     *                                                 orchestrator
     *                                                 (REQ-WIZ-001..011).
     */
    public function __construct(
        IRequest $request,
        private readonly AdminTemplateService $templateService,
        private readonly AdminSettingsService $settingsService,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly ExportService $exportService,
        private readonly ImportService $importService,
        private readonly RoleService $roleService,
        private readonly FeedRefreshService $feedRefresh,
        private readonly FooterService $footerService,
        private readonly SetupWizardService $setupWizardService,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all admin dashboard templates.
     *
     * @return JSONResponse The list of templates.
     *
     * @spec admin-templates:REQ-TMPL-002
     */
    public function listTemplates(): JSONResponse
    {
        $templates = $this->templateService->listTemplates();

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $templates)
        );
    }//end listTemplates()

    /**
     * Get a specific admin template.
     *
     * @param int $id The template ID.
     *
     * @return JSONResponse The template data.
     */
    public function getTemplate(int $id): JSONResponse
    {
        try {
            $result     = $this->templateService->getTemplateWithPlacements(
                id: $id
            );
            $placements = ResponseHelper::serializeList(
                entities: $result['placements']
            );

            return ResponseHelper::success(
                data: [
                    'template'   => $result['template']->jsonSerialize(),
                    'placements' => $placements,
                ]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end getTemplate()

    /**
     * Create a new admin template.
     *
     * @param string      $name            The template name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string      $permissionLevel The permission level.
     * @param bool        $isDefault       Whether default.
     *
     * @return JSONResponse The created template.
     *
     * @spec admin-templates:REQ-TMPL-001
     */
    public function createTemplate(
        string $name,
        ?string $description=null,
        ?array $targetGroups=null,
        string $permissionLevel=Dashboard::PERMISSION_ADD_ONLY,
        bool $isDefault=false
    ): JSONResponse {
        try {
            $template = $this->templateService->createTemplate(
                name: $name,
                description: $description,
                targetGroups: $targetGroups,
                permissionLevel: $permissionLevel,
                isDefault: $isDefault
            );

            return ResponseHelper::success(
                data: $template->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end createTemplate()

    /**
     * Update an admin template.
     *
     * @param int         $id              The template ID.
     * @param string|null $name            The name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string|null $permissionLevel The permission level.
     * @param bool|null   $isDefault       Whether default.
     * @param int|null    $gridColumns     The grid columns.
     *
     * @return JSONResponse The updated template.
     *
     * @spec admin-templates:REQ-TMPL-003
     */
    public function updateTemplate(
        int $id,
        ?string $name=null,
        ?string $description=null,
        ?array $targetGroups=null,
        ?string $permissionLevel=null,
        ?bool $isDefault=null,
        ?int $gridColumns=null
    ): JSONResponse {
        try {
            $data = $this->buildUpdateData(
                name: $name,
                description: $description,
                targetGroups: $targetGroups,
                permissionLevel: $permissionLevel,
                isDefault: $isDefault,
                gridColumns: $gridColumns
            );

            $template = $this->templateService->updateTemplate(
                id: $id,
                data: $data
            );

            return ResponseHelper::success(
                data: $template->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateTemplate()

    /**
     * Delete an admin template.
     *
     * @param int $id The template ID.
     *
     * @return JSONResponse The deletion confirmation.
     *
     * @spec admin-templates:REQ-TMPL-004
     */
    public function deleteTemplate(int $id): JSONResponse
    {
        try {
            $this->templateService->deleteTemplate(id: $id);

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end deleteTemplate()

    /**
     * Get admin settings.
     *
     * @return JSONResponse The admin settings.
     *
     * @spec admin-settings:REQ-ASET-001
     */
    public function getSettings(): JSONResponse
    {
        return ResponseHelper::success(
            data: $this->settingsService->getSettings()
        );
    }//end getSettings()

    /**
     * Update admin settings.
     *
     * @param string|null $defaultPermLevel   Default permission level.
     * @param bool|null   $allowUserDash      Allow user dashboards.
     * @param bool|null   $allowMultiDash     Allow multiple dashboards.
     * @param int|null    $defaultGridCols    Default grid columns.
     * @param array|null  $linkCreateFileExts link-button-widget createFile
     *                                        extension allow-list
     *                                        (REQ-LBN-004).
     *
     * @return JSONResponse The update confirmation.
     *
     * @spec admin-settings:REQ-ASET-002
     */
    public function updateSettings(
        ?string $defaultPermLevel=null,
        ?bool $allowUserDash=null,
        ?bool $allowMultiDash=null,
        ?int $defaultGridCols=null,
        ?array $linkCreateFileExts=null
    ): JSONResponse {
        try {
            $this->settingsService->updateSettings(
                defaultPermLevel: $defaultPermLevel,
                allowUserDash: $allowUserDash,
                allowMultiDash: $allowMultiDash,
                defaultGridCols: $defaultGridCols,
                linkCreateFileExts: $linkCreateFileExts
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateSettings()

    /**
     * Read the global footer settings (REQ-FTR-001, REQ-FTR-010).
     *
     * Returns the five footer keys as a flat camelCase object so the
     * admin UI can render the form with one round-trip. Admin-only —
     * non-admins receive HTTP 403 because even the read path discloses
     * potentially-sensitive draft footer copy.
     *
     * @return JSONResponse The settings object, or 401/403 on guard failure.
     *
     * @NoAdminRequired
     */
    public function getFooterSettings(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return ResponseHelper::success(
            data: $this->footerService->getGlobalSettings()
        );
    }//end getFooterSettings()

    /**
     * Patch the global footer settings (REQ-FTR-001..003, REQ-FTR-009,
     * REQ-FTR-010).
     *
     * Body: any subset of `{footerEnabled, footerHtml, footerConfig,
     * footerBackgroundColor, footerTextColor}`. The service sanitises
     * HTML, validates the structured-config schema, and validates hex
     * colour strings before persistence. Validation failures map to
     * HTTP 400 (or 413 when the HTML exceeds the 8 KB cap).
     *
     * @param bool|null         $footerEnabled         Master toggle.
     * @param string|array|null $footerHtml            Raw HTML or
     *                                                 language-variant
     *                                                 map.
     * @param array|null        $footerConfig          Structured config.
     * @param string|null       $footerBackgroundColor Hex (#rrggbb) or null.
     * @param string|null       $footerTextColor       Hex (#rrggbb) or null.
     *
     * @return JSONResponse Status 200 on success, 400/413 on validation,
     *                      401/403 on guard failure.
     *
     * @NoAdminRequired
     */
    public function updateFooterSettings(
        ?bool $footerEnabled=null,
        mixed $footerHtml=null,
        ?array $footerConfig=null,
        ?string $footerBackgroundColor=null,
        ?string $footerTextColor=null
    ): JSONResponse {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        // Build the patch from only those args that the caller actually
        // supplied — `array_key_exists` semantics on the body let admins
        // explicitly clear a colour by sending `null`.
        $body  = $this->request->getParams();
        $patch = [];
        foreach (['footerEnabled', 'footerHtml', 'footerConfig', 'footerBackgroundColor', 'footerTextColor'] as $key) {
            if (array_key_exists(key: $key, array: $body) === true) {
                $patch[$key] = $body[$key];
            }
        }

        try {
            $this->footerService->updateGlobalSettings(patch: $patch);
        } catch (InvalidArgumentException $e) {
            $isOversize = str_contains(
                haystack: $e->getMessage(),
                needle: '8 KB limit'
            );
            if ($isOversize === true) {
                $status = Http::STATUS_REQUEST_ENTITY_TOO_LARGE;
            } else {
                $status = Http::STATUS_BAD_REQUEST;
            }

            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: $status
            );
        }

        return ResponseHelper::success(data: ['status' => 'ok']);
    }//end updateFooterSettings()

    /**
     * List all Nextcloud groups partitioned for the admin UI (REQ-ASET-013).
     *
     * Returns `{active, inactive, allKnown}`:
     * - `active`  — the persisted `group_order` list, in admin-chosen order.
     *               Stale IDs (no longer in Nextcloud) are preserved so the
     *               admin can see and remove them.
     * - `inactive` — every Nextcloud group ID NOT in `active`, sorted by
     *                display name (case-insensitive).
     * - `allKnown` — full descriptor list `{id, displayName}` for every group
     *                currently known to Nextcloud, so the UI can render
     *                display names without a second round-trip. Stale IDs are
     *                NOT included here (no display name available).
     *
     * Admin-only (REQ-ASET-014): non-admins receive HTTP 403.
     *
     * @return JSONResponse The grouped descriptor payload, or 403.
     *
     * @NoAdminRequired
     */
    public function listGroups(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $allKnown    = [];
        $allKnownIds = [];
        foreach ($this->groupManager->search(search: '') as $group) {
            $id         = $group->getGID();
            $allKnown[] = [
                'id'          => $id,
                'displayName' => $group->getDisplayName(),
            ];
            $allKnownIds[$id] = $group->getDisplayName();
        }

        // Stable sort `allKnown` by displayName (case-insensitive).
        usort(
            array: $allKnown,
            callback: static function (array $a, array $b): int {
                return strcasecmp(
                    string1: $a['displayName'],
                    string2: $b['displayName']
                );
            }
        );

        $active     = $this->settingsService->getGroupOrder();
        $activeKeys = array_flip($active);

        $inactive = [];
        foreach (array_keys($allKnownIds) as $id) {
            if (isset($activeKeys[$id]) === false) {
                $inactive[] = $id;
            }
        }

        // Sort `inactive` by displayName (case-insensitive) per REQ-ASET-013.
        usort(
            array: $inactive,
            callback: static function (string $a, string $b) use ($allKnownIds): int {
                return strcasecmp(
                    string1: $allKnownIds[$a] ?? $a,
                    string2: $allKnownIds[$b] ?? $b
                );
            }
        );

        return ResponseHelper::success(
            data: [
                'active'   => $active,
                'inactive' => $inactive,
                'allKnown' => $allKnown,
            ]
        );
    }//end listGroups()

    /**
     * Replace the persisted group priority order wholesale (REQ-ASET-014).
     *
     * Accepts a JSON body `{groups: string[]}`:
     * - 403 if the caller is not a Nextcloud admin (no side effects).
     * - 400 if `groups` is missing or contains a non-string element (no
     *   side effects on the persisted setting).
     * - Duplicates are deduplicated (first occurrence wins, order preserved).
     * - Unknown IDs are tolerated (per REQ-ASET-014 "Unknown IDs accepted").
     * - 200 with `{status: 'ok', groupOrder: string[]}` on success.
     *
     * @param mixed $groups The new ordered list (validated to `string[]`).
     *
     * @return JSONResponse The status response.
     *
     * @NoAdminRequired
     */
    public function updateGroupOrder(mixed $groups=null): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if (is_array($groups) === false) {
            return new JSONResponse(
                data: ['error' => 'Field "groups" must be an array of strings.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->settingsService->setGroupOrder(groupIds: $groups);
        } catch (InvalidArgumentException) {
            // ADR-005: do not leak raw exception messages.
            return new JSONResponse(
                data: ['error' => 'Invalid groups payload'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(
            data: [
                'status'     => 'ok',
                'groupOrder' => $this->settingsService->getGroupOrder(),
            ]
        );
    }//end updateGroupOrder()

    /**
     * Export a single dashboard or the entire site as a ZIP archive.
     *
     * Implements REQ-EXIM-002 (single-dashboard export) and REQ-EXIM-003
     * (site export). Admin-only — non-admins receive HTTP 403.
     *
     * Query parameters:
     *   - `scope` (string, required): `dashboard` or `site`.
     *   - `dashboardUuid` (string, required when scope=dashboard).
     *
     * @param string      $scope         The export scope.
     * @param string|null $dashboardUuid The dashboard UUID for scope=dashboard.
     *
     * @return StreamResponse|JSONResponse The streamed ZIP, or a JSON error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(
        string $scope='site',
        ?string $dashboardUuid=null
    ): StreamResponse|JSONResponse {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if (in_array(needle: $scope, haystack: ['site', 'dashboard'], strict: true) === false) {
            return new JSONResponse(
                data: ['error' => 'Unsupported scope: '.$scope],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId = (string) $this->userSession->getUser()?->getUID();

        if ($scope === 'site') {
            return $this->exportService->exportSite(currentUserId: $userId);
        }

        if ($dashboardUuid === null || $dashboardUuid === '') {
            return new JSONResponse(
                data: ['error' => 'dashboardUuid parameter is required when scope=dashboard'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if (preg_match(pattern: '/^[A-Za-z0-9\-]{8,}$/', subject: $dashboardUuid) !== 1) {
            return new JSONResponse(
                data: ['error' => 'Invalid dashboard UUID format'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            return $this->exportService->exportDashboard(
                dashboardUuid: $dashboardUuid,
                currentUserId: $userId
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end export()

    /**
     * Import a previously-exported ZIP archive.
     *
     * Implements REQ-EXIM-004..008. Admin-only.
     *
     * Multipart body: a `file` field containing the ZIP archive.
     * Query parameter: `preserveUuids` (default false).
     *
     * @param bool $preserveUuids When true, fail on UUID collision.
     *
     * @return JSONResponse The import summary, or an error response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(bool $preserveUuids=false): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        // Multipart uploads bind to $_FILES; PHP only populates this for
        // POST requests, which is what the route declares (REQ-EXIM-004).
        $upload = $_FILES['file'] ?? null;
        if (is_array($upload) === false
            || isset($upload['tmp_name']) === false
            || (string) $upload['tmp_name'] === ''
        ) {
            return new JSONResponse(
                data: ['error' => 'No file uploaded under field "file".'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $tmpName = (string) $upload['tmp_name'];

        $userId = (string) $this->userSession->getUser()?->getUID();

        try {
            $result = $this->importService->import(
                zipPath: $tmpName,
                preserveUuids: $preserveUuids,
                currentUserId: $userId
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($result['status'] === ImportService::ERR_UUID_COLLISION) {
            return new JSONResponse(
                data: [
                    'importedDashboardCount' => 0,
                    'skippedDashboardCount'  => 0,
                    'errors'                 => $result['errors'],
                ],
                statusCode: Http::STATUS_CONFLICT
            );
        }

        return ResponseHelper::success(
            data: [
                'importedDashboardCount' => $result['importedDashboardCount'],
                'skippedDashboardCount'  => $result['skippedDashboardCount'],
                'errors'                 => $result['errors'],
            ]
        );
    }//end import()

    /**
     * List every role assignment in the system (REQ-ROLE-006). NC-admin only.
     *
     * Returns a JSON array of role-assignment rows with their persisted
     * fields (id, userId, groupId, role, assignedBy, assignedAt). The
     * caller MUST be a Nextcloud admin; non-admins receive HTTP 403.
     *
     * @return JSONResponse The list of role assignments, or 401/403.
     *
     * @NoAdminRequired
     */
    public function listRoles(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $assignments = $this->roleService->listAssignments();

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $assignments)
        );
    }//end listRoles()

    /**
     * Create a new role assignment (REQ-ROLE-004). NC-admin only.
     *
     * Accepts a JSON body `{userId?: string, groupId?: string, role: string}`.
     * Exactly one of `userId` / `groupId` MUST be set. Returns the new
     * assignment with HTTP 201 on success. Returns 400 on structural
     * failure, 409 on duplicate, 401/403 on auth failure.
     *
     * @param string|null $userId  The target user ID (XOR with groupId).
     * @param string|null $groupId The target group ID (XOR with userId).
     * @param string|null $role    The role name (admin / editor / viewer).
     *
     * @return JSONResponse The created assignment, or an error envelope.
     *
     * @NoAdminRequired
     */
    public function createRole(
        ?string $userId=null,
        ?string $groupId=null,
        ?string $role=null
    ): JSONResponse {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $assignedBy = (string) $this->userSession->getUser()->getUID();

        try {
            $assignment = $this->roleService->assignRole(
                userId: $userId,
                groupId: $groupId,
                role: (string) $role,
                assignedBy: $assignedBy
            );
        } catch (DuplicateRoleAssignmentException $e) {
            return new JSONResponse(
                data: [
                    'error'     => $e->getDisplayMessage(),
                    'errorCode' => $e->getErrorCode(),
                ],
                statusCode: Http::STATUS_CONFLICT
            );
        } catch (InvalidRoleAssignmentException $e) {
            return new JSONResponse(
                data: [
                    'error'     => $e->getDisplayMessage(),
                    'errorCode' => $e->getErrorCode(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return ResponseHelper::success(
            data: $assignment->jsonSerialize(),
            statusCode: Http::STATUS_CREATED
        );
    }//end createRole()

    /**
     * Delete a role assignment by ID (REQ-ROLE-004). NC-admin only.
     *
     * Returns 204 on success, 404 when no row matches, 401/403 on auth.
     *
     * @param int $id The role assignment ID.
     *
     * @return JSONResponse Empty success or error envelope.
     *
     * @NoAdminRequired
     */
    public function deleteRole(int $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $this->roleService->removeRole(id: $id);
        } catch (DoesNotExistException) {
            return ResponseHelper::forbidden(
                message: 'Role assignment not found'
            )->setStatus(status: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NO_CONTENT
        );
    }//end deleteRole()

    /**
     * Return the calling user's effective MyDash role and source
     * (REQ-ROLE-006). Available to any authenticated user.
     *
     * Response shape: `{role: string|null, source: string|null}`.
     *
     * @return JSONResponse The role / source envelope, or 401.
     *
     * @NoAdminRequired
     */
    public function getMyRole(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::unauthorized();
        }

        $userId = (string) $user->getUID();

        return ResponseHelper::success(
            data: [
                'role'   => $this->roleService->getEffectiveRole(userId: $userId),
                'source' => $this->roleService->getRoleSource(userId: $userId),
            ]
        );
    }//end getMyRole()

    /**
     * Trigger an immediate background feed refresh (REQ-FRJ-010).
     *
     * Admin-only — guarded by {@see self::requireAdmin()}. Optionally
     * scope the refresh to a single feed URL (must be HTTP/HTTPS).
     * Returns `{processedCount, successCount, failureCount, durationMs}`.
     *
     * @param string|null $feedUrl Optional single URL to refresh.
     *
     * @return JSONResponse The aggregate refresh summary.
     *
     * @NoAdminRequired
     */
    public function refreshFeedsNow(?string $feedUrl=null): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if ($feedUrl !== null && $feedUrl !== '') {
            $scheme = strtolower(
                string: (string) parse_url(
                    url: $feedUrl,
                    component: PHP_URL_SCHEME
                )
            );
            if (in_array(needle: $scheme, haystack: ['http', 'https'], strict: true) === false) {
                return new JSONResponse(
                    data: [
                        'error' => 'feedUrl must use http:// or https:// scheme.',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }
        }

        $summary = $this->feedRefresh->refreshAll(onlyUrl: $feedUrl);

        return new JSONResponse(data: $summary, statusCode: Http::STATUS_OK);
    }//end refreshFeedsNow()

    /**
     * `POST /api/admin/templates/{uuid}/preview-image` — admin-only
     * preview-image upload (REQ-TMPL-017).
     *
     * Body (JSON): `{base64: 'data:image/<type>;base64,<bytes>'}`. The
     * payload is delegated to {@see ResourceService::upload()} (the
     * "custom-icon-upload pattern"); the returned URL is written to the
     * template's `templatePreviewImage` column. Allowed image types:
     * PNG, JPG, GIF, WebP, SVG (sanitised). Maximum decoded size: 5 MB.
     *
     * @param string $uuid   The template UUID.
     * @param string $base64 The base64 data URL.
     *
     * @return JSONResponse `{status: 'success', previewImage: '...'}`
     *                      on success.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function uploadTemplatePreviewImage(
        string $uuid,
        string $base64=''
    ): JSONResponse {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if ($base64 === '') {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_payload',
                    'message' => 'Field "base64" is required',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $url = $this->templateService->uploadPreviewImage(
                templateUuid: $uuid,
                base64DataUrl: $base64
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'not_found',
                    'message' => 'Template not found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (ResourceException $e) {
            // Catches every typed ResourceException subclass (bad data URL,
            // disallowed image format, oversized payload, SVG sanitiser
            // rejection, storage failure) returned by ResourceService::upload
            // — all collapse to a single 400 envelope per REQ-TMPL-017.
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_image',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try

        return new JSONResponse(
            data: [
                'status'       => 'success',
                'previewImage' => $url,
            ],
            statusCode: Http::STATUS_OK
        );
    }//end uploadTemplatePreviewImage()

    /**
     * Get the setup-wizard state (REQ-WIZ-008).
     *
     * Admin-only — non-admins receive HTTP 403. Returns
     * `{complete, currentRecommendedStep, stepStatuses}`.
     *
     * @return JSONResponse The wizard state, or 401/403.
     *
     * @NoAdminRequired
     */
    public function getWizardState(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return ResponseHelper::success(
            data: $this->setupWizardService->getWizardState()
        );
    }//end getWizardState()

    /**
     * Mark the setup-wizard complete (REQ-WIZ-009).
     *
     * Idempotent — calling on a completed instance returns 200 with the
     * same payload. Admin-only.
     *
     * @return JSONResponse The post-completion wizard state, or 401/403.
     *
     * @NoAdminRequired
     */
    public function completeWizard(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        return ResponseHelper::success(
            data: $this->setupWizardService->markWizardComplete()
        );
    }//end completeWizard()

    /**
     * Persist the storage backend choice from Step 2 (REQ-WIZ-003).
     *
     * Validates the selection and writes `mydash.content_storage`. The
     * GroupFolder option is server-side gated by the `groupfolders` app
     * dependency — selecting it without the app installed returns 400.
     * Admin-only.
     *
     * @param string|null $storage The chosen backend.
     *
     * @return JSONResponse The post-write wizard state, or 400/401/403.
     *
     * @NoAdminRequired
     */
    public function setWizardStorage(?string $storage=null): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if ($storage === null || $storage === '') {
            return new JSONResponse(
                data: ['error' => 'Field "storage" is required.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($storage === SetupWizardService::STORAGE_GROUPFOLDER
            && $this->setupWizardService->hasGroupfolderApp() === false
        ) {
            return new JSONResponse(
                data: ['error' => 'GroupFolder app is not installed.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->setupWizardService->setContentStorage(value: $storage);
        } catch (InvalidArgumentException) {
            return new JSONResponse(
                data: ['error' => 'Unsupported storage backend.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(
            data: $this->setupWizardService->getWizardState()
        );
    }//end setWizardStorage()

    /**
     * Verify the current session belongs to a Nextcloud admin.
     *
     * Returns `null` when the caller is an admin (proceed). Returns a 401
     * JSONResponse when no user is logged in, and a 403 JSONResponse when
     * the user is not an admin. REQ-ASET-014.
     *
     * @return JSONResponse|null The error response when the guard fails;
     *                           `null` when the caller is an admin.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return ResponseHelper::forbidden(
                message: 'Administrator privileges required.'
            );
        }

        return null;
    }//end requireAdmin()

    /**
     * Build the update data array from nullable parameters.
     *
     * @param string|null $name            The name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string|null $permissionLevel The permission level.
     * @param bool|null   $isDefault       Whether default.
     * @param int|null    $gridColumns     The grid columns.
     *
     * @return array The non-null update data.
     */
    private function buildUpdateData(
        ?string $name,
        ?string $description,
        ?array $targetGroups,
        ?string $permissionLevel,
        ?bool $isDefault,
        ?int $gridColumns
    ): array {
        $fields = [
            'name'            => $name,
            'description'     => $description,
            'targetGroups'    => $targetGroups,
            'permissionLevel' => $permissionLevel,
            'isDefault'       => $isDefault,
            'gridColumns'     => $gridColumns,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildUpdateData()
}//end class
