<?php

/**
 * DashboardResolver
 *
 * Service for resolving the effective dashboard for a user.
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

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for resolving the effective dashboard for a user.
 */
class DashboardResolver
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param AdminSettingMapper    $settingMapper   Admin setting mapper.
     * @param TemplateService       $templateService Template service.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly TemplateService $templateService,
    ) {
    }//end __construct()

    /**
     * Try to get the user's active dashboard.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The dashboard result or null.
     */
    public function tryGetActiveDashboard(string $userId): ?array
    {
        try {
            $dashboard  = $this->dashboardMapper->findActiveByUserId(
                $userId
            );
            $placements = $this->placementMapper->findByDashboardId(
                $dashboard->getId()
            );

            return $this->buildResult(
                dashboard: $dashboard,
                placements: $placements
            );
        } catch (DoesNotExistException) {
            return null;
        }
    }//end tryGetActiveDashboard()

    /**
     * Try to activate an existing user dashboard.
     *
     * @param string $userId The user ID.
     *
     * @return array|null The dashboard result or null.
     */
    public function tryActivateExistingDashboard(string $userId): ?array
    {
        $userDashboards = $this->dashboardMapper->findByUserId(
            $userId
        );
        if (empty($userDashboards) === true) {
            return null;
        }

        $dashboard = $userDashboards[0];
        $this->dashboardMapper->setActive(
            dashboardId: $dashboard->getId(),
            userId: $userId
        );
        $dashboard->setIsActive(1);

        $placements = $this->placementMapper->findByDashboardId(
            $dashboard->getId()
        );

        return $this->buildResult(
            dashboard: $dashboard,
            placements: $placements
        );
    }//end tryActivateExistingDashboard()

    /**
     * Handle a template-based dashboard result.
     *
     * @param Dashboard $template            The template.
     * @param bool      $allowUserDashboards Whether user dashboards allowed.
     * @param string    $userId              The user ID.
     *
     * @return array The dashboard result.
     */
    public function handleTemplateResult(
        Dashboard $template,
        bool $allowUserDashboards,
        string $userId
    ): array {
        if ($allowUserDashboards === true) {
            $dashboard  = $this->templateService->createDashboardFromTemplate(
                userId: $userId,
                template: $template
            );
            $placements = $this->placementMapper->findByDashboardId(
                $dashboard->getId()
            );

            return $this->buildResult(
                dashboard: $dashboard,
                placements: $placements
            );
        }

        $placements = $this->placementMapper->findByDashboardId(
            $template->getId()
        );
        return [
            'dashboard'       => $template,
            'placements'      => $placements,
            'permissionLevel' => Dashboard::PERMISSION_VIEW_ONLY,
        ];
    }//end handleTemplateResult()

    /**
     * Build a standard dashboard result array.
     *
     * @param Dashboard $dashboard  The dashboard.
     * @param array     $placements The placements.
     *
     * @return array The result array.
     */
    public function buildResult(
        Dashboard $dashboard,
        array $placements
    ): array {
        $permissionLevel = $this->getEffectivePermissionLevel(
            $dashboard
        );

        return [
            'dashboard'       => $dashboard,
            'placements'      => $placements,
            'permissionLevel' => $permissionLevel,
        ];
    }//end buildResult()

    /**
     * Get the effective permission level for a dashboard.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The effective permission level.
     */
    public function getEffectivePermissionLevel(
        Dashboard $dashboard
    ): string {
        if ($dashboard->getBasedOnTemplate() !== null) {
            try {
                $template = $this->dashboardMapper->find(
                    $dashboard->getBasedOnTemplate()
                );
                return $template->getPermissionLevel();
            } catch (DoesNotExistException) {
                // Template was deleted, use full permissions.
            }
        }

        $level = $dashboard->getPermissionLevel();
        if (empty($level) === false) {
            return $level;
        }

        $default = $this->settingMapper->getValue(
            AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL,
            Dashboard::PERMISSION_FULL
        );

        if (is_string($default) === true) {
            return $default;
        }

        return Dashboard::PERMISSION_FULL;
    }//end getEffectivePermissionLevel()
}//end class
