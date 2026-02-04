<?php

declare(strict_types=1);

/**
 * Tile Entity
 *
 * @category Database
 * @package  OCA\MyDash\Db
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getIcon()
 * @method void setIcon(string $icon)
 * @method string getIconType()
 * @method void setIconType(string $iconType)
 * @method string getBackgroundColor()
 * @method void setBackgroundColor(string $backgroundColor)
 * @method string getTextColor()
 * @method void setTextColor(string $textColor)
 * @method string getLinkType()
 * @method void setLinkType(string $linkType)
 * @method string getLinkValue()
 * @method void setLinkValue(string $linkValue)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Tile extends Entity implements JsonSerializable {
	protected string $userId = '';
	protected string $title = '';
	protected string $icon = '';
	protected string $iconType = '';
	protected string $backgroundColor = '';
	protected string $textColor = '';
	protected string $linkType = '';
	protected string $linkValue = '';
	protected ?string $createdAt = null;
	protected ?string $updatedAt = null;

	/**
	 * Constructor
	 *
	 * Registers column types for proper ORM handling.
	 */
	public function __construct() {
		$this->addType('id', 'integer');
	}

	/**
	 * Serialize to JSON
	 *
	 * @return array The serialized tile.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->userId,
			'title' => $this->title,
			'icon' => $this->icon,
			'iconType' => $this->iconType,
			'backgroundColor' => $this->backgroundColor,
			'textColor' => $this->textColor,
			'linkType' => $this->linkType,
			'linkValue' => $this->linkValue,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
