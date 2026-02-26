<?php

/**
 * AdminTemplateService
 *
 * Service for admin template CRUD operations.
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use Ramsey\Uuid\Uuid;

/**
 * Service for admin template CRUD operations.
 */
class AdminTemplateService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
    ) {
    }//end __construct()

    /**
     * List all admin dashboard templates.
     *
     * @return Dashboard[] The list of admin templates.
     */
    public function listTemplates(): array
    {
        return $this->dashboardMapper->findAdminTemplates();
    }//end listTemplates()

    /**
     * Get a specific admin template with its placements.
     *
     * @param int $id The template ID.
     *
     * @return array The template and its placements.
     *
     * @throws Exception If the dashboard is not an admin template.
     */
    public function getTemplateWithPlacements(int $id): array
    {
        $template = $this->dashboardMapper->find(id: $id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception(message: 'Not an admin template');
        }

        $placements = $this->placementMapper->findByDashboardId(
            dashboardId: $id
        );

        return [
            'template'   => $template,
            'placements' => $placements,
        ];
    }//end getTemplateWithPlacements()

    /**
     * Create a new admin template.
     *
     * @param string      $name            The template name.
     * @param string|null $description     The template description.
     * @param array|null  $targetGroups    The target groups.
     * @param string      $permissionLevel The permission level.
     * @param bool        $isDefault       Whether this is the default.
     *
     * @return Dashboard The created template.
     */
    public function createTemplate(
        string $name,
        ?string $description=null,
        ?array $targetGroups=null,
        string $permissionLevel=Dashboard::PERMISSION_ADD_ONLY,
        bool $isDefault=false
    ): Dashboard {
        if ($isDefault === true) {
            $this->dashboardMapper->clearDefaultTemplates();
        }

        $template = new Dashboard();
        $template->setUuid(uuid: Uuid::uuid4()->toString());
        $template->setName(name: $name);
        $template->setDescription(description: $description);
        $template->setType(type: Dashboard::TYPE_ADMIN_TEMPLATE);
        $template->setUserId(userId: null);
        $template->setGridColumns(gridColumns: 12);
        $template->setPermissionLevel(
            permissionLevel: $permissionLevel
        );
        $template->setTargetGroupsArray(
            groups: $targetGroups ?? []
        );
        $template->setIsDefault(isDefault: $isDefault);
        $template->setCreatedAt(createdAt: new DateTime());
        $template->setUpdatedAt(updatedAt: new DateTime());

        return $this->dashboardMapper->insert(entity: $template);
    }//end createTemplate()

    /**
     * Update an admin template.
     *
     * @param int   $id   The template ID.
     * @param array $data The fields to update.
     *
     * @return Dashboard The updated template.
     *
     * @throws Exception If the dashboard is not an admin template.
     */
    public function updateTemplate(int $id, array $data): Dashboard
    {
        $template = $this->dashboardMapper->find(id: $id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception(message: 'Not an admin template');
        }

        $this->applyTemplateUpdates(
            template: $template,
            data: $data
        );

        $template->setUpdatedAt(updatedAt: new DateTime());

        return $this->dashboardMapper->update(entity: $template);
    }//end updateTemplate()

    /**
     * Delete an admin template.
     *
     * @param int $id The template ID.
     *
     * @return void
     *
     * @throws Exception If the dashboard is not an admin template.
     */
    public function deleteTemplate(int $id): void
    {
        $template = $this->dashboardMapper->find(id: $id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception(message: 'Not an admin template');
        }

        // Delete placements first.
        $this->placementMapper->deleteByDashboardId(dashboardId: $id);

        // Delete template.
        $this->dashboardMapper->delete(entity: $template);
    }//end deleteTemplate()

    /**
     * Apply update data to a template entity.
     *
     * @param Dashboard $template The template entity.
     * @param array     $data     The update data.
     *
     * @return void
     */
    private function applyTemplateUpdates(
        Dashboard $template,
        array $data
    ): void {
        if (isset($data['name']) === true) {
            $template->setName(name: $data['name']);
        }

        if (isset($data['description']) === true) {
            $template->setDescription(
                description: $data['description']
            );
        }

        if (isset($data['targetGroups']) === true) {
            $template->setTargetGroupsArray(
                groups: $data['targetGroups']
            );
        }

        if (isset($data['permissionLevel']) === true) {
            $template->setPermissionLevel(
                permissionLevel: $data['permissionLevel']
            );
        }

        if (isset($data['isDefault']) === true) {
            if ($data['isDefault'] === true) {
                $this->dashboardMapper->clearDefaultTemplates();
            }

            $template->setIsDefault(
                isDefault: $data['isDefault']
            );
        }

        if (isset($data['gridColumns']) === true) {
            $template->setGridColumns(
                gridColumns: $data['gridColumns']
            );
        }
    }//end applyTemplateUpdates()
}//end class
