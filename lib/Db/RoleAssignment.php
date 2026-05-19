<?php

/**
 * RoleAssignment Entity
 *
 * Represents a single MyDash role assignment row binding either a user or
 * a group (XOR) to one of the named MyDash roles (admin / editor / viewer).
 * Persisted in the `oc_mydash_role_assignments` table. REQ-ROLE-004.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Role assignment entity (REQ-ROLE-004).
 *
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getAssignedBy()
 * @method void setAssignedBy(?string $assignedBy)
 * @method string|null getAssignedAt()
 * @method void setAssignedAt(?string $assignedAt)
 */
class RoleAssignment extends Entity implements JsonSerializable
{

    /**
     * The Dashboard Admin role — full delegation within MyDash. REQ-ROLE-001.
     *
     * @var string
     */
    public const ROLE_ADMIN = 'admin';

    /**
     * The Dashboard Editor role — content delegation. REQ-ROLE-002.
     *
     * @var string
     */
    public const ROLE_EDITOR = 'editor';

    /**
     * The Dashboard Viewer role — read-only access. REQ-ROLE-003.
     *
     * @var string
     */
    public const ROLE_VIEWER = 'viewer';

    /**
     * All valid role values.
     *
     * @var string[]
     */
    public const VALID_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_EDITOR,
        self::ROLE_VIEWER,
    ];

    /**
     * Numeric ranks used by REQ-ROLE-005 highest-privilege-wins comparison
     * when resolving across multiple group assignments.
     *
     * @var array<string,int>
     */
    public const ROLE_RANKS = [
        self::ROLE_ADMIN  => 2,
        self::ROLE_EDITOR => 1,
        self::ROLE_VIEWER => 0,
    ];

    /**
     * Source string used by REQ-ROLE-006 when the user is a Nextcloud admin.
     *
     * @var string
     */
    public const SOURCE_NC_ADMIN = 'nc-admin';

    /**
     * Source string used by REQ-ROLE-006 when a direct user assignment exists.
     *
     * @var string
     */
    public const SOURCE_USER_ASSIGNED = 'user-assigned';

    /**
     * Source-string prefix used by REQ-ROLE-006 when the role comes from
     * a group assignment. The full source is `group-assigned:{groupId}`.
     *
     * @var string
     */
    public const SOURCE_GROUP_ASSIGNED_PREFIX = 'group-assigned:';

    /**
     * The Nextcloud user ID, or null for group assignments.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The Nextcloud group ID, or null for user assignments.
     *
     * @var string|null
     */
    protected ?string $groupId = null;

    /**
     * The role name. One of admin / editor / viewer.
     *
     * @var string|null
     */
    protected ?string $role = null;

    /**
     * The user ID of the admin who created this assignment (audit).
     *
     * @var string|null
     */
    protected ?string $assignedBy = null;

    /**
     * Assignment timestamp ('c' format ISO-8601).
     *
     * @var string|null
     */
    protected ?string $assignedAt = null;

    /**
     * Constructor — register column types so the ORM hydrates the integer ID.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
    }//end __construct()

    /**
     * Whether this row is a user assignment (`userId` is set).
     *
     * @return bool True when `userId` is populated.
     */
    public function isUserAssignment(): bool
    {
        return $this->userId !== null && $this->userId !== '';
    }//end isUserAssignment()

    /**
     * Whether this row is a group assignment (`groupId` is set).
     *
     * @return bool True when `groupId` is populated.
     */
    public function isGroupAssignment(): bool
    {
        return $this->groupId !== null && $this->groupId !== '';
    }//end isGroupAssignment()

    /**
     * Convenience accessor that returns the row's target identifier:
     * the user ID when this is a user assignment, the group ID otherwise.
     *
     * @return string|null The target ID, or null when neither is set.
     */
    public function getTarget(): ?string
    {
        if ($this->isUserAssignment() === true) {
            return $this->userId;
        }

        if ($this->isGroupAssignment() === true) {
            return $this->groupId;
        }

        return null;
    }//end getTarget()

    /**
     * Serialize to JSON. Field names mirror the API contract documented in
     * REQ-ROLE-006.
     *
     * @return array<string,mixed> The serialized assignment.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'userId'     => $this->userId,
            'groupId'    => $this->groupId,
            'role'       => $this->role,
            'assignedBy' => $this->assignedBy,
            'assignedAt' => $this->assignedAt,
        ];
    }//end jsonSerialize()
}//end class
