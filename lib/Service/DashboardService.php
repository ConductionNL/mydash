<?php

/**
 * DashboardService
 *
 * Service for managing dashboards.
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
use OCA\MyDash\Db\WidgetPlacementMapper;

/**
 * Service for managing dashboards.
 */
class DashboardService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper  Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper  Widget placement mapper.
     * @param AdminSettingMapper    $settingMapper    Admin setting mapper.
     * @param TemplateService       $templateService  Template service.
     * @param DashboardFactory      $dashboardFactory Dashboard factory.
     * @param DashboardResolver     $dashResolver     Dashboard resolver.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingMapper $settingMapper,
        private readonly TemplateService $templateService,
        private readonly DashboardFactory $dashboardFactory,
        private readonly DashboardResolver $dashResolver,
    ) {
    }//end __construct()

    /**
     * Get all dashboards for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard[] The list of dashboards.
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
            dashboardId: $dashboardId,
            userId: $userId
        );
        $dashboard->setIsActive(isActive: true);

        return $dashboard;
    }//end activateDashboard()

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
            $dashboard = $this->createDashboard(
                userId: $userId,
                name: 'My Dashboard'
            );
            return [
                'dashboard'       => $dashboard,
                'placements'      => [],
                'permissionLevel' => Dashboard::PERMISSION_FULL,
            ];
        }

        return null;
    }//end tryCreateFromTemplate()

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
            $dashboard->setName(name: $data['name']);
        }

        if (isset($data['description']) === true) {
            $dashboard->setDescription(
                description: $data['description']
            );
        }

        if (isset($data['gridColumns']) === true) {
            $dashboard->setGridColumns(
                gridColumns: $data['gridColumns']
            );
        }

        $dashboard->setUpdatedAt(
            updatedAt: (new DateTime())->format(format: 'Y-m-d H:i:s')
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
