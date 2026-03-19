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
// Note: Ramsey\Uuid is not a direct dependency; using random_bytes-based UUID v4 generator.

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
        $template = $this->dashboardMapper->find($id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception('Not an admin template');
        }

        $placements = $this->placementMapper->findByDashboardId(
            $id
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

        $now      = (new DateTime())->format('Y-m-d H:i:s');
        $template = new Dashboard();
        $template->setUuid(self::generateUuid());
        $template->setName($name);
        $template->setDescription($description);
        $template->setType(Dashboard::TYPE_ADMIN_TEMPLATE);
        $template->setUserId(null);
        $template->setGridColumns(12);
        $template->setPermissionLevel(
            $permissionLevel
        );
        $template->setTargetGroupsArray(
            $targetGroups ?? []
        );
        if ($isDefault === true) {
            $template->setIsDefault(1);
        } else {
            $template->setIsDefault(0);
        }
        $template->setCreatedAt($now);
        $template->setUpdatedAt($now);

        return $this->dashboardMapper->insert($template);
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
        $template = $this->dashboardMapper->find($id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception('Not an admin template');
        }

        $this->applyTemplateUpdates(
            template: $template,
            data: $data
        );

        $template->setUpdatedAt(
            (new DateTime())->format('Y-m-d H:i:s')
        );

        return $this->dashboardMapper->update($template);
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
        $template = $this->dashboardMapper->find($id);

        if ($template->getType() !== Dashboard::TYPE_ADMIN_TEMPLATE) {
            throw new Exception('Not an admin template');
        }

        // Delete placements first.
        $this->placementMapper->deleteByDashboardId($id);

        // Delete template.
        $this->dashboardMapper->delete($template);
    }//end deleteTemplate()

    /**
     * Generate a UUID v4 string.
     *
     * @return string The generated UUID.
     */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }//end generateUuid()

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
            $template->setName($data['name']);
        }

        if (isset($data['description']) === true) {
            $template->setDescription(
                $data['description']
            );
        }

        if (isset($data['targetGroups']) === true) {
            $template->setTargetGroupsArray(
                $data['targetGroups']
            );
        }

        if (isset($data['permissionLevel']) === true) {
            $template->setPermissionLevel(
                $data['permissionLevel']
            );
        }

        if (isset($data['isDefault']) === true) {
            if ($data['isDefault'] === true) {
                $this->dashboardMapper->clearDefaultTemplates();
            }

            $template->setIsDefault(
                $data['isDefault'] ? 1 : 0
            );
        }

        if (isset($data['gridColumns']) === true) {
            $template->setGridColumns(
                $data['gridColumns']
            );
        }
    }//end applyTemplateUpdates()
}//end class
