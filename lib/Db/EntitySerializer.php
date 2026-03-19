<?php

/**
 * EntitySerializer
 *
 * Utility class for serializing database entities.
 *
 * @category  Db
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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Utility class for serializing database entities.
 */
class EntitySerializer
{
    /**
     * Serialize an array of entities to arrays.
     *
     * @param array<Entity&JsonSerializable> $entities The entities to serialize.
     *
     * @return array<int, array<string, mixed>> The serialized data.
     */
    public function serializeList(array $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            $result[] = $entity->jsonSerialize();
        }

        return $result;
    }//end serializeList()

    /**
     * Serialize a single entity to array.
     *
     * @param Entity&JsonSerializable $entity The entity to serialize.
     *
     * @return array<string, mixed> The serialized data.
     */
    public function serialize(Entity&JsonSerializable $entity): array
    {
        return $entity->jsonSerialize();
    }//end serialize()

    /**
     * Extract specific fields from an entity's serialized form.
     *
     * @param Entity&JsonSerializable $entity The entity.
     * @param array<string>           $fields The field names to extract.
     *
     * @return array<string, mixed> The extracted fields.
     */
    public function extractFields(Entity&JsonSerializable $entity, array $fields): array
    {
        $serialized = $entity->jsonSerialize();
        $result     = [];

        foreach ($fields as $field) {
            if (array_key_exists(key: $field, array: $serialized) === true) {
                $result[$field] = $serialized[$field];
            }
        }

        return $result;
    }//end extractFields()
}//end class
