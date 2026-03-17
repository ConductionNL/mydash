<?php

/**
 * AdminSettingMapper
 *
 * Database mapper for admin setting entities.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * AdminSettingMapper
 *
 * Mapper for admin setting entities.
 *
 * @extends QBMapper<AdminSetting>
 */
class AdminSettingMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_admin_settings',
            entityClass: AdminSetting::class
        );
    }//end __construct()

    /**
     * Find setting by key.
     *
     * @param string $key The setting key.
     *
     * @return AdminSetting The found setting.
     *
     * @throws DoesNotExistException If not found.
     */
    public function findByKey(string $key): AdminSetting
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'setting_key',
                    y: $qb->createNamedParameter(value: $key)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByKey()

    /**
     * Get all settings as key-value array.
     *
     * @return array The settings as key-value pairs.
     */
    public function getAllAsArray(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName());

        $entities = $this->findEntities(query: $qb);
        $result   = [];

        foreach ($entities as $entity) {
            $result[$entity->getSettingKey()] = $entity->getValueDecoded();
        }

        return $result;
    }//end getAllAsArray()

    /**
     * Set a setting value (create or update).
     *
     * @param string $key   The setting key.
     * @param mixed  $value The setting value.
     *
     * @return AdminSetting The created or updated setting.
     */
    public function setSetting(string $key, mixed $value): AdminSetting
    {
        try {
            $setting = $this->findByKey(key: $key);
            $setting->setValueEncoded($value);
            $setting->setUpdatedAt(new DateTime());
            return $this->update(entity: $setting);
        } catch (DoesNotExistException) {
            $setting = new AdminSetting();
            $setting->setSettingKey($key);
            $setting->setValueEncoded($value);
            $setting->setUpdatedAt(new DateTime());
            return $this->insert(entity: $setting);
        }
    }//end setSetting()

    /**
     * Get a setting value with default.
     *
     * @param string $key     The setting key.
     * @param mixed  $default The default value.
     *
     * @return mixed The setting value or default.
     */
    public function getValue(string $key, mixed $default=null): mixed
    {
        try {
            $setting = $this->findByKey(key: $key);
            return $setting->getValueDecoded();
        } catch (DoesNotExistException) {
            return $default;
        }
    }//end getValue()
}//end class
