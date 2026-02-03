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
 * @method int getWidgetPlacementId()
 * @method void setWidgetPlacementId(int $widgetPlacementId)
 * @method string getRuleType()
 * @method void setRuleType(string $ruleType)
 * @method string|null getRuleConfig()
 * @method void setRuleConfig(?string $ruleConfig)
 * @method bool getIsInclude()
 * @method void setIsInclude(bool $isInclude)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class ConditionalRule extends Entity implements JsonSerializable {

	// Rule types
	public const TYPE_GROUP = 'group';
	public const TYPE_TIME = 'time';
	public const TYPE_DATE = 'date';
	public const TYPE_ATTRIBUTE = 'attribute';

	protected int $widgetPlacementId = 0;
	protected string $ruleType = '';
	protected ?string $ruleConfig = null;
	protected bool $isInclude = true;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('widgetPlacementId', 'integer');
		$this->addType('isInclude', 'boolean');
	}

	/**
	 * Get rule config as array
	 */
	public function getRuleConfigArray(): array {
		if (empty($this->ruleConfig)) {
			return [];
		}
		$decoded = json_decode($this->ruleConfig, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Set rule config from array
	 */
	public function setRuleConfigArray(array $config): void {
		$this->setRuleConfig(json_encode($config));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'widgetPlacementId' => $this->widgetPlacementId,
			'ruleType' => $this->ruleType,
			'ruleConfig' => $this->getRuleConfigArray(),
			'isInclude' => $this->isInclude,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
