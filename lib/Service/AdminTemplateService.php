<?php

/**
 * AdminTemplateService
 *
 * Service for admin template CRUD operations and the canonical
 * primary-group routing resolver (REQ-TMPL-012, REQ-TMPL-013).
 *
 * This class is the single source of truth for:
 *   - Walking the admin-configured `group_order` priority list to pick the
 *     user's primary workspace group (`resolvePrimaryGroup`).
 *   - Reading the user's Nextcloud group memberships
 *     (`getUserGroupIdsFor` — the only place in `lib/` that calls
 *     `IGroupManager::getUserGroupIds`). The grep-based test
 *     {@see \Unit\Service\AdminTemplateServiceGrepGuardTest} enforces this
 *     invariant.
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
 * Service for admin template CRUD operations and primary-group routing.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Routing resolver
 *  intentionally lives here so REQ-TMPL-013's single-source-of-truth
 *  invariant is statically enforceable by the grep guard.
 */
class AdminTemplateService
{
    /**
     * Constructor
     *
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param AdminSettingsService  $settingsService Admin settings reader
     *                                               (provides the `group_order`
     *                                               list).
     * @param IGroupManager         $groupManager    Nextcloud group manager.
     * @param IUserManager          $userManager     Nextcloud user manager.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingsService $settingsService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Resolve the Nextcloud group ID whose `group_shared` dashboards the
     * given user should see (REQ-TMPL-012).
     *
     * Pure read: walks the admin-configured ordered list of group IDs from
     * `admin_settings.group_order`, intersects with the user's actual group
     * memberships (`IGroupManager::getUserGroupIds`), and returns the first
     * match. When no group matches — or when `group_order` is empty / the
     * user has no groups — returns the literal {@see Dashboard::DEFAULT_GROUP_ID}
     * sentinel. Stale group IDs in `group_order` (groups that no longer
     * exist in Nextcloud) are tolerated: they simply never match a real
     * user membership and are silently skipped.
     *
     * MUST be deterministic and idempotent — never writes.
     *
     * @param string $userId The user ID.
     *
     * @return string The resolved primary group ID, or
     *                {@see Dashboard::DEFAULT_GROUP_ID} when no match is found.
     */
    public function resolvePrimaryGroup(string $userId): string
    {
        $orderedGroups = $this->settingsService->getGroupOrder();
        $userGroups    = $this->getUserGroupIdsFor(userId: $userId);

        $match = self::pickFirstMatch(
            orderedGroups: $orderedGroups,
            userGroups: $userGroups
        );

        if ($match === null) {
            return Dashboard::DEFAULT_GROUP_ID;
        }

        return $match;
    }//end resolvePrimaryGroup()

    /**
     * Pure helper: return the first element of `$orderedGroups` that also
     * appears in `$userGroups`, or `null` when there is no overlap.
     *
     * Extracted from {@see self::resolvePrimaryGroup()} so the algorithm
     * itself is unit-testable without an `IGroupManager` /
     * `AdminSettingsService` round-trip. The method is `static` because it
     * has no instance state — kept on the class for discoverability.
     *
     * @param string[] $orderedGroups The admin-configured priority list.
     * @param string[] $userGroups    The user's actual group memberships.
     *
     * @return string|null The first matching group ID, or `null` when no
     *                     element of `$orderedGroups` is present in
     *                     `$userGroups`.
     */
    public static function pickFirstMatch(
        array $orderedGroups,
        array $userGroups
    ): ?string {
        if ($orderedGroups === [] || $userGroups === []) {
            return null;
        }

        $userIndex = array_flip(array: $userGroups);

        foreach ($orderedGroups as $groupId) {
            if (isset($userIndex[$groupId]) === true) {
                return $groupId;
            }
        }

        return null;
    }//end pickFirstMatch()

    /**
     * Resolve the user's Nextcloud group IDs (REQ-TMPL-013).
     *
     * Single-source-of-truth wrapper around `IGroupManager::getUserGroupIds`.
     * Every other service that needs the user's group memberships MUST
     * consume this helper instead of injecting `IGroupManager` directly —
     * the {@see \Unit\Service\AdminTemplateServiceGrepGuardTest} grep guard
     * enforces the rule. Returns `[]` when the user is unknown so callers
     * can treat "no user" the same as "no groups".
     *
     * @param string $userId The user ID.
     *
     * @return string[] The user's group IDs, or `[]` when the user does
     *                  not exist.
     */
    public function getUserGroupIdsFor(string $userId): array
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return [];
        }

        return $this->groupManager->getUserGroupIds(user: $user);
    }//end getUserGroupIdsFor()

    /**
     * Resolve the human-readable display name for a primary group ID.
     *
     * Used by the workspace renderer (REQ-TMPL-012) so the frontend can
     * label the dashboard switcher with the friendly name. The
     * {@see Dashboard::DEFAULT_GROUP_ID} sentinel resolves to the literal
     * string `'Default'` (translated client-side); a real group ID is
     * looked up via `IGroupManager::get()` and its display name returned —
     * falling back to the group ID itself when the group has been deleted
     * since the resolver ran (rare race).
     *
     * @param string $groupId The group ID returned by
     *                        {@see self::resolvePrimaryGroup()}.
     *
     * @return string The display name to surface to the frontend.
     */
    public function resolvePrimaryGroupDisplayName(string $groupId): string
    {
        if ($groupId === Dashboard::DEFAULT_GROUP_ID) {
            return 'Default';
        }

        $group = $this->groupManager->get(gid: $groupId);
        if ($group === null) {
            return $groupId;
        }

        return $group->getDisplayName();
    }//end resolvePrimaryGroupDisplayName()

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

        $now      = (new DateTime())->format(format: 'Y-m-d H:i:s');
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
        $template->setIsDefault((int) $isDefault);
        $template->setCreatedAt($now);
        $template->setUpdatedAt($now);

        return $this->dashboardMapper->insert(entity: $template);
    }//end createTemplate()

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

        $template->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

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
}//end class
