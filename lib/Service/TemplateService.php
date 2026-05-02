<?php

/**
 * TemplateService
 *
 * Service for managing admin dashboard templates.
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service for managing admin dashboard templates.
 */
class TemplateService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper      Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper      Widget placement mapper.
     * @param AdminTemplateService  $adminTemplateService Routing resolver —
     *                                                    single source of truth
     *                                                    for
     *                                                    `IGroupManager::getUserGroupIds`
     *                                                    (REQ-TMPL-013).
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminTemplateService $adminTemplateService,
    ) {
    }//end __construct()

    /**
     * Get the applicable admin template for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard|null The applicable template or null.
     */
    public function getApplicableTemplate(string $userId): ?Dashboard
    {
        $templates = $this->dashboardMapper->findAdminTemplates();

        // Group memberships are read through the routing resolver so the
        // single-source-of-truth invariant (REQ-TMPL-013) holds. An empty
        // result means either an unknown user OR a known user with no
        // group memberships — in both cases we skip the per-template
        // intersection scan and fall through to the default template
        // lookup at the end of the method (preserving legacy behaviour).
        $userGroups = $this->adminTemplateService->getUserGroupIdsFor(
            userId: $userId
        );

        // Find template that matches user's groups.
        foreach ($templates as $template) {
            $targetGroups = $template->getTargetGroupsArray();

            // Empty target groups means applies to all users.
            if (empty($targetGroups) === true) {
                continue;
                // Check for more specific templates first.
            }

            // Check if user is in any target group.
            if (empty(array_intersect($userGroups, $targetGroups)) === false) {
                return $template;
            }
        }

        // Return default template if exists.
        try {
            return $this->dashboardMapper->findDefaultTemplate();
        } catch (DoesNotExistException) {
            return null;
        }
    }//end getApplicableTemplate()

    /**
     * Create a user dashboard based on an admin template.
     *
     * @param string    $userId   The user ID.
     * @param Dashboard $template The admin template.
     *
     * @return Dashboard The created dashboard.
     */
    public function createDashboardFromTemplate(
        string $userId,
        Dashboard $template
    ): Dashboard {
        // Create user dashboard.
        $dashboard = $this->buildDashboardFromTemplate(
            userId: $userId,
            template: $template
        );

        // Deactivate other dashboards.
        $this->dashboardMapper->deactivateAllForUser(userId: $userId);

        $dashboard = $this->dashboardMapper->insert(entity: $dashboard);

        // Copy widget placements from template.
        $this->copyTemplatePlacements(
            templateId: $template->getId(),
            dashboardId: $dashboard->getId()
        );

        return $dashboard;
    }//end createDashboardFromTemplate()

    /**
     * Build a dashboard entity from a template.
     *
     * @param string    $userId   The user ID.
     * @param Dashboard $template The admin template.
     *
     * @return Dashboard The built dashboard entity.
     */
    private function buildDashboardFromTemplate(
        string $userId,
        Dashboard $template
    ): Dashboard {
        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $dashboard = new Dashboard();
        $dashboard->setUuid($this->generateUuid());
        $dashboard->setName($template->getName());
        $dashboard->setDescription(
            $template->getDescription()
        );
        $dashboard->setType(Dashboard::TYPE_USER);
        $dashboard->setUserId($userId);
        $dashboard->setBasedOnTemplate(
            $template->getId()
        );
        $dashboard->setGridColumns(
            $template->getGridColumns()
        );
        $dashboard->setPermissionLevel(
            $template->getPermissionLevel()
        );
        $dashboard->setIsActive(1);
        $dashboard->setCreatedAt($now);
        $dashboard->setUpdatedAt($now);

        return $dashboard;
    }//end buildDashboardFromTemplate()

    /**
     * Generate a v4 UUID using random_bytes (no external dependency).
     *
     * @return string A v4 UUID.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(length: 16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
        return vsprintf(
            format: '%s%s-%s-%s-%s-%s%s%s',
            values: str_split(string: bin2hex(string: $data), length: 4)
        );
    }//end generateUuid()

    /**
     * Copy widget placements from a template to a new dashboard.
     *
     * @param int $templateId  The template dashboard ID.
     * @param int $dashboardId The target dashboard ID.
     *
     * @return void
     */
    private function copyTemplatePlacements(
        int $templateId,
        int $dashboardId
    ): void {
        $templatePlacements = $this->placementMapper->findByDashboardId(
            dashboardId: $templateId
        );

        foreach ($templatePlacements as $templatePlacement) {
            $placement = $this->clonePlacement(
                source: $templatePlacement,
                dashboardId: $dashboardId
            );
            $this->placementMapper->insert(entity: $placement);
        }
    }//end copyTemplatePlacements()

    /**
     * Clone a widget placement for a new dashboard.
     *
     * @param WidgetPlacement $source      The source placement.
     * @param int             $dashboardId The target dashboard ID.
     *
     * @return WidgetPlacement The cloned placement entity.
     */
    private function clonePlacement(
        WidgetPlacement $source,
        int $dashboardId
    ): WidgetPlacement {
        $placement = new WidgetPlacement();
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId($source->getWidgetId());
        $placement->setGridX($source->getGridX());
        $placement->setGridY($source->getGridY());
        $placement->setGridWidth($source->getGridWidth());
        $placement->setGridHeight(
            $source->getGridHeight()
        );
        $placement->setIsCompulsory(
            $source->getIsCompulsory()
        );
        $placement->setIsVisible($source->getIsVisible());
        $placement->setStyleConfig(
            $source->getStyleConfig()
        );
        $placement->setCustomTitle(
            $source->getCustomTitle()
        );
        $placement->setShowTitle($source->getShowTitle());
        $placement->setSortOrder($source->getSortOrder());
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $placement->setCreatedAt($now);
        $placement->setUpdatedAt($now);

        return $placement;
    }//end clonePlacement()
}//end class
