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

/**
 * Service for resolving dashboard permissions across personal,
 * shared, and group-shared scopes (REQ-DASH-014).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Twelve methods cover the
 *                                                three scopes' permission
 *                                                checks without splitting.
 */
class PermissionService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper      Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper      Widget placement mapper.
     * @param AdminSettingMapper    $settingMapper        Admin setting mapper.
     * @param DashboardShareService $shareService         Share resolution service.
     * @param IGroupManager         $groupManager         Group manager for the
     *                                                    `isAdmin` check (group
     *                                                    membership lookups go
     *                                                    through the routing
     *                                                    resolver — REQ-TMPL-013).
     * @param AdminTemplateService  $adminTemplateService Routing resolver — single
     *                                                    source of truth for
     *                                                    `IGroupManager::getUserGroupIds`
     *                                                    (REQ-TMPL-013).
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly DashboardShareService $shareService,
        private readonly IGroupManager $groupManager,
        private readonly AdminTemplateService $adminTemplateService,
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
        // REQ-ASET-003 (extended): default `false` — when no row exists,
        // personal dashboard creation MUST be blocked. Defense-in-depth
        // companion to DashboardService::assertPersonalDashboardsAllowed().
        return (bool) $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
            default: false
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
     * For `group_shared` dashboards (REQ-DASH-014): the resolved level is
     * always `view_only` for non-admin members and `full` for admins —
     * the row's own `permissionLevel` field is intentionally ignored so
     * the read-only-for-members rule lives in one place. Pass `$userId`
     * to enable the admin override; omit it to keep the legacy "ignore
     * sharing, return record level" behaviour.
     *
     * @param Dashboard   $dashboard The dashboard.
     * @param string|null $userId    The acting user (enables the
     *                               group-shared admin override). Pass
     *                               null to fall back to the record's
     *                               own permission level.
     *
     * @return string The effective permission level.
     */
    public function getEffectivePermissionLevel(
        Dashboard $dashboard,
        ?string $userId=null
    ): string {
        // REQ-DASH-014: group-shared dashboards are read-only for
        // non-admin members and fully editable for admins, regardless of
        // the row's persisted `permissionLevel` field (which is kept on
        // the row for forward-compat with future per-tile editing).
        if ($dashboard->getType() === Dashboard::TYPE_GROUP_SHARED) {
            if ($userId !== null
                && $this->groupManager->isAdmin(userId: $userId) === true
            ) {
                return Dashboard::PERMISSION_FULL;
            }

            return Dashboard::PERMISSION_VIEW_ONLY;
        }

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

        // Group-shared dashboards bypass the ownership-vs-share path:
        // visibility is by group membership, not by per-row sharing.
        // REQ-DASH-014.
        if ($dashboard->getType() === Dashboard::TYPE_GROUP_SHARED) {
            $groupId = (string) $dashboard->getGroupId();
            if ($groupId === Dashboard::DEFAULT_GROUP_ID) {
                return $this->getEffectivePermissionLevel(
                    dashboard: $dashboard,
                    userId: $userId
                );
            }

            $userGroupIds = $this->adminTemplateService->getUserGroupIdsFor(
                userId: $userId
            );
            if (in_array(needle: $groupId, haystack: $userGroupIds, strict: true) === true
                || $this->groupManager->isAdmin(userId: $userId) === true
            ) {
                return $this->getEffectivePermissionLevel(
                    dashboard: $dashboard,
                    userId: $userId
                );
            }//end if

            return null;
        }//end if

        if ($dashboard->getUserId() === $userId) {
            return $this->getEffectivePermissionLevel(
                dashboard: $dashboard,
                userId: $userId
            );
        }

        $shares = $this->shareService->resolveSharedDashboards(
            userId: $userId,
            groupIds: $this->adminTemplateService->getUserGroupIdsFor(
                userId: $userId
            )
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
}//end class
