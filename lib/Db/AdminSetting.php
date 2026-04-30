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

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Admin setting entity for storing key-value configuration.
 *
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string|null getSettingValue()
 * @method void setSettingValue(?string $settingValue)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
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
     * Setting key for the ordered list of "active" Nextcloud group IDs that
     * MyDash treats as in scope for workspace routing (REQ-ASET-012).
     *
     * @var string
     */
    public const KEY_GROUP_ORDER = 'group_order';

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
     * The update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

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
        // Entity setters resolve via __call which forwards $args[0]; named
        // parameters MUST NOT be used here (Entity __call would receive
        // $args = ['paramName' => $value] and use the wrong key).
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->setSettingValue(json_encode($value));
    }//end setValueEncoded()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized admin setting.
     */
    public function jsonSerialize(): array
    {
        $updatedAtValue = null;
        if ($this->updatedAt !== null) {
            $updatedAtValue = $this->updatedAt->format(format: 'c');
        }

        return [
            'id'        => $this->getId(),
            'key'       => $this->settingKey,
            'value'     => $this->getValueDecoded(),
            'updatedAt' => $updatedAtValue,
        ];
    }//end jsonSerialize()
}//end class
