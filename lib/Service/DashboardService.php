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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
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
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper  Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper  Widget placement mapper.
     * @param AdminSettingMapper    $settingMapper    Admin setting mapper.
     * @param TemplateService       $templateService  Template service.
     * @param DashboardFactory      $dashboardFactory Dashboard factory.
     * @param DashboardResolver     $dashResolver     Dashboard resolver.
     * @param IGroupManager         $groupManager     Group manager.
     * @param IUserManager          $userManager      User manager.
     * @param IDBConnection         $db               DB connection (for the
     *                                                transactional default
     *                                                flip — REQ-DASH-015).
     * @param IConfig               $config           Nextcloud per-user
     *                                                preference storage.
     * @param IFactory              $l10nFactory      L10N factory used to
     *                                                build the
     *                                                "My copy of {name}"
     *                                                default fork name
     *                                                (REQ-DASH-020).
     * @param LoggerInterface       $logger           PSR logger.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly TemplateService $templateService,
        private readonly DashboardFactory $dashboardFactory,
        private readonly DashboardResolver $dashResolver,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly IFactory $l10nFactory,
        private readonly LoggerInterface $logger,
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
     * Get the effective dashboard for a user.
     * Returns user's active dashboard or applicable admin template.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The effective dashboard data or null.
     */
    public function getEffectiveDashboard(string $userId): ?array
    {
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
     * @param string      $userId      The user ID.
     * @param string      $name        The dashboard name.
     * @param string|null $description The dashboard description.
     *
     * @return Dashboard The created dashboard.
     */
    public function createDashboard(
        string $userId,
        string $name,
        ?string $description=null
    ): Dashboard {
        $dashboard = $this->dashboardFactory->create(
            userId: $userId,
            name: $name,
            description: $description
        );

        $this->dashboardMapper->deactivateAllForUser(userId: $userId);

        return $this->dashboardMapper->insert(entity: $dashboard);
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
     * Delete a dashboard.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     *
     * @return void
     */
    public function deleteDashboard(int $dashboardId, string $userId): void
    {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        $this->placementMapper->deleteByDashboardId(
            dashboardId: $dashboardId
        );
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
     * Wires `IGroupManager::getUserGroupIds()` into the mapper's union
     * query and returns the deduplicated list with `source` set per row.
     * REQ-DASH-013.
     *
     * @param string $userId The user ID.
     *
     * @return array<int, array{dashboard: Dashboard, source: string}>
     *   List of {dashboard, source} pairs.
     */
    public function getVisibleToUser(string $userId): array
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return [];
        }

        $userGroupIds = $this->groupManager->getUserGroupIds(user: $user);

        return $this->dashboardMapper->findVisibleToUser(
            userId: $userId,
            userGroupIds: $userGroupIds
        );
    }//end getVisibleToUser()

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

        if (isset($data['gridColumns']) === true) {
            $dashboard->setGridColumns($data['gridColumns']);
        }

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
}//end class
