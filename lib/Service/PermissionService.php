<?php

/**
 * PermissionService
 *
 * Service for managing dashboard permissions.
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

use Exception;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\AdminSetting;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

class PermissionService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param AdminSettingMapper    $settingMapper   Admin setting mapper.
     * @param DashboardShareService $shareService    Share resolution service.
     * @param IGroupManager         $groupManager    Group manager for resolving recipient groups.
     * @param IUserManager          $userManager     User manager (used by share resolution).
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly DashboardShareService $shareService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Whether the user can see a dashboard at all (owner OR has any share).
     *
     * @param string $userId      The acting user ID.
     * @param int    $dashboardId The dashboard ID.
     *
     * @return bool True when the dashboard is visible to the user.
     */
    public function canViewDashboard(string $userId, int $dashboardId): bool
    {
        return $this->resolveAccessLevel(userId: $userId, dashboardId: $dashboardId) !== null;
    }//end canViewDashboard()

    /**
     * Check if user can edit a dashboard (widgets, tiles, layout).
     *
     * @param string $userId      The user ID.
     * @param int    $dashboardId The dashboard ID.
     *
     * @return bool Whether the user can edit the dashboard.
     */
    public function canEditDashboard(string $userId, int $dashboardId): bool
    {
        try {
            $dashboard = $this->dashboardMapper->find(id: $dashboardId);
        } catch (DoesNotExistException) {
            return false;
        }

        // Admin templates can only be edited by admins.
        if ($dashboard->getType() === Dashboard::TYPE_ADMIN_TEMPLATE) {
            return false;
        }

        $level = $this->resolveAccessLevel(userId: $userId, dashboard: $dashboard);
        if ($level === null) {
            return false;
        }

        return in_array(
            needle: $level,
            haystack: [
                Dashboard::PERMISSION_ADD_ONLY,
                Dashboard::PERMISSION_FULL,
            ]
        );
    }//end canEditDashboard()

    /**
     * Check if user can edit dashboard metadata (name, description). Owner only.
     *
     * @param string $userId      The user ID.
     * @param int    $dashboardId The dashboard ID.
     *
     * @return bool Whether the user can edit the dashboard metadata.
     */
    public function canEditDashboardMetadata(
        string $userId,
        int $dashboardId
    ): bool {
        try {
            $dashboard = $this->dashboardMapper->find(id: $dashboardId);
        } catch (DoesNotExistException) {
            return false;
        }

        // Admin templates can only be edited by admins.
        if ($dashboard->getType() === Dashboard::TYPE_ADMIN_TEMPLATE) {
            return false;
        }

        // Only owner can rename / change description.
        return $dashboard->getUserId() === $userId;
    }//end canEditDashboardMetadata()

    /**
     * Check if user can add widgets to a dashboard.
     *
     * @param string $userId      The user ID.
     * @param int    $dashboardId The dashboard ID.
     *
     * @return bool Whether the user can add widgets.
     */
    public function canAddWidget(string $userId, int $dashboardId): bool
    {
        $level = $this->resolveAccessLevel(userId: $userId, dashboardId: $dashboardId);
        if ($level === null) {
            return false;
        }

        return in_array(
            needle: $level,
            haystack: [
                Dashboard::PERMISSION_ADD_ONLY,
                Dashboard::PERMISSION_FULL,
            ]
        );
    }//end canAddWidget()

    /**
     * Check if user can remove a widget.
     *
     * @param string $userId      The user ID.
     * @param int    $placementId The placement ID.
     *
     * @return bool Whether the user can remove the widget.
     */
    public function canRemoveWidget(string $userId, int $placementId): bool
    {
        try {
            $placement = $this->placementMapper->find(id: $placementId);
            $dashboard = $this->dashboardMapper->find(
                id: $placement->getDashboardId()
            );
        } catch (DoesNotExistException) {
            return false;
        }

        $level = $this->resolveAccessLevel(userId: $userId, dashboard: $dashboard);
        if ($level === null) {
            return false;
        }

        if ($level === Dashboard::PERMISSION_VIEW_ONLY) {
            return false;
        }

        if ($level === Dashboard::PERMISSION_FULL) {
            return true;
        }

        if ($level === Dashboard::PERMISSION_ADD_ONLY) {
            return $placement->getIsCompulsory() === 0;
        }

        return false;
    }//end canRemoveWidget()

    /**
     * Check if user can style a widget.
     *
     * @param string $userId      The user ID.
     * @param int    $placementId The placement ID.
     *
     * @return bool Whether the user can style the widget.
     */
    public function canStyleWidget(string $userId, int $placementId): bool
    {
        try {
            $placement = $this->placementMapper->find(id: $placementId);
            $dashboard = $this->dashboardMapper->find(
                id: $placement->getDashboardId()
            );
        } catch (DoesNotExistException) {
            return false;
        }

        $level = $this->resolveAccessLevel(userId: $userId, dashboard: $dashboard);
        if ($level === null) {
            return false;
        }

        return in_array(
            needle: $level,
            haystack: [
                Dashboard::PERMISSION_ADD_ONLY,
                Dashboard::PERMISSION_FULL,
            ]
        );
    }//end canStyleWidget()

    /**
     * Check if user can create dashboards.
     *
     * @param string $userId The user ID.
     *
     * @return bool Whether the user can create dashboards.
     */
    public function canCreateDashboard(string $userId): bool
    {
        return $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
            default: true
        );
    }//end canCreateDashboard()

    /**
     * Check if user can have multiple dashboards.
     *
     * @param string $userId The user ID.
     *
     * @return bool Whether the user can have multiple dashboards.
     */
    public function canHaveMultipleDashboards(string $userId): bool
    {
        return $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS,
            default: true
        );
    }//end canHaveMultipleDashboards()

    /**
     * Get the effective permission level for a dashboard, ignoring sharing.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The effective permission level.
     */
    public function getEffectivePermissionLevel(Dashboard $dashboard): string
    {
        // If based on a template, use template's permission level.
        if ($dashboard->getBasedOnTemplate() !== null) {
            try {
                $template = $this->dashboardMapper->find(
                    id: $dashboard->getBasedOnTemplate()
                );
                return $template->getPermissionLevel();
            } catch (DoesNotExistException) {
                // Template deleted, use dashboard's level.
            }
        }

        // Use dashboard's permission level or default.
        $level = $dashboard->getPermissionLevel();
        if (empty($level) === false) {
            return $level;
        }

        return $this->settingMapper->getValue(
            key: AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL,
            default: Dashboard::PERMISSION_FULL
        );
    }//end getEffectivePermissionLevel()

    /**
     * Resolve the effective permission level a user has on a dashboard:
     *  - If the user is the owner, returns the dashboard's effective level.
     *  - If a share applies (direct or via group), returns that share's level.
     *  - Otherwise returns null (no access).
     *
     * Pass either a dashboard id or an already-loaded dashboard entity.
     *
     * @param string         $userId      The user id.
     * @param int|null       $dashboardId The dashboard id (optional if $dashboard given).
     * @param Dashboard|null $dashboard   The dashboard entity (optional if $dashboardId given).
     *
     * @return string|null The permission level or null when no access.
     */
    public function resolveAccessLevel(
        string $userId,
        ?int $dashboardId=null,
        ?Dashboard $dashboard=null
    ): ?string {
        if ($dashboard === null) {
            try {
                $dashboard = $this->dashboardMapper->find(id: $dashboardId);
            } catch (DoesNotExistException) {
                return null;
            }
        }

        if ($dashboard->getUserId() === $userId) {
            return $this->getEffectivePermissionLevel(dashboard: $dashboard);
        }

        $shares = $this->shareService->resolveSharedDashboards(
            userId: $userId,
            groupIds: $this->getUserGroupIds(userId: $userId)
        );

        return $shares[$dashboard->getId()] ?? null;
    }//end resolveAccessLevel()

    /**
     * Verify user owns a dashboard.
     *
     * @param string $userId      The user ID.
     * @param int    $dashboardId The dashboard ID.
     *
     * @return Dashboard The verified dashboard.
     *
     * @throws \Exception If access is denied.
     */
    public function verifyDashboardOwnership(
        string $userId,
        int $dashboardId
    ): Dashboard {
        $dashboard = $this->dashboardMapper->find(id: $dashboardId);

        if ($dashboard->getUserId() !== $userId) {
            throw new Exception(message: 'Access denied');
        }

        return $dashboard;
    }//end verifyDashboardOwnership()

    /**
     * Verify user owns a placement's dashboard.
     *
     * @param string $userId      The user ID.
     * @param int    $placementId The placement ID.
     *
     * @return WidgetPlacement The verified placement.
     *
     * @throws \Exception If access is denied.
     */
    public function verifyPlacementOwnership(
        string $userId,
        int $placementId
    ): WidgetPlacement {
        $placement = $this->placementMapper->find(id: $placementId);
        $this->verifyDashboardOwnership(
            userId: $userId,
            dashboardId: $placement->getDashboardId()
        );

        return $placement;
    }//end verifyPlacementOwnership()

    /**
     * Resolve the group ids a user belongs to.
     *
     * @param string $userId The user id.
     *
     * @return string[] Group ids.
     */
    public function getUserGroupIds(string $userId): array
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return [];
        }

        return $this->groupManager->getUserGroupIds(user: $user);
    }//end getUserGroupIds()
}//end class
