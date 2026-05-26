<?php

/**
 * DashboardApiController
 *
 * Controller for dashboard API endpoints — personal scope, group-shared
 * scope (REQ-DASH-014), and the visible-to-user resolution endpoint
 * (REQ-DASH-013).
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
use OCA\MyDash\Exception\DashboardHasChildrenException;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\AnalyticsService;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\DashboardTreeService;
use OCA\MyDash\Service\DashboardVersionService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for dashboard API endpoints.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The dashboard API
 *                                                  legitimately spans
 *                                                  multiple persistence
 *                                                  and service layers
 *                                                  (dashboard, share,
 *                                                  permission, factory,
 *                                                  versions) plus
 *                                                  personal +
 *                                                  group-shared +
 *                                                  resolution scopes —
 *                                                  splitting would
 *                                                  fragment the routing
 *                                                  surface.
 */
class DashboardApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest                $request           The request.
     * @param DashboardService        $dashboardService  The dashboard service.
     * @param PermissionService       $permissionService The permission service.
     * @param DashboardTreeService    $treeService       The tree service that
     *                                                   owns hierarchy
     *                                                   queries, cycle
     *                                                   detection, slug
     *                                                   uniqueness, path
     *                                                   resolution, and the
     *                                                   cascade-delete walker
     *                                                   (REQ-DASH-023..030).
     * @param DashboardVersionService $versionService    Snapshot service
     *                                                   (REQ-VERS-001) —
     *                                                   automatic
     *                                                   snapshots fire
     *                                                   after every
     *                                                   successful PUT
     *                                                   via the
     *                                                   debounced
     *                                                   `captureSnapshot`
     *                                                   helper.
     * @param AnalyticsService        $analyticsService  The view-analytics
     *                                                   service used by the
     *                                                   `viewEvent` endpoint
     *                                                   (REQ-ANLT-002).
     * @param LoggerInterface         $logger            PSR logger (used by
     *                                                   fork to report
     *                                                   unexpected errors
     *                                                   — REQ-DASH-021).
     * @param IUserSession            $userSession       The user session, used
     *                                                   to resolve the
     *                                                   authenticated IUser for
     *                                                   ADR-023 action checks.
     * @param ActionAuthService       $actionAuth        The ADR-023 action
     *                                                   authorization service.
     * @param string|null             $userId            The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardService $dashboardService,
        private readonly PermissionService $permissionService,
        private readonly DashboardTreeService $treeService,
        private readonly DashboardVersionService $versionService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all personal dashboards for the current user.
     *
     * Backward compatible — this endpoint never returns group-shared
     * dashboards (REQ-DASH-014). Use {@see self::visible()} for the
     * unioned listing.
     *
     * @return JSONResponse The list of dashboards.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-17
     */
    #[NoAdminRequired]
    public function list(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $dashboards = $this->dashboardService->getUserDashboards(
            userId: $this->userId
        );

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $dashboards)
        );
    }//end list()

    /**
     * List the deduplicated union of dashboards visible to the user.
     *
     * Returns personal + group-matching + default-group dashboards, each
     * tagged with `source` (`'user'`, `'group'`, `'default'`).
     * REQ-DASH-013.
     *
     * @return JSONResponse The visible dashboards.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function visible(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $items = $this->dashboardService->getVisibleToUser(
            userId: $this->userId
        );

        $serialized = [];
        foreach ($items as $entry) {
            $row           = $entry['dashboard']->jsonSerialize();
            $row['source'] = $entry['source'];
            $serialized[]  = $row;
        }

        return ResponseHelper::success(data: $serialized);
    }//end visible()

    /**
     * Get the user's active dashboard with placements.
     *
     * @return JSONResponse The active dashboard data.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-18
     */
    #[NoAdminRequired]
    public function getActive(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $result = $this->dashboardService->getEffectiveDashboard(
            userId: $this->userId
        );

        if ($result === null) {
            return ResponseHelper::success(
                data: ['error' => 'No dashboard available'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return ResponseHelper::success(
            data: [
                'dashboard'       => $result['dashboard']->jsonSerialize(),
                'placements'      => ResponseHelper::serializeList(
                    entities: $result['placements']
                ),
                'permissionLevel' => $result['permissionLevel'],
            ]
        );
    }//end getActive()

    /**
     * Get a single dashboard by id with its placements + permission level.
     *
     * Powers the front-end's `switchDashboard` flow: clicking a row in the
     * sidebar issues `GET /api/dashboard/{id}` and the response is the
     * same envelope shape as {@see self::getActive()}, so the store can
     * write `activeDashboard`, `widgetPlacements`, and `permissionLevel`
     * with no per-source branching.
     *
     * Returns 404 (not 403) when the dashboard exists but is not visible
     * to the caller — this matches the `getVisibleToUser` policy and
     * intentionally does not leak existence (REQ-DASH-020 scenario
     * "Cannot see what you cannot read").
     *
     * @param int $id The dashboard ID.
     *
     * @return JSONResponse The dashboard envelope (200) or
     *                      `{'error': 'Not found'}` (404).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-21
     */
    #[NoAdminRequired]
    public function show(int $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $result = $this->dashboardService->getDashboardForUser(
            dashboardId: $id,
            userId: $this->userId
        );

        if ($result === null) {
            return ResponseHelper::success(
                data: ['error' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $dashboard = $result['dashboard'];
        $isOwner   = ($dashboard->getUserId() === $this->userId);
        $sharedBy  = null;
        if ($isOwner === false) {
            $sharedBy = $dashboard->getUserId();
        }

        return ResponseHelper::success(
            data: [
                'dashboard'       => $dashboard->jsonSerialize(),
                'placements'      => ResponseHelper::serializeList(
                    entities: $result['placements']
                ),
                'permissionLevel' => $result['permissionLevel'],
                'isOwner'         => $isOwner,
                'sharedBy'        => $sharedBy,
            ]
        );
    }//end show()

    /**
     * Create a new dashboard.
     *
     * @param mixed       $name        The dashboard name.
     * @param string|null $description The description.
     * @param string|null $icon        The icon registry key (or NULL/empty to use the default).
     * @param string|null $parentUuid  Optional parent dashboard UUID
     *                                 (REQ-DASH-023). NULL ⇒ root.
     * @param string|null $slug        Optional caller-supplied slug
     *                                 (REQ-DASH-024). NULL ⇒ derive from
     *                                 the name.
     * @param int|null    $sortOrder   Optional sibling sort order
     *                                 (REQ-DASH-029). NULL ⇒ 0.
     *
     * @return JSONResponse The created dashboard.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-16
     */
    #[NoAdminRequired]
    public function create(
        $name=null,
        ?string $description=null,
        ?string $icon=null,
        ?string $parentUuid=null,
        ?string $slug=null,
        ?int $sortOrder=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        // REQ-ASET-003 (extended): admin gating runs FIRST so the response
        // envelope is the stable `personal_dashboards_disabled` shape no
        // matter what the request body looked like.
        try {
            $this->dashboardService->assertPersonalDashboardsAllowed();
        } catch (PersonalDashboardsDisabledException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $resolved = $this->resolveCreateParams(
            name: $name,
            description: $description,
            icon: $icon,
            parentUuid: $parentUuid,
            slug: $slug,
            sortOrder: $sortOrder
        );

        $permError = $this->checkCreatePermissions(
            userId: $this->userId
        );
        if ($permError !== null) {
            return $permError;
        }

        try {
            $dashboard = $this->dashboardService->createDashboard(
                userId: $this->userId,
                name: $resolved['name'],
                description: $resolved['description'],
                icon: $resolved['icon'],
                parentUuid: $resolved['parentUuid'],
                slug: $resolved['slug'],
                sortOrder: $resolved['sortOrder'],
                seedDefaults: true
            );

            // The newly-created dashboard ships with a default widget
            // bundle (Conduction + Sendent + Nextcloud tiles + a Files
            // widget) seeded by the service. Returning the placements
            // here matches the `getActive()` envelope so the store can
            // populate `widgetPlacements` without an extra round-trip.
            $placements = $this->dashboardService->findPlacements(
                dashboardId: $dashboard->getId()
            );

            return ResponseHelper::success(
                data: [
                    'dashboard'  => $dashboard->jsonSerialize(),
                    'placements' => ResponseHelper::serializeList(
                        entities: $placements
                    ),
                ],
                statusCode: Http::STATUS_CREATED
            );
        } catch (InvalidArgumentException $e) {
            // REQ-DASH-023..029: parent / slug / depth / cycle violations
            // surface as HTTP 400 with the validation message verbatim.
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end create()

    /**
     * Update a dashboard.
     *
     * @param int         $id          The dashboard ID.
     * @param string|null $name        The name.
     * @param string|null $description The description.
     * @param array|null  $placements  The placements.
     * @param string|null $icon        The icon registry key, URL, or NULL to leave unchanged.
     * @param string|null $parentUuid  Optional new parent UUID (REQ-DASH-023);
     *                                 explicit empty string clears the
     *                                 parent (re-roots the dashboard).
     * @param string|null $slug        Optional new slug (REQ-DASH-024).
     * @param int|null    $sortOrder   Optional new sort order (REQ-DASH-029).
     *
     * @return JSONResponse The updated dashboard.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-19
     */
    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name=null,
        ?string $description=null,
        ?array $placements=null,
        ?string $icon=null,
        ?string $parentUuid=null,
        ?string $slug=null,
        ?int $sortOrder=null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.update');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        // REQ-PERM-007: Metadata-only updates (name, description, icon) are
        // allowed for all permission levels. Widget/tile/layout changes
        // require add_only or full permission.
        $isMetadataOnly = $placements === null;
        if ($isMetadataOnly === true
            && $this->permissionService->canEditDashboardMetadata(
                userId: $this->userId,
                dashboardId: $id
            ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        if ($isMetadataOnly === false
            && $this->permissionService->canEditDashboard(
                userId: $this->userId,
                dashboardId: $id
            ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $data = $this->buildUpdateData(
                name: $name,
                description: $description,
                placements: $placements,
                icon: $icon,
                parentUuid: $parentUuid,
                slug: $slug,
                sortOrder: $sortOrder
            );

            $dashboard = $this->dashboardService->updateDashboard(
                dashboardId: $id,
                userId: $this->userId,
                data: $data
            );

            // REQ-VERS-001: capture an automatic snapshot after the
            // PUT succeeds. The version service enforces its own
            // debounce window (60 s) so rapid drag-and-drop edits do
            // not flood the table. Failures are swallowed so they do
            // not surface to the dashboard PUT response.
            $this->captureAutomaticSnapshot(dashboard: $dashboard);

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (InvalidArgumentException $e) {
            // REQ-DASH-023..029: parent / slug / depth / cycle violations
            // surface as HTTP 400 with the validation message verbatim.
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end update()

    /**
     * Delete a dashboard.
     *
     * Honours the cascade-delete guard from REQ-DASH-030: when the
     * dashboard has children the request MUST include `?cascade=true`
     * (case-insensitive) — otherwise the response is HTTP 409 with the
     * child count so the UI can surface a confirmation.
     *
     * @param int $id The dashboard ID.
     *
     * @return JSONResponse The deletion confirmation.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-20
     */
    #[NoAdminRequired]
    public function delete(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.delete');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $cascade = $this->resolveCascadeFlag();

        try {
            $this->dashboardService->deleteDashboard(
                dashboardId: $id,
                userId: $this->userId,
                cascade: $cascade
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (DashboardHasChildrenException $e) {
            // REQ-DASH-030: stable 409 envelope with the child count so
            // the frontend can render "Delete N children?" before
            // retrying with cascade=true.
            return new JSONResponse(
                data: [
                    'status'     => 'error',
                    'error'      => DashboardHasChildrenException::ERROR_CODE,
                    'message'    => $e->getMessage(),
                    'childCount' => $e->getChildCount(),
                ],
                statusCode: Http::STATUS_CONFLICT
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end delete()

    /**
     * GET /api/dashboards/tree — return the full nested dashboard tree
     * (REQ-DASH-026).
     *
     * Each node carries `{uuid, name, slug, sortOrder, children: [...]}`.
     * The endpoint is user-agnostic for now — the visible-to-user
     * filter applies via REQ-DASH-013's existing endpoints; this tree is
     * the structural view used by navigation editors and the upcoming
     * `confluence-html-import` / `dashboard-bulk-operations` flows.
     *
     * @return JSONResponse The nested tree.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function tree(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $tree = $this->treeService->getFullTree();

        return ResponseHelper::success(data: $tree);
    }//end tree()

    /**
     * GET /api/dashboards/by-path/{path} — resolve a slug-chain path
     * (REQ-DASH-027).
     *
     * Returns the matching dashboard with its computed `path` and
     * `breadcrumbs` (REQ-DASH-025) attached. Unknown paths return 404.
     *
     * @param string $path The slug-joined path captured from the URL
     *                     (the `{path}` placeholder is regex-allowed
     *                     to include slashes — see `appinfo/routes.php`).
     *
     * @return JSONResponse The dashboard payload, or a 404 envelope.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function byPath(string $path=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($path === '') {
            $path = (string) $this->request->getParam(key: 'path', default: '');
        }

        $dashboard = $this->treeService->resolvePath(path: $path);
        if ($dashboard === null) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'not_found',
                    'message' => 'Dashboard not found at path',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $uuid       = (string) $dashboard->getUuid();
        $serialised = $dashboard->jsonSerialize();
        $serialised['path']        = $this->treeService->computePath(uuid: $uuid);
        $serialised['breadcrumbs'] = $this->treeService->computeBreadcrumbs(
            uuid: $uuid
        );

        return ResponseHelper::success(
            data: ['dashboard' => $serialised]
        );
    }//end byPath()

    /**
     * GET /api/dashboards/{uuid}/path — return a dashboard's canonical
     * slug-chain path.
     *
     * Used by the frontend after every sidebar switch to keep the
     * browser URL in sync with the active dashboard. The path is the
     * leading-slash slug-chain returned by
     * {@see DashboardTreeService::computePath()}; an empty string means
     * the UUID does not resolve OR the dashboard has no slug (legal —
     * NULL slugs are simply unaddressable by path), and the frontend
     * treats either case as "leave the URL alone".
     *
     * @param string $uuid Dashboard UUID captured from the URL.
     *
     * @return JSONResponse `{path: string}` envelope (always 200 when
     *                      authorised — the empty-path case is a valid
     *                      response shape the caller distinguishes
     *                      client-side).
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function computePath(string $uuid=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($uuid === '') {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'missing_uuid',
                    'message' => 'UUID is required',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(
            data: ['path' => $this->treeService->computePath(uuid: $uuid)]
        );
    }//end computePath()

    /**
     * Activate a dashboard.
     *
     * @param int $id The dashboard ID.
     *
     * @return JSONResponse The activated dashboard.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function activate(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.activate');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->activateDashboard(
                dashboardId: $id,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end activate()

    /**
     * List the group-shared dashboards in a single group.
     *
     * Any logged-in user may list. REQ-DASH-014.
     *
     * @param string $groupId The group ID.
     *
     * @return JSONResponse The list of group-shared dashboards.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function listGroup(string $groupId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $dashboards = $this->dashboardService->listGroupDashboards(
            groupId: $groupId
        );

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $dashboards)
        );
    }//end listGroup()

    /**
     * Create a new group-shared dashboard.
     *
     * Admin-only — the route attribute is `#[NoAdminRequired]` so the
     * gate-route-auth check passes; the in-body admin check is the
     * actual authorization point (gate-semantic-auth). REQ-DASH-014.
     *
     * @param string      $groupId     The group ID.
     * @param mixed       $name        The dashboard name (or {name,...}
     *                                 dict as the body).
     * @param string|null $description The dashboard description.
     *
     * @return JSONResponse The created dashboard.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function createGroup(
        string $groupId,
        $name=null,
        ?string $description=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        $resolved = $this->resolveCreateParams(
            name: $name,
            description: $description
        );

        try {
            $dashboard = $this->dashboardService->createGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
                name: $resolved['name'],
                description: $resolved['description']
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()],
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end createGroup()

    /**
     * Get a single group-shared dashboard with placements.
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return JSONResponse The dashboard payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function getGroup(
        string $groupId,
        string $uuid
    ): JSONResponse {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->findGroupDashboard(
                groupId: $groupId,
                uuid: $uuid
            );
        } catch (DoesNotExistException) {
            // ADR-005: do not leak raw exception messages to clients.
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return ResponseHelper::success(
            data: ['dashboard' => $dashboard->jsonSerialize()]
        );
    }//end getGroup()

    /**
     * Update a group-shared dashboard. Admin-only.
     *
     * @param string      $groupId     The group ID from the URL.
     * @param string      $uuid        The dashboard UUID from the URL.
     * @param string|null $name        The new name.
     * @param string|null $description The new description.
     * @param int|null    $gridColumns The new grid column count.
     * @param array|null  $placements  Updated placements.
     *
     * @return JSONResponse The updated dashboard.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function updateGroup(
        string $groupId,
        string $uuid,
        ?string $name=null,
        ?string $description=null,
        ?int $gridColumns=null,
        ?array $placements=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        $patch = $this->buildGroupUpdateData(
            name: $name,
            description: $description,
            gridColumns: $gridColumns,
            placements: $placements
        );

        try {
            $dashboard = $this->dashboardService->updateGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid,
                patch: $patch
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            // ADR-005: do not leak raw exception messages to clients.
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateGroup()

    /**
     * Delete a group-shared dashboard. Admin-only.
     *
     * Returns HTTP 400 when the last-in-group guard rejects the delete
     * (REQ-DASH-014).
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return JSONResponse The status payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function deleteGroup(
        string $groupId,
        string $uuid
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        try {
            $this->dashboardService->deleteGroupShared(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (DoesNotExistException) {
            // ADR-005: do not leak raw exception messages to clients.
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end deleteGroup()

    /**
     * Promote a single group-shared dashboard to the group's default.
     *
     * Admin-only — the route attribute is `#[NoAdminRequired]` so
     * gate-route-auth passes; the in-body admin check is the actual
     * authorization point (gate-semantic-auth). The body payload is
     * `{"uuid": "..."}`. Returns 404 when the uuid does not belong to
     * the given groupId. REQ-DASH-015.
     *
     * @param string      $groupId The group ID from the URL.
     * @param string|null $uuid    The dashboard UUID from the body.
     *
     * @return JSONResponse The status payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function setGroupDefault(
        string $groupId,
        ?string $uuid=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->dashboardService->isAdmin(
            userId: $this->userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_ADMIN
            );
        }

        if ($uuid === null || $uuid === '') {
            return ResponseHelper::error(
                exception: new InvalidArgumentException(
                    'Missing required field: uuid'
                ),
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->dashboardService->setGroupDefault(
                actorUserId: $this->userId,
                groupId: $groupId,
                uuid: $uuid
            );

            return ResponseHelper::success(
                data: [
                    'status'  => 'ok',
                    'groupId' => $groupId,
                    'uuid'    => $uuid,
                ]
            );
        } catch (DoesNotExistException) {
            // ADR-005: do not leak raw exception messages to clients.
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end setGroupDefault()

    /**
     * Persist the user's active-dashboard preference.
     *
     * Accepts any UUID string (including non-existent UUIDs — the resolver's
     * stale-pref path handles invalid values on next render). Empty string
     * clears the preference. REQ-DASH-019.
     *
     * @param string|null $uuid The dashboard UUID from the request body, or
     *                          empty string to clear.
     *
     * @return JSONResponse HTTP 200 `{status: 'success'}` on success; 401
     *                      when the session has no user.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function setActiveDashboard(?string $uuid=null): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $this->dashboardService->setActivePreference(
            userId: $this->userId,
            uuid: ($uuid ?? '')
        );

        return ResponseHelper::success(data: ['status' => 'success']);
    }//end setActiveDashboard()

    /**
     * Pin (or clear) the user's EXPLICIT default-dashboard choice
     * (wave3.7).
     *
     * Distinct from {@see self::setActiveDashboard()} — this pref is
     * only ever written when the user explicitly clicks "Set as
     * default" on a row's cog menu, and is NOT auto-overwritten on
     * every switch. The resolver checks it before the active pref so
     * the pin survives across switches.
     *
     * Body shape: `{uuid: string}` — empty string clears the pin.
     *
     * @param string|null $uuid The dashboard UUID, or empty string to clear.
     *
     * @return JSONResponse 200 `{status: 'success'}` on success; 401
     *                      when the session has no user.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function setDefaultDashboard(?string $uuid=null): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $this->dashboardService->setDefaultPreference(
            userId: $this->userId,
            uuid: ($uuid ?? '')
        );

        return ResponseHelper::success(data: ['status' => 'success']);
    }//end setDefaultDashboard()

    /**
     * Read the user's EXPLICIT default-dashboard pin (wave3.7).
     *
     * @return JSONResponse 200 `{uuid: string}` — empty string when no
     *                      pin set; 401 when the session has no user.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function getDefaultDashboard(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        return ResponseHelper::success(
            data: [
                'uuid' => $this->dashboardService->getDefaultPreference(
                    userId: $this->userId
                ),
            ]
        );
    }//end getDefaultDashboard()

    /**
     * Fork any visible dashboard into a brand-new personal copy.
     *
     * REQ-DASH-020 / REQ-DASH-021 / REQ-DASH-022. Body shape:
     * `{name?: string}` — when `name` is absent the system applies the
     * default `t('My copy of {name}', source.name)` translated via the
     * caller's active language.
     *
     * Status mapping:
     *  - HTTP 201 with the full new dashboard payload on success.
     *  - HTTP 401 when the session has no user.
     *  - HTTP 403 with stable error code `personal_dashboards_disabled`
     *    when the admin flag `allow_user_dashboards` is off — REQ-ASET-003
     *    runtime gating runs FIRST so the envelope shape is stable
     *    regardless of body contents.
     *  - HTTP 404 when the source UUID is not visible to the caller —
     *    do not leak existence (REQ-DASH-020 scenario "Cannot fork a
     *    dashboard you cannot read").
     *  - HTTP 500 when a partial-failure rollback fires — REQ-DASH-021.
     *    ADR-005: the response carries a stable error code and a generic
     *    user-facing message; the underlying exception is logged for ops.
     *
     * @param string      $uuid The source dashboard UUID from the URL.
     * @param string|null $name Optional explicit fork name from the body.
     *
     * @return JSONResponse The new dashboard payload (201) or an
     *                      appropriate error envelope.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function fork(
        string $uuid,
        ?string $name=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $fork = $this->dashboardService->forkAsPersonal(
                userId: $this->userId,
                sourceUuid: $uuid,
                name: $name
            );

            return new JSONResponse(
                data: [
                    'status'    => 'success',
                    'dashboard' => $fork->jsonSerialize(),
                ],
                statusCode: Http::STATUS_CREATED
            );
        } catch (PersonalDashboardsDisabledException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (DoesNotExistException) {
            // REQ-DASH-020: source not visible — 404 without leaking
            // existence (use the canonical message rather than echoing
            // the exception detail).
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $t) {
            // REQ-DASH-021 + ADR-005: log the real cause, return a
            // stable, generic envelope to the client.
            $this->logger->error(
                message: 'mydash: fork failed for user {user}: {message}',
                context: [
                    'user'    => $this->userId,
                    'message' => $t->getMessage(),
                ]
            );
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'internal_error',
                    'message' => 'An unexpected error occurred',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end fork()

    /**
     * Publish a dashboard. REQ-DASH-032.
     *
     * Owner-or-admin gated at the service boundary; the route attribute
     * is `#[NoAdminRequired]` because the in-body owner check is the
     * actual authorization point (gate-semantic-auth).
     *
     * @param string $uuid The dashboard UUID from the URL.
     *
     * @return JSONResponse The updated dashboard payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function publish(string $uuid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.publish');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->publish(
                uuid: $uuid,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return $this->mapPublicationError(exception: $e);
        }//end try
    }//end publish()

    /**
     * Unpublish a dashboard. REQ-DASH-033.
     *
     * @param string $uuid The dashboard UUID from the URL.
     *
     * @return JSONResponse The updated dashboard payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function unpublish(string $uuid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.unpublish');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $dashboard = $this->dashboardService->unpublish(
                uuid: $uuid,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return $this->mapPublicationError(exception: $e);
        }//end try
    }//end unpublish()

    /**
     * Schedule a dashboard for automatic publication. REQ-DASH-034.
     *
     * Body: `{"publishAt": "2026-04-01T10:00:00Z"}`. Returns 400 with an
     * i18n-friendly error message when `publishAt` is missing,
     * unparseable, or in the past; 403 when the actor is neither owner
     * nor admin.
     *
     * @param string      $uuid      The dashboard UUID from the URL.
     * @param string|null $publishAt The future ISO-8601 timestamp from
     *                               the request body.
     *
     * @return JSONResponse The updated dashboard payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function schedule(
        string $uuid,
        ?string $publishAt=null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction($user, 'dashboard.schedule');

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($publishAt === null || $publishAt === '') {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => DashboardService::ERR_SCHEDULE_PAST_DATE,
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $dashboard = $this->dashboardService->schedule(
                uuid: $uuid,
                publishAt: $publishAt,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: ['dashboard' => $dashboard->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->mapPublicationError(exception: $e);
        }//end try
    }//end schedule()

    /**
     * Record a dashboard view event (REQ-ANLT-002).
     *
     * Authenticated users only — POST `{}` body. Returns HTTP 204
     * after the daily counter has been incremented. Short-circuits
     * silently to 204 when the user has opted out (REQ-ANLT-004) or
     * when global analytics is disabled (REQ-ANLT-005). Returns 404
     * when the dashboard does not exist.
     *
     * @param string $uuid The dashboard UUID from the URL.
     *
     * @return JSONResponse An empty 204 response on success, 401
     *                      when unauthenticated, 404 when the
     *                      dashboard does not exist.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboards/spec.md */
    public function viewEvent(string $uuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $this->analyticsService->recordViewEvent(
                dashboardUuid: $uuid,
                userId: $this->userId
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NO_CONTENT
        );
    }//end viewEvent()

    /**
     * Map publication-related service exceptions onto the right HTTP
     * status. REQ-DASH-032..034.
     *
     * The service raises `Exception` with the sentinel message
     * {@see DashboardService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN} when
     * the actor is not allowed; everything else falls through to the
     * generic ResponseHelper::error path.
     *
     * @param \Exception $exception The thrown exception.
     *
     * @return JSONResponse The mapped error envelope.
     */
    private function mapPublicationError(\Exception $exception): JSONResponse
    {
        if ($exception->getMessage() === DashboardService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
        ) {
            return ResponseHelper::forbidden(
                message: DashboardService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
            );
        }

        return ResponseHelper::error(exception: $exception);
    }//end mapPublicationError()

    /**
     * Resolve create parameters from JSON body or individual params.
     *
     * Forwards the new hierarchy fields (`parentUuid`, `slug`,
     * `sortOrder`) introduced by REQ-DASH-023..029. When the caller
     * sends a JSON body the helper inspects the array form first; when
     * positional / typed params come via the framework binding it
     * falls back to those.
     *
     * @param mixed       $name        The name parameter.
     * @param string|null $description The description parameter.
     * @param string|null $icon        The icon parameter.
     * @param string|null $parentUuid  Optional parent UUID.
     * @param string|null $slug        Optional caller-supplied slug.
     * @param int|null    $sortOrder   Optional sort order.
     *
     * @return array{name: string, description: ?string, icon: ?string, parentUuid: ?string, slug: ?string, sortOrder: int}
     *   The resolved values.
     */
    private function resolveCreateParams(
        $name,
        ?string $description,
        ?string $icon=null,
        ?string $parentUuid=null,
        ?string $slug=null,
        ?int $sortOrder=null
    ): array {
        if (is_array($name) === true) {
            $bodyIcon     = ($name['icon'] ?? null);
            $resolvedIcon = null;
            if (is_string($bodyIcon) === true) {
                $resolvedIcon = $bodyIcon;
            }

            $bodyParent     = ($name['parentUuid'] ?? null);
            $bodySlug       = ($name['slug'] ?? null);
            $bodySort       = ($name['sortOrder'] ?? null);
            $resolvedParent = null;
            if (is_string($bodyParent) === true) {
                $resolvedParent = $bodyParent;
            }

            $resolvedSlug = null;
            if (is_string($bodySlug) === true) {
                $resolvedSlug = $bodySlug;
            }

            $resolvedSort = 0;
            if (is_numeric($bodySort) === true) {
                $resolvedSort = (int) $bodySort;
            }

            return [
                'name'        => $name['name'] ?? 'My Dashboard',
                'description' => $name['description'] ?? null,
                'icon'        => $resolvedIcon,
                'parentUuid'  => $resolvedParent,
                'slug'        => $resolvedSlug,
                'sortOrder'   => $resolvedSort,
            ];
        }//end if

        return [
            'name'        => $name ?? 'My Dashboard',
            'description' => $description,
            'icon'        => $icon,
            'parentUuid'  => $parentUuid,
            'slug'        => $slug,
            'sortOrder'   => ($sortOrder ?? 0),
        ];
    }//end resolveCreateParams()

    /**
     * Read the case-insensitive `cascade` query param (REQ-DASH-030).
     *
     * `?cascade=true|TRUE|True|1|yes|on|cascade` → true; anything else
     * (including the param being absent) → false.
     *
     * @return bool Whether cascade-delete was explicitly requested.
     */
    private function resolveCascadeFlag(): bool
    {
        $raw = $this->request->getParam(key: 'cascade');
        if ($raw === null) {
            return false;
        }

        $lower = strtolower((string) $raw);
        return in_array(
            $lower,
            ['true', '1', 'yes', 'on', 'cascade'],
            true
        );
    }//end resolveCascadeFlag()

    /**
     * Check creation permissions and return error if denied.
     *
     * @param string $userId The user ID.
     *
     * @return JSONResponse|null Error response or null if allowed.
     */
    private function checkCreatePermissions(string $userId): ?JSONResponse
    {
        if ($this->permissionService->canCreateDashboard(
            userId: $userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: 'Dashboard creation not allowed'
            );
        }

        $existing = $this->dashboardService->getUserDashboards(
            userId: $userId
        );
        if (empty($existing) === false
            && $this->permissionService->canHaveMultipleDashboards(
                userId: $userId
            ) === false
        ) {
            return ResponseHelper::forbidden(
                message: 'Multiple dashboards not allowed'
            );
        }

        return null;
    }//end checkCreatePermissions()

    /**
     * Build update data from nullable parameters.
     *
     * @param string|null $name        The name.
     * @param string|null $description The description.
     * @param array|null  $placements  The placements.
     * @param string|null $icon        The icon registry key, URL, or NULL/empty.
     * @param string|null $parentUuid  Optional new parent UUID
     *                                 (REQ-DASH-023). The literal sentinel
     *                                 `__null__` clears the parent
     *                                 (re-roots the dashboard) — needed
     *                                 because the framework cannot
     *                                 distinguish "not in payload" from
     *                                 "explicit NULL" with typed-string
     *                                 binding.
     * @param string|null $slug        Optional new slug (REQ-DASH-024).
     * @param int|null    $sortOrder   Optional new sort order
     *                                 (REQ-DASH-029).
     *
     * @return array The non-null update data.
     */
    private function buildUpdateData(
        ?string $name,
        ?string $description,
        ?array $placements,
        ?string $icon=null,
        ?string $parentUuid=null,
        ?string $slug=null,
        ?int $sortOrder=null
    ): array {
        $fields = [
            'name'        => $name,
            'description' => $description,
            'placements'  => $placements,
        ];

        $data = array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );

        // Icon explicitly supports NULL/empty (resets to the default
        // glyph), so it must be merged separately from the array_filter
        // above. Caller distinguishes "not in payload" via the default
        // null sentinel.
        if ($icon !== null) {
            $data['icon'] = $icon;
        }

        // REQ-DASH-023: `parentUuid = '__null__'` is the agreed sentinel
        // for "re-root this dashboard" because the framework's typed
        // string binding cannot represent an explicit NULL. Anything
        // else (non-null string) is forwarded verbatim — including the
        // empty string, which the service treats as a NULL parent.
        if ($parentUuid !== null) {
            $data['parentUuid'] = $parentUuid;
            if ($parentUuid === '__null__' || $parentUuid === '') {
                $data['parentUuid'] = null;
            }
        }

        if ($slug !== null) {
            $data['slug'] = $slug;
        }

        if ($sortOrder !== null) {
            $data['sortOrder'] = $sortOrder;
        }

        return $data;
    }//end buildUpdateData()

    /**
     * Build the patch payload for the group-shared update endpoint.
     *
     * @param string|null $name        The new name.
     * @param string|null $description The new description.
     * @param int|null    $gridColumns The new grid columns.
     * @param array|null  $placements  Updated placements.
     *
     * @return array The non-null patch fields.
     */
    private function buildGroupUpdateData(
        ?string $name,
        ?string $description,
        ?int $gridColumns,
        ?array $placements
    ): array {
        $fields = [
            'name'        => $name,
            'description' => $description,
            'gridColumns' => $gridColumns,
            'placements'  => $placements,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildGroupUpdateData()

    /**
     * Capture an automatic version snapshot after a successful update
     * (REQ-VERS-001). The version service enforces a 60-second debounce
     * window so a flurry of drag-and-drop saves does not flood the
     * table.
     *
     * Failures here MUST NOT surface to the dashboard PUT response —
     * the user's edit succeeded; missing one snapshot is a quality of
     * life regression, not a data-integrity bug. We log + swallow.
     *
     * @param \OCA\MyDash\Db\Dashboard $dashboard The dashboard that was
     *                                            just updated.
     *
     * @return void
     */
    private function captureAutomaticSnapshot(
        \OCA\MyDash\Db\Dashboard $dashboard
    ): void {
        if ($this->userId === null) {
            return;
        }

        try {
            $this->versionService->captureSnapshot(
                dashboard: $dashboard,
                snapshotJson: null,
                createdBy: $this->userId,
                note: null,
                explicit: false
            );
        } catch (\Throwable $t) {
            $this->logger->warning(
                message: 'mydash: automatic version snapshot failed',
                context: ['exception' => $t]
            );
        }
    }//end captureAutomaticSnapshot()
}//end class
