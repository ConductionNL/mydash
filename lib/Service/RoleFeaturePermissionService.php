<?php

/**
 * RoleFeaturePermissionService
 *
 * Stateless service that resolves the effective allowed-widget set for a
 * MyDash user based on their Nextcloud group memberships, the configured
 * `group_order` priority, and any RoleFeaturePermission rows. Implements
 * the multi-group resolution algorithm from
 * `openspec/changes/role-based-content/design.md` (REQ-RFP-001 ..
 * REQ-RFP-010).
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\RoleFeaturePermission;
use OCA\MyDash\Db\RoleFeaturePermissionMapper;
use OCA\MyDash\Db\RoleLayoutDefault;
use OCA\MyDash\Db\RoleLayoutDefaultMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * @spec openspec/changes/role-based-content/tasks.md#task-2
 *
 * All public methods are stateless — no per-request memoisation. Caller
 * concerns (controllers, other services) inject this directly.
 */
class RoleFeaturePermissionService
{
    /**
     * Constructor.
     *
     * @param RoleFeaturePermissionMapper $permissionMapper Permission mapper.
     * @param RoleLayoutDefaultMapper     $defaultMapper    Layout default mapper.
     * @param WidgetPlacementMapper       $placementMapper  Widget placement mapper.
     * @param AdminSettingsService        $adminSettings    Admin settings reader.
     * @param IGroupManager               $groupManager     Nextcloud group manager.
     * @param IUserManager                $userManager      Nextcloud user manager.
     */
    public function __construct(
        private readonly RoleFeaturePermissionMapper $permissionMapper,
        private readonly RoleLayoutDefaultMapper $defaultMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly AdminSettingsService $adminSettings,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * List all RoleFeaturePermission rows for the admin UI.
     *
     * @return RoleFeaturePermission[] All rows.
     */
    public function listPermissions(): array
    {
        return $this->permissionMapper->findAll();
    }//end listPermissions()

    /**
     * List all RoleLayoutDefault rows for the admin UI.
     *
     * @return RoleLayoutDefault[] All rows.
     */
    public function listLayoutDefaults(): array
    {
        return $this->defaultMapper->findAll();
    }//end listLayoutDefaults()

    /**
     * Upsert a RoleFeaturePermission row keyed by `groupId`.
     *
     * @param array $data The submitted permission data.
     *
     * @return RoleFeaturePermission The persisted row.
     */
    public function savePermission(array $data): RoleFeaturePermission
    {
        $groupId = (string) ($data['groupId'] ?? '');
        if ($groupId === '') {
            throw new \InvalidArgumentException(message: 'groupId is required');
        }

        try {
            $entity = $this->permissionMapper->findByGroupId(groupId: $groupId);
        } catch (DoesNotExistException $e) {
            $entity = new RoleFeaturePermission();
            $now    = (new DateTime())->format(format: 'c');
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setCreatedAt($now);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGroupId($groupId);
        }

        if (array_key_exists(key: 'name', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setName((string) $data['name']);
        }
        if (array_key_exists(key: 'description', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists(key: 'allowedWidgets', array: $data) === true) {
            $allowed = is_array(value: $data['allowedWidgets']) === true ? $data['allowedWidgets'] : [];
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setAllowedWidgets(json_encode(value: array_values(array: $allowed)));
        }
        if (array_key_exists(key: 'deniedWidgets', array: $data) === true) {
            $denied = is_array(value: $data['deniedWidgets']) === true ? $data['deniedWidgets'] : [];
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setDeniedWidgets(json_encode(value: array_values(array: $denied)));
        }
        if (array_key_exists(key: 'priorityWeights', array: $data) === true) {
            $weights = is_array(value: $data['priorityWeights']) === true ? $data['priorityWeights'] : [];
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setPriorityWeights(json_encode(value: $weights));
        }
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setUpdatedAt((new DateTime())->format(format: 'c'));

        if ($entity->getId() === null) {
            return $this->permissionMapper->insert(entity: $entity);
        }

        return $this->permissionMapper->update(entity: $entity);
    }//end savePermission()

    /**
     * Delete a RoleFeaturePermission row by id.
     *
     * @param int $id The row id.
     *
     * @return void
     *
     * @throws DoesNotExistException When the row does not exist.
     */
    public function deletePermission(int $id): void
    {
        $entity = $this->permissionMapper->find(id: $id);
        $this->permissionMapper->delete(entity: $entity);
    }//end deletePermission()

    /**
     * Upsert a RoleLayoutDefault row keyed by `(groupId, widgetId)`.
     *
     * @param array $data The submitted layout default data.
     *
     * @return RoleLayoutDefault The persisted row.
     */
    public function saveLayoutDefault(array $data): RoleLayoutDefault
    {
        $groupId  = (string) ($data['groupId'] ?? '');
        $widgetId = (string) ($data['widgetId'] ?? '');
        if ($groupId === '' || $widgetId === '') {
            throw new \InvalidArgumentException(
                message: 'groupId and widgetId are required'
            );
        }

        try {
            $entity = $this->defaultMapper->findByGroupAndWidget(
                groupId: $groupId,
                widgetId: $widgetId
            );
        } catch (DoesNotExistException $e) {
            $entity = new RoleLayoutDefault();
            $now    = (new DateTime())->format(format: 'c');
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setCreatedAt($now);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGroupId($groupId);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setWidgetId($widgetId);
        }

        if (array_key_exists(key: 'name', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setName((string) $data['name']);
        }
        if (array_key_exists(key: 'description', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists(key: 'gridX', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGridX((int) $data['gridX']);
        }
        if (array_key_exists(key: 'gridY', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGridY((int) $data['gridY']);
        }
        if (array_key_exists(key: 'gridWidth', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGridWidth(max(1, (int) $data['gridWidth']));
        }
        if (array_key_exists(key: 'gridHeight', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setGridHeight(max(1, (int) $data['gridHeight']));
        }
        if (array_key_exists(key: 'sortOrder', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setSortOrder((int) $data['sortOrder']);
        }
        if (array_key_exists(key: 'isCompulsory', array: $data) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $entity->setIsCompulsory(((bool) $data['isCompulsory']) === true ? 1 : 0);
        }
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setUpdatedAt((new DateTime())->format(format: 'c'));

        if ($entity->getId() === null) {
            return $this->defaultMapper->insert(entity: $entity);
        }

        return $this->defaultMapper->update(entity: $entity);
    }//end saveLayoutDefault()

    /**
     * Delete a RoleLayoutDefault row by id.
     *
     * @param int $id The row id.
     *
     * @return void
     *
     * @throws DoesNotExistException When the row does not exist.
     */
    public function deleteLayoutDefault(int $id): void
    {
        $entity = $this->defaultMapper->find(id: $id);
        $this->defaultMapper->delete(entity: $entity);
    }//end deleteLayoutDefault()

    /**
     * Resolve the effective allowed-widget ID list for a user.
     *
     * Returns `null` (= no restriction, REQ-RFP-009) when none of the user's
     * groups are mapped AND no `default` RoleFeaturePermission exists.
     *
     * Algorithm (REQ-RFP-005):
     * 1. Walk the configured `group_order` array.
     * 2. The FIRST group that matches BOTH the user's group memberships AND
     *    has a RoleFeaturePermission row provides the BASE allowed set.
     * 3. ALL subsequent groups that match the user widen the allowed set
     *    via union.
     * 4. ANY group's `deniedWidgets` removes those widget IDs from the
     *    final set (deny-wins).
     * 5. If no `group_order` group matched, fall back to the row whose
     *    groupId == 'default' (REQ-RFP-009).
     *
     * @param string $userId The user's UID.
     *
     * @return array|null Sorted list of allowed widget IDs, or null.
     */
    public function getAllowedWidgetIds(string $userId): ?array
    {
        $userGroups = $this->groupIdsForUser(userId: $userId);
        if ($userGroups === []) {
            return $this->fallbackAllowedWidgets();
        }

        $groupOrder = $this->adminSettings->getGroupOrder();
        $base       = null;
        $allowed    = [];
        $denied     = [];

        // Pre-fetch all RoleFeaturePermission rows for the user's groups (one query).
        $rows  = $this->permissionMapper->findByGroupIds(groupIds: $userGroups);
        $byGid = [];
        foreach ($rows as $row) {
            $byGid[$row->getGroupId()] = $row;
        }

        foreach ($groupOrder as $gid) {
            if (in_array(needle: $gid, haystack: $userGroups, strict: true) === false) {
                continue;
            }
            if (array_key_exists(key: $gid, array: $byGid) === false) {
                continue;
            }
            $row     = $byGid[$gid];
            $rowAllow = $row->getAllowedWidgetsDecoded();
            $rowDeny  = $row->getDeniedWidgetsDecoded();
            if ($base === null) {
                $base    = true;
                $allowed = $rowAllow;
            } else {
                $allowed = array_values(
                    array: array_unique(array: array_merge($allowed, $rowAllow))
                );
            }
            $denied = array_values(
                array: array_unique(array: array_merge($denied, $rowDeny))
            );
        }//end foreach

        if ($base === null) {
            // No group_order match — try the explicit 'default' row.
            return $this->fallbackAllowedWidgets();
        }

        $effective = array_values(
            array: array_diff($allowed, $denied)
        );
        sort(array: $effective);
        return $effective;
    }//end getAllowedWidgetIds()

    /**
     * Convenience: is a single widget allowed for a user?
     *
     * @param string $userId   The user's UID.
     * @param string $widgetId The widget id to check.
     *
     * @return bool True when the widget is allowed (or no restriction is configured).
     */
    public function isWidgetAllowed(string $userId, string $widgetId): bool
    {
        $allowed = $this->getAllowedWidgetIds(userId: $userId);
        if ($allowed === null) {
            // No restriction configured — backwards-compatible.
            return true;
        }

        return in_array(needle: $widgetId, haystack: $allowed, strict: true);
    }//end isWidgetAllowed()

    /**
     * Seed the default layout for a freshly created dashboard from the
     * RoleLayoutDefault rows attached to the user's primary group.
     *
     * No-op when the dashboard already has placements (REQ-RFP-002 scenario 3
     * — never overwrite personal customisations).
     *
     * Resolves the user's primary group by walking `group_order` and taking
     * the first match that has at least one RoleLayoutDefault row.
     *
     * @param string    $userId    The user's UID.
     * @param Dashboard $dashboard The dashboard to seed (must already exist).
     *
     * @return int The number of placements created (0 when no-op).
     */
    public function seedLayoutFromRoleDefaults(string $userId, Dashboard $dashboard): int
    {
        $existing = $this->placementMapper->findByDashboardId(
            dashboardId: $dashboard->getId()
        );
        if (count(value: $existing) > 0) {
            return 0;
        }

        $userGroups = $this->groupIdsForUser(userId: $userId);
        if ($userGroups === []) {
            return 0;
        }

        $groupOrder = $this->adminSettings->getGroupOrder();
        $defaults   = [];
        foreach ($groupOrder as $gid) {
            if (in_array(needle: $gid, haystack: $userGroups, strict: true) === false) {
                continue;
            }
            $defaults = $this->defaultMapper->findByGroupId(groupId: $gid);
            if (count(value: $defaults) > 0) {
                break;
            }
        }

        if (count(value: $defaults) === 0) {
            return 0;
        }

        $created = 0;
        $now     = (new DateTime())->format(format: 'Y-m-d H:i:s');
        foreach ($defaults as $default) {
            $placement = new WidgetPlacement();
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setDashboardId($dashboard->getId());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setWidgetId($default->getWidgetId());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setGridX($default->getGridX());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setGridY($default->getGridY());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setGridWidth($default->getGridWidth());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setGridHeight($default->getGridHeight());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setSortOrder($default->getSortOrder());
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setShowTitle(1);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setIsVisible(1);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setCreatedAt($now);
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setUpdatedAt($now);

            $this->placementMapper->insert(entity: $placement);
            $created++;
        }//end foreach

        return $created;
    }//end seedLayoutFromRoleDefaults()

    /**
     * Look up `default` RoleFeaturePermission row when no user-group match
     * occurred. Returns null when there is no `default` row.
     *
     * @return array|null The allowed widget list from the default row.
     */
    private function fallbackAllowedWidgets(): ?array
    {
        try {
            $row = $this->permissionMapper->findByGroupId(
                groupId: RoleFeaturePermission::GROUP_DEFAULT
            );
        } catch (DoesNotExistException $e) {
            return null;
        }

        $allowed = $row->getAllowedWidgetsDecoded();
        $denied  = $row->getDeniedWidgetsDecoded();
        $eff     = array_values(array: array_diff($allowed, $denied));
        sort(array: $eff);
        return $eff;
    }//end fallbackAllowedWidgets()

    /**
     * Pull the list of group IDs a user belongs to. Wraps `IGroupManager`.
     *
     * @param string $userId The user UID.
     *
     * @return array The user's group IDs (may be empty).
     */
    private function groupIdsForUser(string $userId): array
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return [];
        }

        return $this->groupManager->getUserGroupIds(user: $user);
    }//end groupIdsForUser()
}//end class
