<?php

declare(strict_types=1);

/**
 * Dashboard Entity
 *
 * Represents a dashboard entity.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Dashboard entity for storing dashboard configuration.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method int|null getBasedOnTemplate()
 * @method void setBasedOnTemplate(?int $basedOnTemplate)
 * @method int getGridColumns()
 * @method void setGridColumns(int $gridColumns)
 * @method string getPermissionLevel()
 * @method void setPermissionLevel(string $permissionLevel)
 * @method string|null getTargetGroups()
 * @method void setTargetGroups(?string $targetGroups)
 * @method int getIsDefault()
 * @method void setIsDefault(int $isDefault)
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Dashboard extends Entity implements JsonSerializable
{

    /**
     * Dashboard type for admin templates.
     *
     * @var string
     */
    public const TYPE_ADMIN_TEMPLATE = 'admin_template';

    /**
     * Dashboard type for user dashboards.
     *
     * @var string
     */
    public const TYPE_USER = 'user';

    /**
     * Dashboard type for group-shared dashboards.
     *
     * Group-shared dashboards are admin-authored, scoped to a single
     * Nextcloud group via the {@see Dashboard::$groupId} field, and
     * rendered live (not copied) to every member of that group.
     * REQ-DASH-011.
     *
     * @var string
     */
    public const TYPE_GROUP_SHARED = 'group_shared';

    /**
     * Synthetic group sentinel meaning "visible to every user".
     *
     * Reserved literal value for the {@see Dashboard::$groupId} field
     * on group-shared dashboards. REQ-DASH-012.
     *
     * @var string
     */
    public const DEFAULT_GROUP_ID = 'default';

    /**
     * Source tag indicating a personal user-owned dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_USER = 'user';

    /**
     * Source tag indicating a group-matched group-shared dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_GROUP = 'group';

    /**
     * Source tag indicating a default-group group-shared dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * Permission level for view only.
     *
     * @var string
     */
    public const PERMISSION_VIEW_ONLY = 'view_only';

    /**
     * Permission level for add only.
     *
     * @var string
     */
    public const PERMISSION_ADD_ONLY = 'add_only';

    /**
     * Permission level for full access.
     *
     * @var string
     */
    public const PERMISSION_FULL = 'full';

    /**
     * The UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The dashboard type.
     *
     * @var string|null
     */
    protected ?string $type = self::TYPE_USER;

    /**
     * The user ID.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The group ID for group-shared dashboards.
     *
     * Populated only when {@see Dashboard::$type} equals
     * {@see Dashboard::TYPE_GROUP_SHARED}. The literal value
     * {@see Dashboard::DEFAULT_GROUP_ID} is reserved as a "visible to
     * every user" sentinel. REQ-DASH-011, REQ-DASH-012.
     *
     * @var string|null
     */
    protected ?string $groupId = null;

    /**
     * The template ID this dashboard is based on.
     *
     * @var integer|null
     */
    protected ?int $basedOnTemplate = null;

    /**
     * The number of grid columns.
     *
     * @var integer
     */
    protected int $gridColumns = 12;

    /**
     * The permission level.
     *
     * @var string
     */
    protected string $permissionLevel = self::PERMISSION_FULL;

    /**
     * The target groups JSON.
     *
     * @var string|null
     */
    protected ?string $targetGroups = null;

    /**
     * Whether this is the default (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isDefault = 0;

    /**
     * Whether this is active (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isActive = 0;

    /**
     * The creation timestamp as string.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The update timestamp as string.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor
     *
     * Registers column types for proper ORM handling.
     * Note: is_default and is_active are SMALLINT in DB, not boolean.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'basedOnTemplate', type: 'integer');
        $this->addType(fieldName: 'gridColumns', type: 'integer');
        $this->addType(fieldName: 'isDefault', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'isActive', type: 'integer');
        // SMALLINT in DB (0/1).
    }//end __construct()

    /**
     * Get target groups as array.
     *
     * @return array The decoded target groups.
     */
    public function getTargetGroupsArray(): array
    {
        if (empty($this->targetGroups) === true) {
            return [];
        }

        $decoded = json_decode(json: $this->targetGroups, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getTargetGroupsArray()

    /**
     * Set target groups from array.
     *
     * @param array $groups The target groups array.
     *
     * @return void
     */
    public function setTargetGroupsArray(array $groups): void
    {
        // Entity setters resolve via __call which uses $args[0]; named args
        // would break the magic forwarding (see project memory).
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->setTargetGroups(json_encode($groups));
    }//end setTargetGroupsArray()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized dashboard.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->getId(),
            'uuid'            => $this->uuid,
            'name'            => $this->name,
            'description'     => $this->description,
            'type'            => $this->type,
            'userId'          => $this->userId,
            'groupId'         => $this->groupId,
            'basedOnTemplate' => $this->basedOnTemplate,
            'gridColumns'     => $this->gridColumns,
            'permissionLevel' => $this->permissionLevel,
            'targetGroups'    => $this->getTargetGroupsArray(),
            'isDefault'       => $this->isDefault,
            'isActive'        => $this->isActive,
            'createdAt'       => $this->createdAt,
            'updatedAt'       => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
