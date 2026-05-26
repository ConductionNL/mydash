<?php

/**
 * RoleService
 *
 * Business-logic layer for the admin-roles capability. Owns role
 * validation, effective-role resolution, assignment CRUD, and the
 * cascade entry points invoked by the user/group deletion listeners.
 *
 * The resolver implements the deterministic algorithm specified in
 * REQ-ROLE-005:
 *  1. Nextcloud admin → role "admin", source "nc-admin".
 *  2. Direct user assignment → used as-is (group assignments skipped).
 *  3. Otherwise highest-privilege wins among the user's group assignments.
 *  4. No assignment → null (caller falls back to permissions capability).
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
use OCA\MyDash\Db\RoleAssignment;
use OCA\MyDash\Db\RoleAssignmentMapper;
use OCA\MyDash\Exception\DuplicateRoleAssignmentException;
use OCA\MyDash\Exception\InvalidRoleAssignmentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

// REQ-TMPL-013: `IGroupManager::getUserGroupIds` MUST only be invoked
// from AdminTemplateService. This service consumes the routing helper
// `AdminTemplateService::getUserGroupIdsFor` instead so the grep guard
// (`AdminTemplateServiceGrepGuardTest`) stays satisfied.

/**
 * Role assignment service (REQ-ROLE-001..011).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Validation, resolution,
 *                                                CRUD and cascade methods
 *                                                belong on a single
 *                                                cohesive role service.
 */
class RoleService
{
    /**
     * Constructor
     *
     * @param RoleAssignmentMapper $mapper               Persistence mapper.
     * @param IUserManager         $userManager          Nextcloud user manager.
     * @param IGroupManager        $groupManager         Nextcloud group manager
     *                                                   (used only for `isAdmin`
     *                                                   and `groupExists` —
     *                                                   group-membership lookups
     *                                                   go through the routing
     *                                                   resolver per
     *                                                   REQ-TMPL-013).
     * @param AdminTemplateService $adminTemplateService Routing resolver — the
     *                                                   single source of truth
     *                                                   for `getUserGroupIds`.
     */
    public function __construct(
        private readonly RoleAssignmentMapper $mapper,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly AdminTemplateService $adminTemplateService,
    ) {
    }//end __construct()

    /**
     * Resolve the effective MyDash role for a user (REQ-ROLE-005).
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return string|null The role string, or null when no role applies.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function getEffectiveRole(string $userId): ?string
    {
        if ($this->groupManager->isAdmin(userId: $userId) === true) {
            return RoleAssignment::ROLE_ADMIN;
        }

        $direct = $this->mapper->findByUser(userId: $userId);
        if (count($direct) > 0) {
            return $this->highestRole(assignments: $direct);
        }

        $groupIds = $this->adminTemplateService->getUserGroupIdsFor(
            userId: $userId
        );

        $groupAssignments = $this->mapper->findByGroupIds(groupIds: $groupIds);
        if (count($groupAssignments) === 0) {
            return null;
        }

        return $this->highestRole(assignments: $groupAssignments);
    }//end getEffectiveRole()

    /**
     * Resolve the source of a user's effective role (REQ-ROLE-006).
     *
     * Returns "nc-admin", "user-assigned", "group-assigned:{groupId}",
     * or null when no role applies.
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return string|null The source identifier, or null when no role.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function getRoleSource(string $userId): ?string
    {
        if ($this->groupManager->isAdmin(userId: $userId) === true) {
            return RoleAssignment::SOURCE_NC_ADMIN;
        }

        $direct = $this->mapper->findByUser(userId: $userId);
        if (count($direct) > 0) {
            return RoleAssignment::SOURCE_USER_ASSIGNED;
        }

        $groupIds = $this->adminTemplateService->getUserGroupIdsFor(
            userId: $userId
        );

        $groupAssignments = $this->mapper->findByGroupIds(groupIds: $groupIds);
        if (count($groupAssignments) === 0) {
            return null;
        }

        $winning = $this->highestAssignment(assignments: $groupAssignments);

        return RoleAssignment::SOURCE_GROUP_ASSIGNED_PREFIX.(string) $winning->getGroupId();
    }//end getRoleSource()

    /**
     * Validate a role string against the canonical enum (REQ-ROLE-001..003).
     *
     * @param string $role The candidate role.
     *
     * @return void
     *
     * @throws InvalidRoleAssignmentException When the role is unknown.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function validateRole(string $role): void
    {
        if (in_array(
            needle: $role,
            haystack: RoleAssignment::VALID_ROLES,
            strict: true
        ) === false
        ) {
            throw new InvalidRoleAssignmentException(
                message: 'Unknown role; must be one of admin, editor, viewer'
            );
        }
    }//end validateRole()

    /**
     * Validate the user/group XOR target plus existence in Nextcloud
     * (REQ-ROLE-004).
     *
     * @param string|null $userId  Candidate user ID.
     * @param string|null $groupId Candidate group ID.
     *
     * @return void
     *
     * @throws InvalidRoleAssignmentException On any structural failure.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function validateTarget(?string $userId, ?string $groupId): void
    {
        $hasUser  = ($userId !== null && $userId !== '');
        $hasGroup = ($groupId !== null && $groupId !== '');

        if ($hasUser === false && $hasGroup === false) {
            throw new InvalidRoleAssignmentException(
                message: 'Either userId or groupId must be provided'
            );
        }

        if ($hasUser === true && $hasGroup === true) {
            throw new InvalidRoleAssignmentException(
                message: 'Only one of userId or groupId may be provided'
            );
        }

        if ($hasUser === true && $this->userManager->userExists(uid: $userId) === false) {
            throw new InvalidRoleAssignmentException(
                message: 'Unknown user'
            );
        }

        if ($hasGroup === true && $this->groupManager->groupExists(gid: $groupId) === false) {
            throw new InvalidRoleAssignmentException(
                message: 'Unknown group'
            );
        }
    }//end validateTarget()

    /**
     * Create a new role assignment (REQ-ROLE-004).
     *
     * @param string|null $userId     The user ID, or null for a group assignment.
     * @param string|null $groupId    The group ID, or null for a user assignment.
     * @param string      $role       The role name.
     * @param string      $assignedBy The acting admin's user ID.
     *
     * @return RoleAssignment The persisted assignment with its generated ID.
     *
     * @throws InvalidRoleAssignmentException   On structural failures.
     * @throws DuplicateRoleAssignmentException When the (target, role) pair exists.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function assignRole(
        ?string $userId,
        ?string $groupId,
        string $role,
        string $assignedBy
    ): RoleAssignment {
        $this->validateRole(role: $role);
        $this->validateTarget(userId: $userId, groupId: $groupId);

        if ($userId !== null && $userId !== ''
            && $this->mapper->findUserRole(userId: $userId, role: $role) !== null
        ) {
            throw new DuplicateRoleAssignmentException();
        }

        if ($groupId !== null && $groupId !== ''
            && $this->mapper->findGroupRole(groupId: $groupId, role: $role) !== null
        ) {
            throw new DuplicateRoleAssignmentException();
        }

        $assignment = new RoleAssignment();
        // Entity setters MUST receive positional args — Entity::__call
        // forwards $args[0] which means named args would be misinterpreted.
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $assignment->setUserId($userId);
        $assignment->setGroupId($groupId);
        $assignment->setRole($role);
        $assignment->setAssignedBy($assignedBy);
        $assignment->setAssignedAt((new DateTime())->format('c'));
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        return $this->mapper->insert(entity: $assignment);
    }//end assignRole()

    /**
     * Remove a role assignment by ID (REQ-ROLE-004).
     *
     * @param int $id The assignment ID.
     *
     * @return void
     *
     * @throws DoesNotExistException When no row matches the given ID.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function removeRole(int $id): void
    {
        $affected = $this->mapper->deleteById(id: $id);
        if ($affected === 0) {
            throw new DoesNotExistException(msg: 'Role assignment not found');
        }
    }//end removeRole()

    /**
     * List every role assignment in the system (REQ-ROLE-006 admin listing).
     *
     * @return RoleAssignment[] Every persisted assignment.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function listAssignments(): array
    {
        return $this->mapper->findAll();
    }//end listAssignments()

    /**
     * Cascade entry point invoked by the user-deletion listener
     * (REQ-ROLE-010).
     *
     * @param string $userId The deleted user's UID.
     *
     * @return int The number of rows removed.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function deleteByUserId(string $userId): int
    {
        return $this->mapper->deleteByUserId(userId: $userId);
    }//end deleteByUserId()

    /**
     * Cascade entry point invoked by the group-deletion listener
     * (REQ-ROLE-011).
     *
     * @param string $groupId The deleted group's GID.
     *
     * @return int The number of rows removed.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function deleteByGroupId(string $groupId): int
    {
        return $this->mapper->deleteByGroupId(groupId: $groupId);
    }//end deleteByGroupId()

    /**
     * Whether the user's effective role is "admin" (REQ-ROLE-001).
     *
     * @param string $userId The user ID.
     *
     * @return bool True for admin role.
     */
    public function isAdmin(string $userId): bool
    {
        return $this->getEffectiveRole(userId: $userId) === RoleAssignment::ROLE_ADMIN;
    }//end isAdmin()

    /**
     * Whether the user's effective role is editor or higher (REQ-ROLE-002).
     *
     * @param string $userId The user ID.
     *
     * @return bool True for editor or admin.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function isEditorOrHigher(string $userId): bool
    {
        $role = $this->getEffectiveRole(userId: $userId);

        return $role === RoleAssignment::ROLE_EDITOR
            || $role === RoleAssignment::ROLE_ADMIN;
    }//end isEditorOrHigher()

    /**
     * Whether the user is explicitly Viewer (REQ-ROLE-008 mutation guard).
     *
     * @param string $userId The user ID.
     *
     * @return bool True when the effective role is "viewer".
     */
    public function isViewer(string $userId): bool
    {
        return $this->getEffectiveRole(userId: $userId) === RoleAssignment::ROLE_VIEWER;
    }//end isViewer()

    /**
     * Whether the user can mutate dashboard structure (REQ-ROLE-008).
     *
     * Returns false only for users whose effective role is explicitly
     * "viewer". Users with no assignment fall back to true so the existing
     * permissions capability stays the source of truth.
     *
     * @param string $userId The user ID.
     *
     * @return bool False when the user has the Viewer role.
     */
    /** @spec openspec/specs/admin-roles/spec.md */
    public function canMutate(string $userId): bool
    {
        return $this->isViewer(userId: $userId) === false;
    }//end canMutate()

    /**
     * Pick the highest-ranked assignment from a non-empty list.
     *
     * @param RoleAssignment[] $assignments The candidate rows.
     *
     * @return RoleAssignment The winning assignment.
     */
    private function highestAssignment(array $assignments): RoleAssignment
    {
        $winner   = $assignments[0];
        $bestRank = RoleAssignment::ROLE_RANKS[(string) $winner->getRole()] ?? -1;

        foreach ($assignments as $candidate) {
            $rank = RoleAssignment::ROLE_RANKS[(string) $candidate->getRole()] ?? -1;
            if ($rank > $bestRank) {
                $winner   = $candidate;
                $bestRank = $rank;
            }
        }

        return $winner;
    }//end highestAssignment()

    /**
     * Return the highest-ranked role string from a non-empty list.
     *
     * @param RoleAssignment[] $assignments The candidate rows.
     *
     * @return string The winning role name.
     */
    private function highestRole(array $assignments): string
    {
        return (string) $this->highestAssignment(assignments: $assignments)->getRole();
    }//end highestRole()
}//end class
