<?php

/**
 * RoleLayoutDefault Entity
 *
 * Captures the default grid position, size, and ordering for a single
 * widget within a role's seeded dashboard layout. Read by
 * `DashboardResolver::tryCreateFromTemplate()` when no admin template
 * matches a new user (REQ-RFP-002).
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
 * Role-layout-default entity (REQ-RFP-002).
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string getWidgetId()
 * @method void setWidgetId(string $widgetId)
 * @method int getGridX()
 * @method void setGridX(int $gridX)
 * @method int getGridY()
 * @method void setGridY(int $gridY)
 * @method int getGridWidth()
 * @method void setGridWidth(int $gridWidth)
 * @method int getGridHeight()
 * @method void setGridHeight(int $gridHeight)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getIsCompulsory()
 * @method void setIsCompulsory(int $isCompulsory)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class RoleLayoutDefault extends Entity implements JsonSerializable
{
    /**
     * Display name (e.g. "Manager — activiteiten").
     *
     * @var string
     */
    protected string $name = '';

    /**
     * Optional notes about why this widget appears at this position.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Nextcloud group ID this default layout slot applies to.
     *
     * @var string
     */
    protected string $groupId = '';

    /**
     * Nextcloud Dashboard widget ID to seed.
     *
     * @var string
     */
    protected string $widgetId = '';

    /**
     * Column position (0-based).
     *
     * @var int
     */
    protected int $gridX = 0;

    /**
     * Row position (0-based).
     *
     * @var int
     */
    protected int $gridY = 0;

    /**
     * Widget width in grid columns (min 1).
     *
     * @var int
     */
    protected int $gridWidth = 4;

    /**
     * Widget height in grid rows (min 1).
     *
     * @var int
     */
    protected int $gridHeight = 4;

    /**
     * Sort order within the layout (lower = rendered first).
     *
     * @var int
     */
    protected int $sortOrder = 0;

    /**
     * 0/1 — when 1, the user cannot remove this widget from a seeded layout.
     *
     * @var int
     */
    protected int $isCompulsory = 0;

    /**
     * ISO-8601 creation timestamp.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * ISO-8601 last-modification timestamp.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor — registers ORM column types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'gridX', type: 'integer');
        $this->addType(fieldName: 'gridY', type: 'integer');
        $this->addType(fieldName: 'gridWidth', type: 'integer');
        $this->addType(fieldName: 'gridHeight', type: 'integer');
        $this->addType(fieldName: 'sortOrder', type: 'integer');
        $this->addType(fieldName: 'isCompulsory', type: 'integer');
    }//end __construct()

    /**
     * Serialize to a JSON-friendly array.
     *
     * @return array The serialized representation.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->getId(),
            'name'         => $this->name,
            'description'  => $this->description,
            'groupId'      => $this->groupId,
            'widgetId'     => $this->widgetId,
            'gridX'        => $this->gridX,
            'gridY'        => $this->gridY,
            'gridWidth'    => $this->gridWidth,
            'gridHeight'   => $this->gridHeight,
            'sortOrder'    => $this->sortOrder,
            'isCompulsory' => $this->isCompulsory === 1,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
