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
use OCP\IGroupManager;
use OCP\IUserManager;
use Ramsey\Uuid\Uuid;

/**
 * Service for managing admin dashboard templates.
 */
class TemplateService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param IGroupManager         $groupManager    Group manager interface.
     * @param IUserManager          $userManager     User manager interface.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
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

        // Get user object and their groups.
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return null;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);

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
            $userId,
            $template
        );

        // Deactivate other dashboards.
        $this->dashboardMapper->deactivateAllForUser($userId);

        $dashboard = $this->dashboardMapper->insert($dashboard);

        // Copy widget placements from template.
        $this->copyTemplatePlacements(
            $template->getId(),
            $dashboard->getId()
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
        $dashboard = new Dashboard();
        $dashboard->setUuid(Uuid::uuid4()->toString());
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
        $dashboard->setIsActive(true);
        $dashboard->setCreatedAt(new DateTime());
        $dashboard->setUpdatedAt(new DateTime());

        return $dashboard;
    }//end buildDashboardFromTemplate()

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
            $templateId
        );

        foreach ($templatePlacements as $templatePlacement) {
            $placement = $this->clonePlacement(
                $templatePlacement,
                $dashboardId
            );
            $this->placementMapper->insert($placement);
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
        $placement->setCreatedAt(new DateTime());
        $placement->setUpdatedAt(new DateTime());

        return $placement;
    }//end clonePlacement()
}//end class
