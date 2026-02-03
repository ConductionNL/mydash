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
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string|null getSettingValue()
 * @method void setSettingValue(?string $settingValue)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class AdminSetting extends Entity implements JsonSerializable {

	// Setting keys
	public const KEY_DEFAULT_PERMISSION_LEVEL = 'default_permission_level';
	public const KEY_ALLOW_USER_DASHBOARDS = 'allow_user_dashboards';
	public const KEY_ALLOW_MULTIPLE_DASHBOARDS = 'allow_multiple_dashboards';
	public const KEY_DEFAULT_GRID_COLUMNS = 'default_grid_columns';

	protected string $settingKey = '';
	protected ?string $settingValue = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	/**
	 * Get setting value as decoded JSON
	 */
	public function getValueDecoded(): mixed {
		if ($this->settingValue === null) {
			return null;
		}
		$decoded = json_decode($this->settingValue, true);
		return $decoded;
	}

	/**
	 * Set setting value from any value (will be JSON encoded)
	 */
	public function setValueEncoded(mixed $value): void {
		$this->setSettingValue(json_encode($value));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'key' => $this->settingKey,
			'value' => $this->getValueDecoded(),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}
}
