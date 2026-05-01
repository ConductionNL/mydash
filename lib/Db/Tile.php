<?php

declare(strict_types=1);

/**
 * Tile Entity
 *
 * Represents a tile entity.
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
 * Tile entity for storing custom tile configuration.
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
class Tile extends Entity implements JsonSerializable
{

    /**
     * The user ID.
     *
     * @var string
     */
    protected string $userId = '';

    /**
     * The tile title.
     *
     * @var string
     */
    protected string $title = '';

    /**
     * The tile icon.
     *
     * @var string
     */
    protected string $icon = '';

    /**
     * The icon type.
     *
     * @var string
     */
    protected string $iconType = '';

    /**
     * The background color.
     *
     * @var string
     */
    protected string $backgroundColor = '';

    /**
     * The text color.
     *
     * @var string
     */
    protected string $textColor = '';

    /**
     * The link type.
     *
     * @var string
     */
    protected string $linkType = '';

    /**
     * The link value.
     *
     * @var string
     */
    protected string $linkValue = '';

    /**
     * The creation timestamp.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The update timestamp.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor
     *
     * Registers column types for proper ORM handling.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
    }//end __construct()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized tile.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->getId(),
            'userId'          => $this->userId,
            'title'           => $this->title,
            'icon'            => $this->icon,
            'iconType'        => $this->iconType,
            'backgroundColor' => $this->backgroundColor,
            'textColor'       => $this->textColor,
            'linkType'        => $this->linkType,
            'linkValue'       => $this->linkValue,
            'createdAt'       => $this->createdAt,
            'updatedAt'       => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
