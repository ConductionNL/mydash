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
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service for managing dashboards.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
        $dashboard->setIsActive(true);

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
     * Try to create a dashboard from a template or empty.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The dashboard result or null.
     */
    private function tryCreateFromTemplate(string $userId): ?array
    {
        $allowUserDashboards = $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
            default: true
        );

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
