<?php

/**
 * DashboardShare Entity
 *
 * Represents a dashboard share entity — a single row in the
 * oc_mydash_dashboard_shares table binding a dashboard to a user or group
 * recipient with a given permission level. REQ-SHARE-001.
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
 * Dashboard share entity.
 *
 * @method int|null getDashboardId()
 * @method void setDashboardId(?int $dashboardId)
 * @method string|null getShareType()
 * @method void setShareType(?string $shareType)
 * @method string|null getShareWith()
 * @method void setShareWith(?string $shareWith)
 * @method string|null getPermissionLevel()
 * @method void setPermissionLevel(?string $permissionLevel)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class DashboardShare extends Entity implements JsonSerializable
{

    /**
     * Share type for a single user recipient.
     *
     * @var string
     */
    public const SHARE_TYPE_USER = 'user';

    /**
     * Share type for a Nextcloud group recipient.
     *
     * @var string
     */
    public const SHARE_TYPE_GROUP = 'group';

    /**
     * Valid share types.
     *
     * @var string[]
     */
    public const VALID_SHARE_TYPES = [
        self::SHARE_TYPE_USER,
        self::SHARE_TYPE_GROUP,
    ];

    /**
     * Valid permission levels (mirrors Dashboard constants).
     *
     * @var string[]
     */
    public const VALID_PERMISSION_LEVELS = [
        Dashboard::PERMISSION_VIEW_ONLY,
        Dashboard::PERMISSION_ADD_ONLY,
        Dashboard::PERMISSION_FULL,
    ];

    /**
     * The dashboard ID.
     *
     * @var integer|null
     */
    protected ?int $dashboardId = null;

    /**
     * The share type ('user' or 'group').
     *
     * @var string|null
     */
    protected ?string $shareType = null;

    /**
     * The recipient user ID or group ID.
     *
     * @var string|null
     */
    protected ?string $shareWith = null;

    /**
     * The permission level.
     *
     * @var string|null
     */
    protected ?string $permissionLevel = null;

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
     * Constructor — registers column types.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'dashboardId', type: 'integer');
    }//end __construct()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized share.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->getId(),
            'dashboardId'     => $this->dashboardId,
            'shareType'       => $this->shareType,
            'shareWith'       => $this->shareWith,
            'permissionLevel' => $this->permissionLevel,
            'createdAt'       => $this->createdAt,
            'updatedAt'       => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
