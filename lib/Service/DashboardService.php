<?php

/**
 * DashboardService
 *
 * Service for managing dashboards (personal, group-shared, and the
 * visible-to-user resolution endpoint).
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTime;
use Exception;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardLockMapper;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\DashboardHasChildrenException;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing dashboards.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Personal + group-shared + visible-to-user CRUD lives here intentionally.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same; splitting risks losing the single-source-of-truth behaviour.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   The constructor wires every dependency the three scopes need.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     `resolveActiveDashboard` fans out the 7-step REQ-DASH-018 chain.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Single source of truth for CRUD + tree + publication + footer.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Mode methods live next to one another for grep-ability.
 */
class DashboardService
{
    /**
     * HTTP-like error message for non-admin attempting an admin-only mutation.
     *
     * @var string
     */
    public const ERR_FORBIDDEN_NOT_ADMIN = 'Forbidden: admin only';

    /**
     * Error message returned by the last-in-group delete guard.
     *
     * @var string
     */
    public const ERR_LAST_IN_GROUP = 'Cannot delete the only dashboard in the group';

    /**
     * Error message returned when the path-group does not match the record.
     *
     * @var string
     */
    public const ERR_GROUP_MISMATCH = 'Dashboard does not belong to this group';

    /**
     * Error message returned when the default-flip target is not found.
     *
     * @var string
     */
    public const ERR_DEFAULT_TARGET_NOT_IN_GROUP = 'Dashboard not found in group';

    /**
     * Preference key for the user's last-active dashboard UUID.
     *
     * Stored via IConfig::setUserValue / getUserValue.
     * REQ-DASH-019.
     *
     * @var string
     */
    public const ACTIVE_DASHBOARD_UUID_PREF_KEY = 'active_dashboard_uuid';

    /**
     * User-pref key for the EXPLICIT default dashboard (wave3.7).
     * Distinct from `active_dashboard_uuid` — this one is only ever
     * written when the user explicitly pins a dashboard via the
     * per-row "Set as default" action; it is NOT auto-overwritten on
     * every switch. The resolver checks it BEFORE the active pref so
     * an explicit pin survives across switches.
     *
     * @var string
     */
    public const DEFAULT_DASHBOARD_UUID_PREF_KEY = 'default_dashboard_uuid';

    /**
     * HTTP-like error message for non-owner / non-admin attempting a
     * publication state mutation. REQ-DASH-032..034.
     *
     * @var string
     */
    public const ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN = 'Forbidden: owner or admin only';

    /**
     * Validation error returned when `publishAt` is missing or in the
     * past for the schedule action. REQ-DASH-033.
     *
     * @var string
     */
    public const ERR_SCHEDULE_PAST_DATE = 'publishAt must be a future timestamp';

    /**
     * Constructor
     *
     * @param DashboardMapper                  $dashboardMapper      Dashboard mapper.
     * @param WidgetPlacementMapper            $placementMapper      Widget placement mapper.
     * @param AdminSettingMapper               $settingMapper        Admin setting mapper.
     * @param TemplateService                  $templateService      Template service.
     * @param DashboardFactory                 $dashboardFactory     Dashboard factory.
     * @param DashboardResolver                $dashResolver         Dashboard resolver.
     * @param DashboardTreeService             $treeService          Tree-aware
     *                                                               validation
     *                                                               / cascade
     *                                                               walker
     *                                                               (REQ-DASH-023..030).
     * @param IGroupManager                    $groupManager         Group manager (used for
     *                                                               `isAdmin` only —
     *                                                               group membership
     *                                                               lookups go through the
     *                                                               routing resolver per
     *                                                               REQ-TMPL-013).
     * @param AdminTemplateService             $adminTemplateService Routing resolver —
     *                                                               single source of truth
     *                                                               for
     *                                                               `IGroupManager::getUserGroupIds`
     *                                                               (REQ-TMPL-013).
     * @param IDBConnection                    $db                   DB connection (for the
     *                                                               transactional default
     *                                                               flip —
     *                                                               REQ-DASH-015).
     * @param IConfig                          $config               Nextcloud per-user
     *                                                               preference
     *                                                               storage.
     * @param IFactory                         $l10nFactory          L10N factory used to
     *                                                               build the "My copy
     *                                                               of {name}" default
     *                                                               fork name
     *                                                               (REQ-DASH-020).
     * @param LoggerInterface                  $logger               PSR logger.
     * @param DashboardTranslationService|null $translationService   Optional
     *                                                               translation
     *                                                               service
     *                                                               for the
     *                                                               per-language
     *                                                               content
     *                                                               variants
     *                                                               (REQ-DASH-038..044).
     *                                                               Nullable
     *                                                               so
     *                                                               legacy
     *                                                               test
     *                                                               doubles
     *                                                               constructed
     *                                                               without
     *                                                               it keep
     *                                                               working.
     * @param DashboardLockMapper|null         $lockMapper           Optional lock
     *                                                               mapper. When
     *                                                               provided the
     *                                                               delete path
     *                                                               cascades the
     *                                                               row removal
     *                                                               to the
     *                                                               editing-lock
     *                                                               table per
     *                                                               REQ-LOCK-008.
     *                                                               Nullable to
     *                                                               keep the
     *                                                               constructor
     *                                                               backwards-
     *                                                               compatible
     *                                                               with existing
     *                                                               unit tests.
     * @param FooterService|null               $footerService        Optional
     *                                                               per-dashboard
     *                                                               footer
     *                                                               sanitiser +
     *                                                               resolver
     *                                                               (REQ-FTR-006).
     *                                                               Nullable for
     *                                                               backwards-
     *                                                               compat with
     *                                                               existing
     *                                                               test doubles.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly TemplateService $templateService,
        private readonly DashboardFactory $dashboardFactory,
        private readonly DashboardResolver $dashResolver,
        private readonly DashboardTreeService $treeService,
        private readonly IGroupManager $groupManager,
        private readonly AdminTemplateService $adminTemplateService,
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly IFactory $l10nFactory,
        private readonly LoggerInterface $logger,
        private readonly ?DashboardTranslationService $translationService=null,
        private readonly ?DashboardLockMapper $lockMapper=null,
        private readonly ?FooterService $footerService=null,
    ) {
    }//end __construct()

    /**
     * Get all dashboards for a user.
     *
     * Returns only personal `user`-type dashboards owned by the caller —
     * group-shared dashboards never appear here (REQ-DASH-014, see
     * `getVisibleToUser` for the unioned endpoint).
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard[] The list of personal dashboards.
     */
    public function getUserDashboards(string $userId): array
    {
        return $this->dashboardMapper->findByUserId(userId: $userId);
    }//end getUserDashboards()

    /**
     * Get a single dashboard with its placements + permission level when
     * the user is allowed to read it.
     *
     * Visibility is delegated to {@see self::getVisibleToUser()} so
     * ownership, group-share, and publication-state filters all share one
     * implementation. The returned shape mirrors {@see DashboardResolver::buildResult()}
     * so the front-end's switch flow consumes the same envelope it gets
     * from `GET /api/dashboard` (active dashboard).
     *
     * @param int    $dashboardId The dashboard ID to load.
     * @param string $userId      The caller's user ID.
     *
     * @return array|null `{dashboard, placements, permissionLevel}` when
     *                    visible; null when the user has no read access.
     */
    public function getDashboardForUser(
        int $dashboardId,
        string $userId
    ): ?array {
        // `getVisibleToUser` returns entries shaped as
        // `{dashboard: Dashboard, source: string}` (per
        // {@see self::filterByPublicationState()} contract). Match on
        // `dashboard.id` rather than treating the entry itself as an
        // entity — calling `getId()` on the wrapper triggers the
        // "member function on array" fatal that broke the switch flow
        // when this method first shipped.
        $visible   = $this->getVisibleToUser(userId: $userId);
        $dashboard = null;
        foreach ($visible as $entry) {
            $candidate = $entry['dashboard'];
            if ($candidate->getId() === $dashboardId) {
                $dashboard = $candidate;
                break;
            }
        }

        if ($dashboard === null) {
            return null;
        }

        $placements = $this->placementMapper->findByDashboardId(
            dashboardId: $dashboard->getId()
        );

        return $this->dashResolver->buildResult(
            dashboard: $dashboard,
            placements: $placements
        );
    }//end getDashboardForUser()

    /**
     * Get the effective dashboard for a user.
     * Returns user's active dashboard or applicable admin template.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The effective dashboard data or null.
     */
    public function getEffectiveDashboard(string $userId): ?array
    {
        // Wave3.7 Step 0 — explicit default-dashboard pin wins over
        // the auto-overwriting active flag. Resolves the pinned UUID
        // through the user's visible set so a stale UUID falls
        // through to the legacy chain rather than 404'ing.
        $defaultUuid = $this->getDefaultPreference(userId: $userId);
        if ($defaultUuid !== '') {
            $visible = $this->getVisibleToUser(userId: $userId);
            foreach ($visible as $entry) {
                $candidate = $entry['dashboard'];
                if ((string) $candidate->getUuid() === $defaultUuid) {
                    $placements = $this->placementMapper->findByDashboardId(
                        dashboardId: $candidate->getId()
                    );
                    return $this->dashResolver->buildResult(
                        dashboard: $candidate,
                        placements: $placements
                    );
                }
            }
        }

        $result = $this->dashResolver->tryGetActiveDashboard(
            userId: $userId
        );
        if ($result !== null) {
            return $result;
        }

        $result = $this->dashResolver->tryActivateExistingDashboard(
            userId: $userId
        );
        if ($result !== null) {
            return $result;
        }

        return $this->tryCreateFromTemplate(userId: $userId);
    }//end getEffectiveDashboard()

    /**
     * Create a new dashboard for a user.
     *
     * Accepts the optional hierarchy fields introduced by REQ-DASH-023..029:
     * `parentUuid`, `slug`, `sortOrder`. The factory generates a slug
     * from the name when none is supplied; the tree service validates
     * the parent (existence, cycle, depth) and the slug uniqueness
     * (per-parent) before the row is persisted.
     *
     * @param string      $userId      The user ID.
     * @param string      $name        The dashboard name.
     * @param string|null $description The dashboard description.
     * @param string|null $icon        Opaque icon identifier (registry
     *                                 key or URL); see the
     *                                 `dashboard-icons` capability.
     * @param string|null $parentUuid  Optional parent dashboard UUID
     *                                 (REQ-DASH-023). NULL ⇒ root.
     * @param string|null $slug        Optional caller-supplied slug
     *                                 (REQ-DASH-024). NULL ⇒ derive from
     *                                 the name.
     * @param int         $sortOrder   Optional sibling sort order
     *                                 (REQ-DASH-029). Defaults to 0.
     *
     * @return Dashboard The created dashboard.
     */
    public function createDashboard(
        string $userId,
        string $name,
        ?string $description=null,
        ?string $icon=null,
        ?string $parentUuid=null,
        ?string $slug=null,
        int $sortOrder=0
    ): Dashboard {
        // REQ-DASH-023, REQ-DASH-028: parent existence + cycle +
        // depth checks BEFORE the entity is built so the request is
        // rejected without a partial row in memory.
        $this->treeService->validateParent(
            movingUuid: null,
            newParentUuid: $parentUuid
        );

        $dashboard = $this->dashboardFactory->create(
            userId: $userId,
            name: $name,
            description: $description,
            parentUuid: $parentUuid,
            slug: $slug,
            sortOrder: $sortOrder
        );

        // Icon is an opaque string (registry key or URL) — see the
        // `dashboard-icons` capability. NULL/empty means "use the
        // frontend default glyph".
        if ($icon !== null && $icon !== '') {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setIcon($icon);
        }

        // REQ-DASH-024: slug uniqueness within parent scope. The
        // factory may have emitted NULL when the name yields no legal
        // characters and the caller did not supply an explicit slug —
        // skip the unique check in that case (NULL slugs are simply
        // unaddressable by path).
        $resolvedSlug = $dashboard->getSlug();
        if ($resolvedSlug !== null && $resolvedSlug !== '') {
            $this->treeService->validateSlugUnique(
                parentUuid: $parentUuid,
                slug: $resolvedSlug
            );
        }

        $this->dashboardMapper->deactivateAllForUser(userId: $userId);

        $persisted = $this->dashboardMapper->insert(entity: $dashboard);

        // REQ-DASH-038, REQ-DASH-044: every newly created dashboard gets
        // a primary translation row in the owner's Nextcloud locale (or
        // the default fallback when no preference is set). The seed is
        // best-effort — failure here does not abort dashboard creation,
        // because the legacy materialiser keeps reads working until the
        // seed retries via a subsequent translation API call.
        if ($this->translationService !== null) {
            try {
                $this->translationService->seedPrimaryFor(
                    dashboard: $persisted
                );
            } catch (Throwable $t) {
                $this->logger->warning(
                    message: 'mydash: failed to seed primary translation: {message}',
                    context: ['message' => $t->getMessage()]
                );
            }
        }

        return $persisted;
    }//end createDashboard()

    /**
     * Update a dashboard.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     * @param array  $data        The data to update.
     *
     * @return Dashboard The updated dashboard.
     */
    public function updateDashboard(
        int $dashboardId,
        string $userId,
        array $data
    ): Dashboard {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        $this->applyDashboardUpdates(
            dashboard: $dashboard,
            data: $data
        );

        return $this->dashboardMapper->update(entity: $dashboard);
    }//end updateDashboard()

    /**
     * Delete a dashboard, with cascade-delete guard for the tree.
     *
     * REQ-DASH-030: when the dashboard has children the caller MUST pass
     * `$cascade = true`; otherwise `Exception` is raised with the
     * sentinel message `Dashboard has children` so the controller can
     * map it to HTTP 409 with the child count. With `$cascade = true`
     * the entire subtree (including placements) is removed via
     * {@see DashboardTreeService::deleteSubtree()}.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     * @param bool   $cascade     When true, remove the entire subtree.
     *
     * @return void
     */
    public function deleteDashboard(
        int $dashboardId,
        string $userId,
        bool $cascade=false
    ): void {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        $uuid = (string) $dashboard->getUuid();
        if ($uuid !== '' && $cascade === false) {
            $childCount = $this->dashboardMapper->countChildrenByParent(
                parentUuid: $uuid
            );
            if ($childCount > 0) {
                throw new DashboardHasChildrenException(
                    childCount: $childCount
                );
            }
        }

        if ($cascade === true && $uuid !== '') {
            // Translation rows are scoped by uuid; clear them BEFORE the
            // tree walker drops the parent rows so we never orphan a
            // variant on a vanished dashboard. The cascade-delete spans
            // the entire descendant set as well. REQ-DASH-044.
            if ($this->translationService !== null) {
                $descendants = $this->dashboardMapper->findDescendants(
                    ancestorUuid: $uuid
                );
                foreach ($descendants as $descendant) {
                    $descendantUuid = (string) $descendant->getUuid();
                    if ($descendantUuid !== '') {
                        $this->translationService->deleteAllForDashboard(
                            dashboardUuid: $descendantUuid
                        );
                    }
                }

                $this->translationService->deleteAllForDashboard(
                    dashboardUuid: $uuid
                );
            }

            // Cascade-clear the editing lock for the root before the
            // subtree wipe (REQ-LOCK-008). Descendant locks are not
            // tracked through this path because the spec scopes locks
            // to the dashboard the user is editing — children that
            // disappear simply leak a row that the next-acquire
            // inline-cleanup will reap.
            if ($this->lockMapper !== null) {
                $this->lockMapper->deleteByDashboardUuid(
                    dashboardUuid: $uuid
                );
            }

            $this->treeService->deleteSubtree(dashboard: $dashboard);
            return;
        }//end if

        // REQ-DASH-044: cascade-delete translation variants for the
        // dashboard about to disappear so they don't outlive the parent.
        if ($uuid !== '' && $this->translationService !== null) {
            $this->translationService->deleteAllForDashboard(
                dashboardUuid: $uuid
            );
        }

        $this->placementMapper->deleteByDashboardId(
            dashboardId: $dashboardId
        );

        // Cascade-clear the editing lock so a deleted dashboard never
        // leaves an orphaned lock row behind (REQ-LOCK-008).
        if ($this->lockMapper !== null && $uuid !== '') {
            $this->lockMapper->deleteByDashboardUuid(
                dashboardUuid: $uuid
            );
        }

        $this->dashboardMapper->delete(entity: $dashboard);
    }//end deleteDashboard()

    /**
     * Activate a dashboard for a user.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     *
     * @return Dashboard The activated dashboard.
     */
    public function activateDashboard(
        int $dashboardId,
        string $userId
    ): Dashboard {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        $this->dashboardMapper->setActive(
            $dashboardId,
            userId: $userId
        );
        // Cast to int — the entity column is SMALLINT.
        $dashboard->setIsActive(1);

        return $dashboard;
    }//end activateDashboard()

    /**
     * List the group-shared dashboards in a single group.
     *
     * Any logged-in user may list — REQ-DASH-014.
     *
     * @param string $groupId The group ID.
     *
     * @return Dashboard[] The group-shared dashboards in the group.
     */
    public function listGroupDashboards(string $groupId): array
    {
        return $this->dashboardMapper->findByGroup(groupId: $groupId);
    }//end listGroupDashboards()

    /**
     * Find a single group-shared dashboard, validating the path-group.
     *
     * Returns the dashboard only when its `groupId` matches the path
     * parameter — otherwise the caller treats it as a 404.
     * REQ-DASH-014 (group-id mismatch returns 404).
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return Dashboard The dashboard.
     *
     * @throws DoesNotExistException When no dashboard with that UUID
     *                               exists, or when its `groupId` does
     *                               not match the path parameter, or
     *                               when its type is not group_shared.
     */
    public function findGroupDashboard(
        string $groupId,
        string $uuid
    ): Dashboard {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);

        if ($dashboard->getType() !== Dashboard::TYPE_GROUP_SHARED) {
            throw new DoesNotExistException(
                msg: self::ERR_GROUP_MISMATCH
            );
        }

        if ($dashboard->getGroupId() !== $groupId) {
            throw new DoesNotExistException(
                msg: self::ERR_GROUP_MISMATCH
            );
        }

        return $dashboard;
    }//end findGroupDashboard()

    /**
     * Create a new group-shared dashboard.
     *
     * Admin-only — caller MUST have validated the actor with
     * {@see DashboardService::isAdmin()}. The route attribute alone is
     * not enough (per Hydra semantic-auth gate).
     *
     * @param string      $actorUserId The acting user ID (for the admin
     *                                 check).
     * @param string      $groupId     The group ID.
     * @param string      $name        The dashboard name.
     * @param string|null $description The dashboard description.
     * @param int         $gridColumns The grid column count.
     *
     * @return Dashboard The created group-shared dashboard.
     *
     * @throws Exception When the actor is not an administrator.
     */
    public function createGroupShared(
        string $actorUserId,
        string $groupId,
        string $name,
        ?string $description=null,
        int $gridColumns=12
    ): Dashboard {
        if ($this->isAdmin(userId: $actorUserId) === false) {
            throw new Exception(message: self::ERR_FORBIDDEN_NOT_ADMIN);
        }

        $dashboard = $this->dashboardFactory->create(
            userId: null,
            name: $name,
            description: $description,
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: $groupId,
            gridColumns: $gridColumns
        );
        $dashboard->setPermissionLevel(Dashboard::PERMISSION_VIEW_ONLY);
        // REQ-DASH-016: new group-shared rows always start non-default.
        // Promotion is only possible via the dedicated
        // POST /api/dashboards/group/{groupId}/default endpoint.
        $dashboard->setIsDefault(0);

        return $this->dashboardMapper->insert(entity: $dashboard);
    }//end createGroupShared()

    /**
     * Update a group-shared dashboard.
     *
     * Admin-only. The path's `groupId` must match the record's `groupId`
     * — otherwise `DoesNotExistException` (treated as 404 by caller).
     * The `userId` field is intentionally never patched (REQ-DASH-014).
     *
     * @param string $actorUserId The acting user ID (for the admin check).
     * @param string $groupId     The group ID from the URL.
     * @param string $uuid        The dashboard UUID from the URL.
     * @param array  $patch       The patch data (name, description,
     *                            gridColumns, placements supported).
     *
     * @return Dashboard The updated dashboard.
     *
     * @throws Exception When the actor is not an administrator.
     * @throws DoesNotExistException On 404.
     */
    public function updateGroupShared(
        string $actorUserId,
        string $groupId,
        string $uuid,
        array $patch
    ): Dashboard {
        if ($this->isAdmin(userId: $actorUserId) === false) {
            throw new Exception(message: self::ERR_FORBIDDEN_NOT_ADMIN);
        }

        $dashboard = $this->findGroupDashboard(
            groupId: $groupId,
            uuid: $uuid
        );

        // REQ-DASH-017: PUT MUST NOT mutate `isDefault` regardless of
        // payload contents. Drop the field defensively before applying
        // updates — even though `applyDashboardUpdates` already ignores
        // unknown keys, we strip it explicitly so the contract is
        // visible at the service boundary.
        unset($patch['isDefault']);

        $this->applyDashboardUpdates(
            dashboard: $dashboard,
            data: $patch
        );

        return $this->dashboardMapper->update(entity: $dashboard);
    }//end updateGroupShared()

    /**
     * Delete a group-shared dashboard.
     *
     * Admin-only. The last-in-group guard returns an `Exception` (the
     * controller maps to HTTP 400) when removing the row would leave
     * the group with zero group-shared dashboards. The `default` group
     * is exempt from the guard. REQ-DASH-014.
     *
     * @param string $actorUserId The acting user ID.
     * @param string $groupId     The group ID from the URL.
     * @param string $uuid        The dashboard UUID from the URL.
     *
     * @return void
     *
     * @throws Exception When the actor is not admin, or the
     *                   last-in-group guard rejects the delete.
     * @throws DoesNotExistException On 404.
     */
    public function deleteGroupShared(
        string $actorUserId,
        string $groupId,
        string $uuid
    ): void {
        if ($this->isAdmin(userId: $actorUserId) === false) {
            throw new Exception(message: self::ERR_FORBIDDEN_NOT_ADMIN);
        }

        $dashboard = $this->findGroupDashboard(
            groupId: $groupId,
            uuid: $uuid
        );

        if ($groupId !== Dashboard::DEFAULT_GROUP_ID) {
            $count = $this->dashboardMapper->countByGroup(
                groupId: $groupId
            );
            if ($count <= 1) {
                throw new Exception(message: self::ERR_LAST_IN_GROUP);
            }
        }

        $this->placementMapper->deleteByDashboardId(
            dashboardId: $dashboard->getId()
        );
        $this->dashboardMapper->delete(entity: $dashboard);
    }//end deleteGroupShared()

    /**
     * Promote a single group-shared dashboard to the group's default.
     *
     * Admin-only. Wraps both mapper writes — clear the existing default
     * on every other dashboard in the group, then set the target to
     * `is_default = 1` — in a single DB transaction so concurrent
     * promotions cannot leave two rows with `is_default = 1` in the
     * same group. REQ-DASH-015.
     *
     * Order of operations matters: we issue the SET first; if the
     * target uuid does not belong to the group the row count is `0`
     * and we throw {@see DoesNotExistException} (mapped to HTTP 404 by
     * the controller). The transaction is then rolled back, leaving
     * the previous default untouched.
     *
     * @param string $actorUserId The acting user ID (for the admin
     *                            check).
     * @param string $groupId     The group ID from the URL.
     * @param string $uuid        The dashboard UUID from the URL.
     *
     * @return void
     *
     * @throws Exception              When the actor is not an admin.
     * @throws DoesNotExistException  When the uuid does not belong to
     *                                the given group.
     */
    public function setGroupDefault(
        string $actorUserId,
        string $groupId,
        string $uuid
    ): void {
        if ($this->isAdmin(userId: $actorUserId) === false) {
            throw new Exception(message: self::ERR_FORBIDDEN_NOT_ADMIN);
        }

        $this->db->beginTransaction();
        try {
            $affected = $this->dashboardMapper->setGroupDefaultUuid(
                groupId: $groupId,
                uuid: $uuid
            );

            if ($affected === 0) {
                // The target uuid does not belong to this group — roll
                // back so the existing default in the group is
                // preserved. REQ-DASH-015 scenario "Default cannot be
                // set across groups".
                $this->db->rollBack();
                throw new DoesNotExistException(
                    msg: self::ERR_DEFAULT_TARGET_NOT_IN_GROUP
                );
            }

            $this->dashboardMapper->clearGroupDefaults(
                groupId: $groupId,
                exceptUuid: $uuid
            );

            $this->db->commit();
        } catch (DoesNotExistException $e) {
            // Already rolled back above — re-throw for the controller.
            throw $e;
        } catch (Throwable $t) {
            $this->db->rollBack();
            throw $t;
        }//end try
    }//end setGroupDefault()

    /**
     * Get all dashboards visible to a user, source-tagged.
     *
     * Resolves the user's full group memberships through
     * {@see AdminTemplateService::getUserGroupIdsFor()} (REQ-TMPL-013 —
     * single source of truth for `IGroupManager::getUserGroupIds`) and
     * wires the result into the mapper's union query. Returns the
     * deduplicated list with `source` set per row. REQ-DASH-013.
     *
     * @param string $userId The user ID.
     *
     * @return array<int, array{dashboard: Dashboard, source: string}>
     *   List of {dashboard, source} pairs.
     */
    public function getVisibleToUser(string $userId): array
    {
        $userGroupIds = $this->adminTemplateService->getUserGroupIdsFor(
            userId: $userId
        );

        $entries = $this->dashboardMapper->findVisibleToUser(
            userId: $userId,
            userGroupIds: $userGroupIds
        );

        // REQ-DASH-031..035: apply publication-state filter and lazy
        // materialisation in one pass — drafts hidden from non-owners,
        // scheduled rows past `publishAt` surfaced as published without
        // a DB write, future scheduled rows hidden from non-owners.
        // Defensive cast: legacy test doubles may stub isAdmin to null.
        $isAdmin = (bool) $this->safeIsAdmin(userId: $userId);

        return $this->filterByPublicationState(
            entries: $entries,
            actorUserId: $userId,
            actorIsAdmin: $isAdmin
        );
    }//end getVisibleToUser()

    /**
     * Defensive admin check for the visibility filter.
     *
     * Wraps {@see self::isAdmin()} in a try/catch so legacy test doubles
     * that stub the underlying `IGroupManager::isAdmin` to return `null`
     * or throw on unknown users degrade gracefully to "not admin"
     * instead of taking down the whole `getVisibleToUser` call.
     *
     * @param string $userId The user ID.
     *
     * @return bool Whether the user is a Nextcloud admin (false on any
     *              defect in the underlying group manager).
     */
    private function safeIsAdmin(string $userId): bool
    {
        try {
            return (bool) $this->groupManager->isAdmin(userId: $userId);
        } catch (Throwable) {
            return false;
        }
    }//end safeIsAdmin()

    /**
     * Apply publication-state filtering and lazy materialisation to a
     * visible-to-user result set. REQ-DASH-031..035.
     *
     * Rules:
     *  - `published` rows pass through unchanged.
     *  - `scheduled` rows whose `publishAt <= now()` are surfaced as
     *    `published` (entity mutated in-memory only — no DB write).
     *  - `scheduled` rows whose `publishAt > now()` and `draft` rows are
     *    hidden from non-owner non-admin viewers; owners and admins
     *    keep seeing them in their unmaterialised form.
     *
     * @param array<int, array{dashboard: Dashboard, source: string}> $entries      The mapper result set.
     * @param string                                                  $actorUserId  The acting user ID.
     * @param bool                                                    $actorIsAdmin Whether the actor is a Nextcloud admin.
     *
     * @return array<int, array{dashboard: Dashboard, source: string}>
     *   The filtered, lazily-materialised result set.
     */
    private function filterByPublicationState(
        array $entries,
        string $actorUserId,
        bool $actorIsAdmin
    ): array {
        $now      = new DateTime();
        $filtered = [];

        foreach ($entries as $entry) {
            $dashboard = $entry['dashboard'];
            $status    = $dashboard->getPublicationStatus();
            // Pre-migration / legacy rows that never set the column
            // semantically remain visible (REQ-DASH-035): treat an
            // empty string as `'published'` so backwards compatibility
            // holds even if an entity is hydrated without the column.
            if ($status === '') {
                $status = Dashboard::STATUS_PUBLISHED;
            }

            // REQ-DASH-034: lazy materialisation of due scheduled rows.
            if ($status === Dashboard::STATUS_SCHEDULED) {
                $publishAt = $dashboard->getPublishAt();
                if ($publishAt !== null && $publishAt !== '') {
                    try {
                        $when = new DateTime($publishAt);
                        if ($when <= $now) {
                            $dashboard->setPublicationStatus(
                                Dashboard::STATUS_PUBLISHED
                            );
                            $status = Dashboard::STATUS_PUBLISHED;
                        }
                    } catch (Exception) {
                        // Malformed timestamp — leave as scheduled and
                        // fall through to the visibility check below.
                    }
                }
            }

            if ($status === Dashboard::STATUS_PUBLISHED) {
                $filtered[] = $entry;
                continue;
            }

            // Status is now draft or (still) scheduled — hide from
            // non-owner non-admin viewers.
            $ownerId = $dashboard->getUserId();
            $isOwner = ($ownerId !== null && $ownerId === $actorUserId);
            if ($isOwner === true || $actorIsAdmin === true) {
                $filtered[] = $entry;
            }
        }//end foreach

        return $filtered;
    }//end filterByPublicationState()

    /**
     * Resolve the active dashboard for a user using the 7-step precedence
     * chain defined in REQ-DASH-018.
     *
     * Steps:
     *  1. Saved `active_dashboard_uuid` preference — if the UUID resolves to
     *     a dashboard currently visible to the user (REQ-DASH-013).
     *  2. `group_shared` with `isDefault = 1` in the user's primary group.
     *  3. `group_shared` with `isDefault = 1` in the `'default'` group.
     *  4. First `group_shared` (by sortOrder ASC, then createdAt) in the
     *     user's primary group.
     *  5. First `group_shared` in the `'default'` group.
     *  6. User's first personal (`user`-type) dashboard.
     *  7. `null` — triggers the empty-state UI.
     *
     * The only side-effect on read is the stale-pref auto-clear in step 1:
     * when the saved UUID is not visible the pref is deleted and a WARNING
     * is logged before falling through to step 2.
     *
     * @param string      $userId         The user ID.
     * @param string|null $primaryGroupId The user's primary group ID, or null /
     *                                    {@see Dashboard::DEFAULT_GROUP_ID}.
     *
     * @return array{dashboard: Dashboard, source: string}|null
     *   `{dashboard, source}` where source is `'user'`, `'group'`, or
     *   `'default'`; or `null` when no dashboard exists at all.
     */
    public function resolveActiveDashboard(
        string $userId,
        ?string $primaryGroupId
    ): ?array {
        // Normalise the sentinel so steps 2-5 can rely on it.
        $groupId = $primaryGroupId;
        if ($primaryGroupId === null || $primaryGroupId === '') {
            $groupId = Dashboard::DEFAULT_GROUP_ID;
        }

        // Pre-fetch all visible dashboards once — used for the pref lookup
        // and to avoid redundant DB round-trips.
        $visible = $this->getVisibleToUser(userId: $userId);

        // Build a UUID-keyed index for O(1) pref lookup.
        /**
         * UUID-indexed view of $visible for O(1) lookup.
         *
         * @var array<string, array{dashboard: Dashboard, source: string}> $byUuid
         */
        $byUuid = [];
        foreach ($visible as $entry) {
            $uuid = (string) $entry['dashboard']->getUuid();
            if ($uuid !== '') {
                $byUuid[$uuid] = $entry;
            }
        }

        // Step 0 (wave3.7): explicit default — if the user has pinned
        // a default dashboard via the per-row "Set as default" action,
        // it always wins over the auto-overwriting `active_dashboard_uuid`
        // pref so visiting `/apps/mydash/` consistently opens the same
        // dashboard regardless of where the user navigated last.
        $defaultUuid = $this->config->getUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::DEFAULT_DASHBOARD_UUID_PREF_KEY,
            default: ''
        );

        if ($defaultUuid !== '') {
            if (isset($byUuid[$defaultUuid]) === true) {
                return $byUuid[$defaultUuid];
            }

            // Stale default: UUID is no longer visible — clear and fall through.
            $this->config->deleteUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::DEFAULT_DASHBOARD_UUID_PREF_KEY
            );
            $this->logger->warning(
                message: 'mydash: stale default_dashboard_uuid "{uuid}" cleared for user "{user}"',
                context: ['uuid' => $defaultUuid, 'user' => $userId]
            );
        }

        // Step 1: saved preference.
        $savedUuid = $this->config->getUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::ACTIVE_DASHBOARD_UUID_PREF_KEY,
            default: ''
        );

        if ($savedUuid !== '') {
            if (isset($byUuid[$savedUuid]) === true) {
                return $byUuid[$savedUuid];
            }

            // Stale pref: UUID is no longer visible — clear and fall through.
            $this->config->deleteUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::ACTIVE_DASHBOARD_UUID_PREF_KEY
            );
            $this->logger->warning(
                message: 'mydash: stale active_dashboard_uuid "{uuid}" cleared for user "{user}"',
                context: ['uuid' => $savedUuid, 'user' => $userId]
            );
        }

        // Steps 2-3: group-shared with isDefault = 1.
        if ($groupId !== Dashboard::DEFAULT_GROUP_ID) {
            // Step 2: primary group default.
            $result = $this->findFirstGroupSharedWhere(
                visible: $visible,
                groupId: $groupId,
                source: Dashboard::SOURCE_GROUP,
                requireDefault: true
            );
            if ($result !== null) {
                return $result;
            }
        }

        // Step 3: default-group default.
        $result = $this->findFirstGroupSharedWhere(
            visible: $visible,
            groupId: Dashboard::DEFAULT_GROUP_ID,
            source: Dashboard::SOURCE_DEFAULT,
            requireDefault: true
        );
        if ($result !== null) {
            return $result;
        }

        // Steps 4-5: first group-shared (sortOrder ASC, createdAt ASC).
        if ($groupId !== Dashboard::DEFAULT_GROUP_ID) {
            // Step 4: primary group first.
            $result = $this->findFirstGroupSharedWhere(
                visible: $visible,
                groupId: $groupId,
                source: Dashboard::SOURCE_GROUP,
                requireDefault: false
            );
            if ($result !== null) {
                return $result;
            }
        }

        // Step 5: default-group first.
        $result = $this->findFirstGroupSharedWhere(
            visible: $visible,
            groupId: Dashboard::DEFAULT_GROUP_ID,
            source: Dashboard::SOURCE_DEFAULT,
            requireDefault: false
        );
        if ($result !== null) {
            return $result;
        }

        // Step 6: first personal dashboard.
        foreach ($visible as $entry) {
            if ($entry['source'] === Dashboard::SOURCE_USER) {
                return $entry;
            }
        }

        // Step 7: nothing found.
        return null;
    }//end resolveActiveDashboard()

    /**
     * Persist (or clear) the user's active-dashboard preference.
     *
     * Accepts any non-empty UUID string without performing an existence
     * check — the resolver's stale-pref path handles invalid UUIDs on next
     * read (REQ-DASH-019 "no existence check on write").
     *
     * @param string $userId The user ID.
     * @param string $uuid   The dashboard UUID, or empty string to clear.
     *
     * @return void
     */
    public function setActivePreference(string $userId, string $uuid): void
    {
        if ($uuid === '') {
            $this->config->deleteUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::ACTIVE_DASHBOARD_UUID_PREF_KEY
            );
            return;
        }

        $this->config->setUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::ACTIVE_DASHBOARD_UUID_PREF_KEY,
            value: $uuid
        );
    }//end setActivePreference()

    /**
     * Persist (or clear) the user's EXPLICIT default-dashboard pin
     * (wave3.7).
     *
     * Distinct from {@see self::setActivePreference()} — this pref is
     * only ever written when the user clicks "Set as default" on a
     * row's cog menu (it is NOT auto-overwritten on every switch).
     * The resolver checks it before the active pref so an explicit
     * pin survives across switches.
     *
     * Same write semantics as `setActivePreference`: no existence
     * check on write, the resolver's stale-pref path handles missing
     * UUIDs on next read.
     *
     * @param string $userId The user ID.
     * @param string $uuid   The dashboard UUID, or empty string to clear.
     *
     * @return void
     */
    public function setDefaultPreference(string $userId, string $uuid): void
    {
        if ($uuid === '') {
            $this->config->deleteUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::DEFAULT_DASHBOARD_UUID_PREF_KEY
            );
            return;
        }

        $this->config->setUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::DEFAULT_DASHBOARD_UUID_PREF_KEY,
            value: $uuid
        );
    }//end setDefaultPreference()

    /**
     * Read the user's explicit default-dashboard pin (wave3.7).
     *
     * Returns the empty string when no pin is set. No existence
     * check is performed here — callers should treat the return
     * value as advisory; the resolver's stale-pref path is the
     * authoritative source-of-truth.
     *
     * @param string $userId The user ID.
     *
     * @return string The pinned dashboard UUID, or '' when unset.
     */
    public function getDefaultPreference(string $userId): string
    {
        return (string) $this->config->getUserValue(
            userId: $userId,
            appName: Application::APP_ID,
            key: self::DEFAULT_DASHBOARD_UUID_PREF_KEY,
            default: ''
        );
    }//end getDefaultPreference()

    /**
     * Transition a dashboard to `published` and stamp `publishedAt`
     * the first time it happens. REQ-DASH-032.
     *
     * Idempotent: republishing an already-published dashboard returns
     * the existing entity without altering `publishedAt`. Owner-or-admin
     * gated — non-owner non-admin callers raise `Exception` with the
     * sentinel message {@see self::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN}
     * so the controller can map to HTTP 403.
     *
     * @param string $uuid   The dashboard UUID to publish.
     * @param string $userId The acting user ID.
     *
     * @return Dashboard The updated dashboard entity.
     *
     * @throws DoesNotExistException When the UUID does not exist.
     * @throws Exception             When the actor is neither the owner
     *                               nor a Nextcloud administrator.
     */
    public function publish(string $uuid, string $userId): Dashboard
    {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $userId
        );

        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');

        // Idempotent: already published — no-op other than touching
        // updatedAt is intentionally skipped so audit timestamps stay
        // accurate. Caller still receives the current state.
        if ($dashboard->getPublicationStatus() === Dashboard::STATUS_PUBLISHED) {
            return $dashboard;
        }

        $dashboard->setPublicationStatus(Dashboard::STATUS_PUBLISHED);

        // First-publication timestamp survives unpublish (REQ-DASH-032
        // scenario "Unpublish preserves publishedAt"); only set it when
        // the dashboard has never been published before.
        if ($dashboard->getPublishedAt() === null) {
            $dashboard->setPublishedAt($now);
        }

        // Clear any pending scheduled-publish hint; the dashboard is
        // published immediately and `publishAt` is meaningless now.
        $dashboard->setPublishAt(null);
        $dashboard->setUpdatedAt($now);

        return $this->dashboardMapper->update(entity: $dashboard);
    }//end publish()

    /**
     * Transition a dashboard back to `draft` while preserving
     * `publishedAt` for the audit trail. REQ-DASH-033.
     *
     * Idempotent: unpublishing an already-draft dashboard returns the
     * existing entity unchanged. Owner-or-admin gated.
     *
     * @param string $uuid   The dashboard UUID to unpublish.
     * @param string $userId The acting user ID.
     *
     * @return Dashboard The updated dashboard entity.
     *
     * @throws DoesNotExistException When the UUID does not exist.
     * @throws Exception             When the actor is neither owner nor
     *                               admin.
     */
    public function unpublish(string $uuid, string $userId): Dashboard
    {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $userId
        );

        if ($dashboard->getPublicationStatus() === Dashboard::STATUS_DRAFT) {
            return $dashboard;
        }

        $dashboard->setPublicationStatus(Dashboard::STATUS_DRAFT);
        // REQ-DASH-033: publishedAt is preserved verbatim; publishAt is
        // cleared because the scheduled hint no longer applies once we
        // are explicitly back in draft state.
        $dashboard->setPublishAt(null);
        $dashboard->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

        return $this->dashboardMapper->update(entity: $dashboard);
    }//end unpublish()

    /**
     * Schedule a dashboard for automatic publication at a future
     * timestamp. REQ-DASH-034.
     *
     * Validates that `$publishAt` parses as a valid timestamp strictly
     * greater than `now()`. Past or unparseable values raise
     * `InvalidArgumentException` with the canonical error message
     * {@see self::ERR_SCHEDULE_PAST_DATE} so the controller can map to
     * HTTP 400 with an i18n-translatable copy. Owner-or-admin gated.
     *
     * @param string $uuid      The dashboard UUID to schedule.
     * @param string $publishAt The ISO-8601 timestamp at which the
     *                          dashboard should automatically publish.
     * @param string $userId    The acting user ID.
     *
     * @return Dashboard The updated dashboard entity.
     *
     * @throws DoesNotExistException    When the UUID does not exist.
     * @throws InvalidArgumentException When `publishAt` is missing,
     *                                  unparseable, or in the past.
     * @throws Exception                When the actor is neither owner
     *                                  nor admin.
     */
    public function schedule(
        string $uuid,
        string $publishAt,
        string $userId
    ): Dashboard {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $userId
        );

        $parsed = $this->parseFuturePublishAt(publishAt: $publishAt);

        $dashboard->setPublicationStatus(Dashboard::STATUS_SCHEDULED);
        $dashboard->setPublishAt($parsed);
        $dashboard->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

        return $this->dashboardMapper->update(entity: $dashboard);
    }//end schedule()

    /**
     * Eagerly materialise scheduled dashboards whose `publishAt` is
     * past-due. REQ-DASH-034 (optional eager path).
     *
     * Iterates every row with `publication_status = 'scheduled'` and
     * `publish_at <= now()`, flips it to `'published'`, and stamps
     * `publishedAt = now()`. Lazy materialisation in the visibility
     * filter still runs at read time (correctness guarantee); this
     * method exists only to keep the database row consistent with the
     * effective state for cleaner audit queries.
     *
     * @return int The number of dashboards materialised.
     */
    public function materialiseScheduledDashboards(): int
    {
        $dueRows = $this->dashboardMapper->findDueScheduled();
        if (count($dueRows) === 0) {
            return 0;
        }

        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
        foreach ($dueRows as $dashboard) {
            $dashboard->setPublicationStatus(Dashboard::STATUS_PUBLISHED);
            if ($dashboard->getPublishedAt() === null) {
                $dashboard->setPublishedAt($now);
            }

            $dashboard->setPublishAt(null);
            $dashboard->setUpdatedAt($now);
            $this->dashboardMapper->update(entity: $dashboard);
        }

        return count($dueRows);
    }//end materialiseScheduledDashboards()

    /**
     * Fork any dashboard the user can read into a brand-new personal copy.
     *
     * Implements REQ-DASH-020 / REQ-DASH-021 / REQ-DASH-022:
     *  - source can be ANY dashboard the user can see — personal, group,
     *    or default-group sentinel — resolved via the same visible-to-user
     *    chain as the rest of the multi-scope dashboards code
     *    (REQ-DASH-013). A source the caller cannot see is treated as a
     *    404 ({@see DoesNotExistException}).
     *  - REQ-ASET-003 (extended) gating runs FIRST — when the admin flag
     *    `allow_user_dashboards` is off the call MUST throw
     *    {@see PersonalDashboardsDisabledException} before any DB write.
     *  - the fork is always a `user`-type row owned by `$userId`, with
     *    `groupId = null`, `isDefault = 0`, and `isActive = 1` (every
     *    other personal dashboard owned by the user is deactivated as a
     *    side-effect, mirroring {@see self::createDashboard()}).
     *  - the fork's `gridColumns` is copied from the source.
     *  - all `widget_placements` rows on the source are byte-for-byte
     *    cloned via {@see WidgetPlacementMapper::cloneToDashboard()} —
     *    tile fields, styleConfig, grid coords, sortOrder, customTitle
     *    are all preserved (REQ-DASH-020 scenario "byte-for-byte
     *    clones"). Resource URL fields (e.g. `tileIcon`) reference the
     *    SAME shared resource record (REQ-DASH-022).
     *  - the entire operation runs inside a single
     *    {@see IDBConnection::beginTransaction()} — any failure rolls
     *    back the new dashboard row AND the partial placement clones
     *    (REQ-DASH-021).
     *  - the fork becomes the user's active dashboard (the legacy
     *    `is_active` SMALLINT column on `user`-type rows is the source
     *    of truth for personal-only stacks; the
     *    `active_dashboard_uuid` user-preference is also written so the
     *    REQ-DASH-018 resolution chain returns the fork on next render).
     *
     * @param string      $userId     The acting user.
     * @param string      $sourceUuid The source dashboard UUID.
     * @param string|null $name       Optional explicit name; when null
     *                                or empty the default
     *                                "My copy of {source name}" is
     *                                used (translated via the user's
     *                                active language).
     *
     * @return Dashboard The newly created (and activated) personal
     *                   dashboard entity.
     *
     * @throws PersonalDashboardsDisabledException When the admin flag
     *                                             `allow_user_dashboards`
     *                                             is off — caller maps
     *                                             to HTTP 403 with the
     *                                             stable error code
     *                                             `personal_dashboards_disabled`.
     * @throws DoesNotExistException               When the source UUID
     *                                             does not exist OR the
     *                                             user cannot read it
     *                                             (HTTP 404 — do not
     *                                             leak existence).
     * @throws Throwable                           On any other DB error
     *                                             — the transaction is
     *                                             rolled back before
     *                                             rethrowing
     *                                             (REQ-DASH-021).
     */
    public function forkAsPersonal(
        string $userId,
        string $sourceUuid,
        ?string $name=null
    ): Dashboard {
        // REQ-ASET-003 (extended): gate FIRST so we never persist when
        // personal dashboards are disabled — and so the caller surfaces
        // the stable `personal_dashboards_disabled` envelope no matter
        // what happens with the body.
        $this->assertPersonalDashboardsAllowed();

        // REQ-DASH-020: source must be visible to the user — reuse the
        // visible-to-user resolver so personal / group / default-group
        // sources all resolve through the same indexed-and-deduped path.
        $source = $this->findVisibleDashboardForFork(
            userId: $userId,
            sourceUuid: $sourceUuid
        );

        $resolvedName = $this->resolveForkName(
            userId: $userId,
            requestedName: $name,
            sourceName: (string) $source->getName()
        );

        $this->db->beginTransaction();
        try {
            // REQ-DASH-020: force `isDefault = 0` and `groupId = null`
            // on the fork — the factory is the single source of truth
            // for the (type, groupId) invariant (REQ-DASH-011).
            $fork = $this->dashboardFactory->create(
                userId: $userId,
                name: $resolvedName,
                description: $source->getDescription(),
                type: Dashboard::TYPE_USER,
                groupId: null,
                gridColumns: $source->getGridColumns(),
                permissionLevel: Dashboard::PERMISSION_FULL
            );
            // Defensive — the factory already sets this for TYPE_USER but
            // we make the contract visible at the call site.
            $fork->setIsDefault(0);

            // REQ-DASH-020: deactivate every other personal dashboard
            // for this user before persisting the fork — mirrors
            // {@see self::createDashboard()} so the single-active
            // invariant holds across the transaction.
            $this->dashboardMapper->deactivateAllForUser(userId: $userId);
            $fork->setIsActive(1);

            $persisted = $this->dashboardMapper->insert(entity: $fork);

            // REQ-DASH-020: byte-for-byte placement clone. Any DB error
            // bubbles out of the mapper and the catch below rolls back.
            $this->placementMapper->cloneToDashboard(
                sourceDashboardId: (int) $source->getId(),
                targetDashboardId: (int) $persisted->getId()
            );

            // REQ-DASH-018 / REQ-DASH-019: also pin the active-dashboard
            // user-pref so the resolver returns the fork on the next
            // render even when the personal `is_active` column is not
            // the source of truth (multi-scope deployments).
            $forkUuid = (string) $persisted->getUuid();
            if ($forkUuid !== '') {
                $this->setActivePreference(
                    userId: $userId,
                    uuid: $forkUuid
                );
            }

            $this->db->commit();

            return $persisted;
        } catch (Throwable $t) {
            // REQ-DASH-021: rollback covers the inserted dashboard row
            // AND any partially cloned placements — the catch is wide
            // so we never leak a half-persisted fork on any throwable.
            $this->db->rollBack();
            throw $t;
        }//end try
    }//end forkAsPersonal()

    /**
     * Resolve the source dashboard for a fork via the visible-to-user
     * chain.
     *
     * Personal, group-shared (matching), and default-group sentinel
     * dashboards are all eligible source candidates. A source UUID the
     * caller cannot see MUST be reported as a 404 to avoid leaking
     * existence (REQ-DASH-020 scenario "Cannot fork a dashboard you
     * cannot read").
     *
     * @param string $userId     The acting user.
     * @param string $sourceUuid The source UUID.
     *
     * @return Dashboard The resolved source dashboard entity.
     *
     * @throws DoesNotExistException When the source is not visible.
     */
    private function findVisibleDashboardForFork(
        string $userId,
        string $sourceUuid
    ): Dashboard {
        $visible = $this->getVisibleToUser(userId: $userId);
        foreach ($visible as $entry) {
            $candidate = $entry['dashboard'];
            if ((string) $candidate->getUuid() === $sourceUuid) {
                return $candidate;
            }
        }

        throw new DoesNotExistException(msg: 'Dashboard not found');
    }//end findVisibleDashboardForFork()

    /**
     * Resolve the effective name for a forked dashboard.
     *
     * When the caller supplies a non-empty `$requestedName` we use it
     * verbatim. Otherwise the system applies the localised default
     * `t('My copy of {name}', ['name' => $sourceName])` using the
     * acting user's active language (REQ-DASH-020).
     *
     * @param string      $userId        The acting user (drives the
     *                                   l10n locale).
     * @param string|null $requestedName Caller-supplied name.
     * @param string      $sourceName    The source dashboard's name —
     *                                   substituted into the default
     *                                   pattern via the IL10N
     *                                   placeholder mechanism (NOT
     *                                   string concatenation).
     *
     * @return string The resolved name (always non-empty).
     */
    private function resolveForkName(
        string $userId,
        ?string $requestedName,
        string $sourceName
    ): string {
        $trimmed = trim((string) $requestedName);
        if ($trimmed !== '') {
            return $trimmed;
        }

        $l10n = $this->l10nFactory->get(
            app: Application::APP_ID,
            lang: $this->config->getUserValue(
                userId: $userId,
                appName: 'core',
                key: 'lang',
                default: ''
            )
        );

        // IL10N::t uses positional `%s` placeholders (vsprintf under
        // the hood) — the cross-cutting JS / Python pipelines use
        // `{name}` curly placeholders, but the PHP boundary stays on
        // the standard sprintf substitution mechanism.
        return $l10n->t('My copy of %s', [$sourceName]);
    }//end resolveForkName()

    /**
     * Check whether the given user is a Nextcloud administrator.
     *
     * Wraps `IGroupManager::isAdmin()` so callers don't have to import
     * the interface and so tests can stub one method.
     *
     * @param string $userId The user ID.
     *
     * @return bool Whether the user is an admin.
     */
    public function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin(userId: $userId);
    }//end isAdmin()

    /**
     * Read the admin `allow_user_dashboards` flag without throwing.
     *
     * Use this when callers need a plain boolean (e.g. to render the UI
     * affordance or to push the flag into the initial-state contract);
     * use {@see self::assertPersonalDashboardsAllowed()} when the call
     * site needs the request to be rejected with a 403 envelope.
     *
     * The default is `false` (admins must opt in) — this is the secure
     * default mandated by REQ-ASET-003: when the row is missing, personal
     * dashboard creation MUST be blocked.
     *
     * @return bool Whether personal dashboard creation is currently
     *              permitted by admin settings.
     *
     * @SuppressWarnings(PHPMD.BooleanGetMethodName) Intentional: the proposal
     *  pins the public name to `getAllowUserDashboards()` so it mirrors the
     *  initial-state key (`allowUserDashboards`) and the
     *  setting key constant (`AdminSetting::KEY_ALLOW_USER_DASHBOARDS`).
     *  Renaming to `isAllowUserDashboards()` would break the symmetry the
     *  spec relies on.
     */
    public function getAllowUserDashboards(): bool
    {
        return (bool) $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
            default: false
        );
    }//end getAllowUserDashboards()

    /**
     * Assert that personal-dashboard creation is permitted by admin settings.
     *
     * Implements REQ-ASET-003 runtime gating: when the admin flag
     * `allow_user_dashboards` is `false` (or absent — default is `false`),
     * creation of `type='user'` dashboards MUST be blocked at the service
     * boundary. Read / update / delete operations on existing personal
     * dashboards MUST NOT call this method.
     *
     * @return void
     *
     * @throws PersonalDashboardsDisabledException When the flag is off.
     */
    public function assertPersonalDashboardsAllowed(): void
    {
        if ($this->getAllowUserDashboards() === false) {
            throw new PersonalDashboardsDisabledException();
        }
    }//end assertPersonalDashboardsAllowed()

    /**
     * Scan the pre-fetched visible list for the first group-shared dashboard
     * matching a given `groupId`, optionally filtered to `isDefault = 1`.
     *
     * The visible list preserves mapper order (sortOrder ASC, createdAt ASC
     * for group-shared rows via {@see DashboardMapper::findByGroup}), so the
     * "first" result is already correctly ordered without a secondary sort
     * here.
     *
     * @param array<int, array{dashboard: Dashboard, source: string}> $visible        The full visible-to-user list.
     * @param string                                                  $groupId        The group ID to filter on.
     * @param string                                                  $source         Expected source tag
     *                                                                                (`'group'` or
     *                                                                                `'default'`).
     * @param bool                                                    $requireDefault When true, only rows with
     *                                                                                `isDefault = 1` are
     *                                                                                considered.
     *
     * @return array{dashboard: Dashboard, source: string}|null
     */
    private function findFirstGroupSharedWhere(
        array $visible,
        string $groupId,
        string $source,
        bool $requireDefault
    ): ?array {
        foreach ($visible as $entry) {
            if ($entry['source'] !== $source) {
                continue;
            }

            $dashboard = $entry['dashboard'];
            if ($dashboard->getType() !== Dashboard::TYPE_GROUP_SHARED) {
                continue;
            }

            if ($dashboard->getGroupId() !== $groupId) {
                continue;
            }

            if ($requireDefault === true
                && (int) $dashboard->getIsDefault() !== 1
            ) {
                continue;
            }

            return $entry;
        }//end foreach

        return null;
    }//end findFirstGroupSharedWhere()

    /**
     * Try to create a dashboard from a template or empty.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The dashboard result or null.
     */
    private function tryCreateFromTemplate(string $userId): ?array
    {
        $allowUserDashboards = $this->getAllowUserDashboards();

        $template = $this->templateService->getApplicableTemplate(
            userId: $userId
        );

        if ($template !== null) {
            return $this->dashResolver->handleTemplateResult(
                template: $template,
                allowUserDashboards: $allowUserDashboards,
                userId: $userId
            );
        }

        if ($allowUserDashboards === true) {
            $dashboard  = $this->createDashboard(
                userId: $userId,
                name: 'My Dashboard'
            );
            $placements = $this->createDefaultPlacements(
                dashboardId: $dashboard->getId()
            );
            return [
                'dashboard'       => $dashboard,
                'placements'      => $placements,
                'permissionLevel' => Dashboard::PERMISSION_FULL,
            ];
        }

        return null;
    }//end tryCreateFromTemplate()

    /**
     * Create default widget placements for a new dashboard.
     *
     * Adds the same widgets shown on the standard Nextcloud dashboard:
     * recommendations (recent files) and activity.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return WidgetPlacement[] The created placements.
     */
    private function createDefaultPlacements(int $dashboardId): array
    {
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');

        $defaults = [
            [
                'widgetId'   => 'recommendations',
                'gridX'      => 0,
                'gridY'      => 0,
                'gridWidth'  => 6,
                'gridHeight' => 5,
                'sortOrder'  => 0,
            ],
            [
                'widgetId'   => 'activity',
                'gridX'      => 6,
                'gridY'      => 0,
                'gridWidth'  => 6,
                'gridHeight' => 5,
                'sortOrder'  => 1,
            ],
        ];

        $placements = [];
        foreach ($defaults as $config) {
            $placement = new WidgetPlacement();
            $placement->setDashboardId($dashboardId);
            $placement->setWidgetId($config['widgetId']);
            $placement->setGridX($config['gridX']);
            $placement->setGridY($config['gridY']);
            $placement->setGridWidth($config['gridWidth']);
            $placement->setGridHeight($config['gridHeight']);
            $placement->setSortOrder($config['sortOrder']);
            $placement->setShowTitle(1);
            $placement->setIsVisible(1);
            $placement->setCreatedAt($now);
            $placement->setUpdatedAt($now);

            $placements[] = $this->placementMapper->insert(entity: $placement);
        }//end foreach

        return $placements;
    }//end createDefaultPlacements()

    /**
     * Apply updates to a dashboard entity.
     *
     * @param Dashboard $dashboard The dashboard.
     * @param array     $data      The update data.
     *
     * @return void
     */
    private function applyDashboardUpdates(
        Dashboard $dashboard,
        array $data
    ): void {
        if (isset($data['name']) === true) {
            $dashboard->setName($data['name']);
        }

        if (isset($data['description']) === true) {
            $dashboard->setDescription($data['description']);
        }

        // Icon may be NULL/empty (use default), a registry key, or a URL.
        // The discriminator + lookup live frontend-side in the
        // `dashboard-icons` capability — we just store the opaque string.
        if (array_key_exists(key: 'icon', array: $data) === true) {
            $iconValue   = $data['icon'];
            $iconToStore = null;
            if (is_string($iconValue) === true) {
                $iconToStore = $iconValue;
            }

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setIcon($iconToStore);
        }

        if (isset($data['gridColumns']) === true) {
            $dashboard->setGridColumns($data['gridColumns']);
        }

        $this->applyTreeUpdates(dashboard: $dashboard, data: $data);
        $this->applyFooterUpdates(dashboard: $dashboard, data: $data);

        $dashboard->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

        if (isset($data['placements']) === true
            && is_array($data['placements']) === true
        ) {
            $this->placementMapper->updatePositions(
                updates: $data['placements']
            );
        }
    }//end applyDashboardUpdates()

    /**
     * Apply per-dashboard footer override updates (REQ-FTR-006).
     *
     * Mode + HTML are decoupled — callers can patch either independently.
     * Validates the mode against {@see Dashboard::FOOTER_MODES}, sanitises
     * the HTML through {@see FooterService::sanitiseHtml()} when mode is
     * `custom`, and clears the HTML to NULL when mode flips away from
     * `custom` (REQ-FTR-006 mode-change scenario). When mode is `custom`
     * but no HTML is supplied (and the dashboard has no stored HTML
     * either), throws so the controller can return HTTP 400.
     *
     * @param Dashboard $dashboard The dashboard being updated.
     * @param array     $data      The patch payload.
     *
     * @return void
     *
     * @throws InvalidArgumentException When mode is invalid or `custom`
     *                                  is requested without HTML.
     */
    private function applyFooterUpdates(
        Dashboard $dashboard,
        array $data
    ): void {
        $modeProvided = array_key_exists(key: 'dashboardFooterMode', array: $data);
        $htmlProvided = array_key_exists(key: 'dashboardFooterHtml', array: $data);

        if ($modeProvided === false && $htmlProvided === false) {
            return;
        }

        if ($modeProvided === true) {
            $newMode = $data['dashboardFooterMode'];
        } else {
            $newMode = $dashboard->getDashboardFooterMode();
        }

        if ($newMode === null || $newMode === '') {
            $newMode = Dashboard::FOOTER_MODE_INHERIT;
        }

        if (is_string($newMode) === false
            || in_array(needle: $newMode, haystack: Dashboard::FOOTER_MODES, strict: true) === false
        ) {
            throw new InvalidArgumentException(
                message: 'dashboardFooterMode must be one of: '.implode(separator: ', ', array: Dashboard::FOOTER_MODES)
            );
        }

        if ($newMode === Dashboard::FOOTER_MODE_CUSTOM) {
            if ($htmlProvided === true) {
                $rawHtml = $data['dashboardFooterHtml'];
            } else {
                $rawHtml = $dashboard->getDashboardFooterHtml();
            }

            if ($rawHtml === null || is_string($rawHtml) === false || trim(string: $rawHtml) === '') {
                throw new InvalidArgumentException(
                    message: 'dashboardFooterHtml is required when dashboardFooterMode=custom'
                );
            }

            $sanitised = $this->footerService->sanitiseHtml(html: $rawHtml);
            $dashboard->setDashboardFooterMode(Dashboard::FOOTER_MODE_CUSTOM);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setDashboardFooterHtml($sanitised);
            return;
        }

        // Inherit / hidden — clear stale HTML to keep the invariant.
        $dashboard->setDashboardFooterMode($newMode);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setDashboardFooterHtml(null);
    }//end applyFooterUpdates()

    /**
     * Resolve the effective footer payload for a dashboard
     * (REQ-FTR-006 — see {@see FooterService::resolveFooterForDashboard()}).
     *
     * Thin pass-through so callers (controllers, listeners, tests) can
     * stay on the `DashboardService` surface.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return array|null The effective footer payload, or NULL.
     */
    public function resolveFooterForDashboard(Dashboard $dashboard): ?array
    {
        return $this->footerService->resolveFooterForDashboard(dashboard: $dashboard);
    }//end resolveFooterForDashboard()

    /**
     * Apply hierarchy updates (parentUuid, slug, sortOrder) with the
     * REQ-DASH-023..029 guards.
     *
     * The three keys are decoupled — callers can patch any subset:
     *  - `parentUuid` (NULL or string) — re-parents the dashboard, runs
     *    cycle and depth checks via {@see DashboardTreeService}.
     *  - `slug` (string) — updates the slug, runs the per-parent
     *    uniqueness guard against the resolved parent (post-update).
     *  - `sortOrder` (int) — updates the sibling sort order verbatim.
     *
     * Slug uniqueness is rechecked against the dashboard's parent AFTER
     * any pending `parentUuid` change so a single PUT can re-parent and
     * rename in one atomic patch.
     *
     * @param Dashboard $dashboard The dashboard being updated.
     * @param array     $data      The update payload.
     *
     * @return void
     */
    private function applyTreeUpdates(
        Dashboard $dashboard,
        array $data
    ): void {
        $movingUuid = $dashboard->getUuid();

        // Track the parent the slug uniqueness check should fire against.
        $effectiveParent = $dashboard->getParentUuid();

        if (array_key_exists(key: 'parentUuid', array: $data) === true) {
            $newParent = $data['parentUuid'];
            if ($newParent !== null && is_string($newParent) === false) {
                $newParent = (string) $newParent;
            }

            // REQ-DASH-028: cycle + depth before applying.
            $this->treeService->validateParent(
                movingUuid: $movingUuid,
                newParentUuid: $newParent
            );

            $dashboard->setParentUuid($newParent);
            $effectiveParent = $newParent;
        }

        if (array_key_exists(key: 'slug', array: $data) === true) {
            $newSlug = $data['slug'];
            if ($newSlug !== null && is_string($newSlug) === false) {
                $newSlug = (string) $newSlug;
            }

            if ($newSlug !== null && $newSlug !== '') {
                if (SlugGenerator::isValid(slug: $newSlug) === false) {
                    throw new InvalidArgumentException(
                        message: 'Slug must match [a-z0-9_-]+ and be at most 128 characters'
                    );
                }

                // REQ-DASH-024: per-parent uniqueness — exclude the row
                // being updated so no-op writes succeed.
                $this->treeService->validateSlugUnique(
                    parentUuid: $effectiveParent,
                    slug: $newSlug,
                    excludeUuid: $movingUuid
                );
            }

            $dashboard->setSlug($newSlug);
        }//end if

        if (array_key_exists(key: 'sortOrder', array: $data) === true) {
            $sortOrder = $data['sortOrder'];
            $dashboard->setSortOrder((int) $sortOrder);
        }
    }//end applyTreeUpdates()

    /**
     * Assert the actor is allowed to mutate the dashboard's publication
     * state. REQ-DASH-031..034.
     *
     * Owner-or-admin is the gate: the dashboard's `userId` must match
     * the actor, OR the actor must be a Nextcloud admin. Group-shared
     * and admin-template dashboards (which have `userId = null`) fall
     * back to the admin-only check.
     *
     * @param Dashboard $dashboard   The dashboard being mutated.
     * @param string    $actorUserId The acting user ID.
     *
     * @return void
     *
     * @throws Exception When the actor is neither owner nor admin.
     */
    private function assertOwnerOrAdmin(
        Dashboard $dashboard,
        string $actorUserId
    ): void {
        $ownerId = $dashboard->getUserId();
        if ($ownerId !== null && $ownerId === $actorUserId) {
            return;
        }

        if ($this->isAdmin(userId: $actorUserId) === true) {
            return;
        }

        throw new Exception(
            message: self::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
        );
    }//end assertOwnerOrAdmin()

    /**
     * Parse and validate a `publishAt` argument for the schedule action.
     * REQ-DASH-034.
     *
     * Accepts any string `DateTime::__construct` understands (ISO-8601
     * recommended). The parsed value is normalised to the canonical
     * `Y-m-d H:i:s` storage format used elsewhere on the entity.
     *
     * @param string $publishAt The caller-supplied publish-at string.
     *
     * @return string The normalised storage timestamp.
     *
     * @throws InvalidArgumentException When the string is empty,
     *                                  unparseable, or not strictly in
     *                                  the future.
     */
    private function parseFuturePublishAt(string $publishAt): string
    {
        $trimmed = trim($publishAt);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                message: self::ERR_SCHEDULE_PAST_DATE
            );
        }

        try {
            $parsed = new DateTime($trimmed);
        } catch (Exception) {
            throw new InvalidArgumentException(
                message: self::ERR_SCHEDULE_PAST_DATE
            );
        }

        $now = new DateTime();
        if ($parsed <= $now) {
            throw new InvalidArgumentException(
                message: self::ERR_SCHEDULE_PAST_DATE
            );
        }

        return $parsed->format(format: 'Y-m-d H:i:s');
    }//end parseFuturePublishAt()
}//end class
