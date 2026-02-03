<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Dashboard Entity
 *
 * @category Database
 * @package  OCA\MyDash\Db
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
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
class Dashboard extends Entity implements JsonSerializable {
	public const TYPE_ADMIN_TEMPLATE = 'admin_template';
	public const TYPE_USER = 'user';

	public const PERMISSION_VIEW_ONLY = 'view_only';
	public const PERMISSION_ADD_ONLY = 'add_only';
	public const PERMISSION_FULL = 'full';

	protected ?string $uuid = null;
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?string $type = self::TYPE_USER;
	protected ?string $userId = null;
	protected ?int $basedOnTemplate = null;
	protected int $gridColumns = 12;
	protected string $permissionLevel = self::PERMISSION_FULL;
	protected ?string $targetGroups = null;
	protected int $isDefault = 0; // SMALLINT in DB (0/1).
	protected int $isActive = 0; // SMALLINT in DB (0/1).
	protected ?string $createdAt = null; // Stored as string to avoid DateTime conversion issues.
	protected ?string $updatedAt = null; // Stored as string to avoid DateTime conversion issues.

	/**
	 * Constructor
	 *
	 * Registers column types for proper ORM handling.
	 * Note: is_default and is_active are SMALLINT in DB, not boolean.
	 */
	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('basedOnTemplate', 'integer');
		$this->addType('gridColumns', 'integer');
		$this->addType('isDefault', 'integer'); // SMALLINT in DB (0/1).
		$this->addType('isActive', 'integer'); // SMALLINT in DB (0/1).
	}

	/**
	 * Get target groups as array
	 */
	public function getTargetGroupsArray(): array {
		if (empty($this->targetGroups)) {
			return [];
		}
		$decoded = json_decode($this->targetGroups, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Set target groups from array
	 */
	public function setTargetGroupsArray(array $groups): void {
		$this->setTargetGroups(json_encode($groups));
	}

	/**
	 * Serialize to JSON
	 *
	 * @return array The serialized dashboard.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'type' => $this->type,
			'userId' => $this->userId,
			'basedOnTemplate' => $this->basedOnTemplate,
			'gridColumns' => $this->gridColumns,
			'permissionLevel' => $this->permissionLevel,
			'targetGroups' => $this->getTargetGroupsArray(),
			'isDefault' => $this->isDefault,
			'isActive' => $this->isActive,
			'createdAt' => $this->createdAt, // Already a string in Y-m-d H:i:s format.
			'updatedAt' => $this->updatedAt, // Already a string in Y-m-d H:i:s format.
		];
	}
}
