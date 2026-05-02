<?php

/**
 * AdminSetting Entity
 *
 * Represents an admin setting entity.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Admin setting entity for storing key-value configuration.
 *
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string|null getSettingValue()
 * @method void setSettingValue(?string $settingValue)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class AdminSetting extends Entity implements JsonSerializable
{

    /**
     * Setting key for default permission level.
     *
     * @var string
     */
    public const KEY_DEFAULT_PERMISSION_LEVEL = 'default_permission_level';

    /**
     * Setting key for allowing user dashboards.
     *
     * @var string
     */
    public const KEY_ALLOW_USER_DASHBOARDS = 'allow_user_dashboards';

    /**
     * Setting key for allowing multiple dashboards.
     *
     * @var string
     */
    public const KEY_ALLOW_MULTIPLE_DASHBOARDS = 'allow_multiple_dashboards';

    /**
     * Setting key for default grid columns.
     *
     * @var string
     */
    public const KEY_DEFAULT_GRID_COLUMNS = 'default_grid_columns';

    /**
     * Setting key for the admin-chosen group priority order
     * (REQ-ASET-012). Persisted as a JSON string list of Nextcloud
     * group IDs in the order the admin chose; corrupt JSON resolves
     * to `[]` at the service layer (defensive read).
     *
     * @var string
     */
    public const KEY_GROUP_ORDER = 'group_order';

    /**
     * Setting key for the link-button-widget createFile extension allow-list.
     *
     * Stored as a JSON array of lowercase extensions without dots
     * (e.g. `["txt","md","docx"]`). Default values are returned by
     * {@see \OCA\MyDash\Service\FileService::getAllowedExtensions()}.
     *
     * @var string
     */
    public const KEY_LINK_CREATE_FILE_EXTENSIONS = 'link_create_file_extensions';

    /**
     * The setting key.
     *
     * @var string
     */
    protected string $settingKey = '';

    /**
     * The setting value.
     *
     * @var string|null
     */
    protected ?string $settingValue = null;

    /**
     * The update timestamp (ISO-8601 / 'c' format).
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
     * Get setting value as decoded JSON.
     *
     * @return mixed The decoded value.
     */
    public function getValueDecoded(): mixed
    {
        if ($this->settingValue === null) {
            return null;
        }

        $decoded = json_decode(json: $this->settingValue, associative: true);
        return $decoded;
    }//end getValueDecoded()

    /**
     * Set setting value from any value (will be JSON encoded).
     *
     * @param mixed $value The value to encode and store.
     *
     * @return void
     */
    public function setValueEncoded(mixed $value): void
    {
        $this->setSettingValue(json_encode($value));
    }//end setValueEncoded()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized admin setting.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->getId(),
            'key'       => $this->settingKey,
            'value'     => $this->getValueDecoded(),
            'updatedAt' => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
