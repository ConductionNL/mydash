<?php

/**
 * JsonConfigHelper
 *
 * Helper for encoding and decoding JSON configuration fields.
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

/**
 * Helper for encoding and decoding JSON configuration fields.
 */
class JsonConfigHelper
{
    /**
     * Decode a JSON string to an array.
     *
     * Returns an empty array if the string is empty or not valid JSON.
     *
     * @param string|null $json The JSON string.
     *
     * @return array The decoded array.
     */
    public static function decodeToArray(?string $json): array
    {
        if (empty($json) === true) {
            return [];
        }

        $decoded = json_decode(json: $json, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decodeToArray()

    /**
     * Encode a value to a JSON string.
     *
     * @param mixed $value The value to encode.
     *
     * @return string The JSON string.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(value: $value);
    }//end encode()

    /**
     * Decode a JSON string to its native type.
     *
     * @param string|null $json The JSON string.
     *
     * @return mixed The decoded value or null.
     */
    public static function decode(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }

        return json_decode(json: $json, associative: true);
    }//end decode()
}//end class
