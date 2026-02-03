<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AdminSetting>
 */
class AdminSettingMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mydash_admin_settings', AdminSetting::class);
	}

	/**
	 * Find setting by key
	 *
	 * @throws DoesNotExistException
	 */
	public function findByKey(string $key): AdminSetting {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($key)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all settings as key-value array
	 */
	public function getAllAsArray(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		$entities = $this->findEntities($qb);
		$result = [];

		foreach ($entities as $entity) {
			$result[$entity->getSettingKey()] = $entity->getValueDecoded();
		}

		return $result;
	}

	/**
	 * Set a setting value (create or update)
	 */
	public function setSetting(string $key, mixed $value): AdminSetting {
		try {
			$setting = $this->findByKey($key);
			$setting->setValueEncoded($value);
			$setting->setUpdatedAt(new DateTime());
			return $this->update($setting);
		} catch (DoesNotExistException) {
			$setting = new AdminSetting();
			$setting->setSettingKey($key);
			$setting->setValueEncoded($value);
			$setting->setUpdatedAt(new DateTime());
			return $this->insert($setting);
		}
	}

	/**
	 * Get a setting value with default
	 */
	public function getValue(string $key, mixed $default = null): mixed {
		try {
			$setting = $this->findByKey($key);
			return $setting->getValueDecoded();
		} catch (DoesNotExistException) {
			return $default;
		}
	}
}
