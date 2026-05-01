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
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTime;
use Exception;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\IGroupManager;
use OCP\IUserManager;

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
     * @param AdminSettingsService  $adminSettings   Admin settings reader (for `group_order`).
     * @param IGroupManager         $groupManager    Nextcloud group membership lookup.
     * @param IUserManager          $userManager     Nextcloud user lookup (resolve user ID to IUser).
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingsService $adminSettings,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
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
        $template->setUuid($this->generateUuid());
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
        $template->setIsDefault($isDefault);
        $template->setCreatedAt(new DateTime());
        $template->setUpdatedAt(new DateTime());

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

        $template->setUpdatedAt(new DateTime());

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
                $data['isDefault']
            );
        }

        if (isset($data['gridColumns']) === true) {
            $template->setGridColumns(
                $data['gridColumns']
            );
        }
    }//end applyTemplateUpdates()

    /**
     * Resolve the user's primary workspace group (REQ-TMPL-012).
     *
     * Pure read-only function — performs no writes. Implements the single
     * routing authority for primary-group resolution (REQ-TMPL-013): every
     * workspace-rendering and dashboard-resolution code path MUST consult
     * this method instead of inlining the algorithm.
     *
     * Algorithm:
     *   1. Read the admin-configured ordered list of group IDs from
     *      `admin_settings.group_order` (JSON `string[]`, default `[]`).
     *   2. Read the user's Nextcloud group memberships via
     *      `IGroupManager::getUserGroupIds`.
     *   3. Walk `group_order` left-to-right and return the first group ID
     *      that also appears in the user's memberships.
     *   4. If no match (including empty list, user in no configured group,
     *      or every configured ID is stale), return the literal sentinel
     *      `Dashboard::DEFAULT_GROUP_ID` (`'default'`).
     *
     * Stale (deleted) group IDs in `group_order` are tolerated — cleanup is
     * the admin UI's responsibility and the resolver MUST NOT throw on
     * them.
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return string The matched Nextcloud group ID, or
     *                {@see Dashboard::DEFAULT_GROUP_ID} when no match
     *                exists.
     */
    public function resolvePrimaryGroup(string $userId): string
    {
        $orderedGroups = $this->adminSettings->getGroupOrder();

        // Short-circuit: if no groups are configured the answer is always
        // the default sentinel — no need to look the user up.
        if ($orderedGroups === []) {
            return Dashboard::DEFAULT_GROUP_ID;
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            // Stale / unknown user ID — tolerate silently per
            // REQ-TMPL-012 spirit (resolver MUST NOT throw on bad input).
            return Dashboard::DEFAULT_GROUP_ID;
        }

        $userGroups = $this->groupManager->getUserGroupIds(user: $user);

        $match = self::pickFirstMatch(
            orderedGroups: $orderedGroups,
            userGroups: $userGroups
        );

        return ($match ?? Dashboard::DEFAULT_GROUP_ID);
    }//end resolvePrimaryGroup()

    /**
     * Pick the first ordered group that the user is a member of.
     *
     * Internal pure helper extracted for direct unit-testability of the
     * intersection logic, independent of any Nextcloud/DI dependencies.
     * Walks `$orderedGroups` left-to-right and returns the first entry that
     * also appears in `$userGroups`. Returns `null` when no overlap exists
     * (including either argument being empty).
     *
     * Tolerates stale entries in `$orderedGroups` — entries that are not in
     * `$userGroups` are simply skipped, never raise an error.
     *
     * @param array<int, string> $orderedGroups The admin-configured ordered
     *                                          list of group IDs.
     * @param array<int, string> $userGroups    The user's actual Nextcloud
     *                                          group memberships.
     *
     * @return string|null The first matching group ID, or `null` when the
     *                     two lists do not intersect.
     */
    public static function pickFirstMatch(
        array $orderedGroups,
        array $userGroups
    ): ?string {
        if ($orderedGroups === [] || $userGroups === []) {
            return null;
        }

        // O(n) lookup: hash the user's groups for constant-time matching.
        $userGroupSet = array_flip($userGroups);

        foreach ($orderedGroups as $groupId) {
            if (isset($userGroupSet[$groupId]) === true) {
                return $groupId;
            }
        }

        return null;
    }//end pickFirstMatch()


    /**
     * Generate a UUID v4.
     *
     * @return string The generated UUID.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(length: 16);
        $data[6] = chr(codepoint: ord(character: $data[6]) & 0x0f | 0x40);
        $data[8] = chr(codepoint: ord(character: $data[8]) & 0x3f | 0x80);

        return vsprintf(
            format: '%s%s-%s-%s-%s-%s%s%s',
            values: str_split(string: bin2hex(string: $data), length: 4)
        );
    }//end generateUuid()
}//end class
