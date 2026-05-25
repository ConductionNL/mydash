<?php

/**
 * MetadataValidationService
 *
 * Validates a raw value string against a {@see MetadataField} type
 * (REQ-MDFL-006). Pure: no DB I/O, no logging, no side effects.
 *
 * Encoding contract per type (matches `dashboard-metadata-fields/spec.md`):
 *   - text          plain string (any length)
 *   - number        decimal string (e.g. `"42.5"`)
 *   - date          ISO-8601 date string (`YYYY-MM-DD`)
 *   - select        one option from `field->getOptionsArray()`
 *   - multi-select  JSON array of strings, each in `field->getOptionsArray()`
 *   - boolean       literal `"0"` or `"1"`
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Db\MetadataField;
use OCA\MyDash\Exception\InvalidMetadataFieldException;

/**
 * Per-type value validation for the dashboard-metadata-fields capability.
 */
class MetadataValidationService
{
    /**
     * Validate `$value` against the field's type and required flag.
     *
     * @param mixed         $value The raw incoming value (string, array,
     *                             null, etc.).
     * @param MetadataField $field The field definition.
     *
     * @return string The canonical encoded string ready for persistence.
     *
     * @throws InvalidMetadataFieldException When the value is invalid.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function validateValue(mixed $value, MetadataField $field): string
    {
        if (self::isEmpty(value: $value) === true) {
            if ($field->getRequired() === 1) {
                throw new InvalidMetadataFieldException(
                    message: "Field '".$field->getLabel()."' is required"
                );
            }

            return '';
        }

        return match ($field->getType()) {
            MetadataField::TYPE_TEXT         => self::asString(value: $value),
            MetadataField::TYPE_NUMBER       => self::validateNumber(value: $value, field: $field),
            MetadataField::TYPE_DATE         => self::validateDate(value: $value, field: $field),
            MetadataField::TYPE_SELECT       => self::validateSelect(value: $value, field: $field),
            MetadataField::TYPE_MULTI_SELECT => self::validateMultiSelect(value: $value, field: $field),
            MetadataField::TYPE_BOOLEAN      => self::validateBoolean(value: $value, field: $field),
            default                          => throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' has unknown type '".$field->getType()."'"
            ),
        };
    }//end validateValue()

    /**
     * Returns true when the value is null, empty string, or empty array.
     *
     * @param mixed $value The candidate value.
     *
     * @return bool True when treated as missing.
     */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) === true && $value === '') {
            return true;
        }

        if (is_array($value) === true && count($value) === 0) {
            return true;
        }

        return false;
    }//end isEmpty()

    /**
     * Cast scalar values to string (text type fall-through).
     *
     * @param mixed $value The candidate value.
     *
     * @return string The string form.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value) === true) {
            return $value;
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        if (is_bool($value) === true) {
            if ($value === true) {
                return '1';
            }

            return '0';
        }

        return '';
    }//end asString()

    /**
     * Number-type validation: numeric string only.
     *
     * @param mixed         $value The candidate value.
     * @param MetadataField $field The field definition.
     *
     * @return string The canonical numeric string.
     *
     * @throws InvalidMetadataFieldException When non-numeric.
     */
    private static function validateNumber(mixed $value, MetadataField $field): string
    {
        $candidate = self::asString(value: $value);
        if (is_numeric(value: $candidate) === false) {
            throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' must be a valid number"
            );
        }

        return $candidate;
    }//end validateNumber()

    /**
     * Date-type validation: strict YYYY-MM-DD.
     *
     * @param mixed         $value The candidate value.
     * @param MetadataField $field The field definition.
     *
     * @return string The validated date string.
     *
     * @throws InvalidMetadataFieldException When malformed.
     */
    private static function validateDate(mixed $value, MetadataField $field): string
    {
        $candidate = self::asString(value: $value);
        if (preg_match(pattern: '/^\d{4}-\d{2}-\d{2}$/', subject: $candidate) !== 1) {
            throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' must be a valid date (YYYY-MM-DD)"
            );
        }

        $parts = explode(separator: '-', string: $candidate);
        $check = checkdate(
            month: (int) $parts[1],
            day: (int) $parts[2],
            year: (int) $parts[0]
        );
        if ($check === false) {
            throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' must be a valid date (YYYY-MM-DD)"
            );
        }

        return $candidate;
    }//end validateDate()

    /**
     * Select-type validation: value MUST be in the option set.
     *
     * @param mixed         $value The candidate value.
     * @param MetadataField $field The field definition.
     *
     * @return string The validated option string.
     *
     * @throws InvalidMetadataFieldException When out of set.
     */
    private static function validateSelect(mixed $value, MetadataField $field): string
    {
        $candidate = self::asString(value: $value);
        $options   = $field->getOptionsArray();
        if (in_array(needle: $candidate, haystack: $options, strict: true) === false) {
            throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' value '".$candidate."' not in allowed options"
            );
        }

        return $candidate;
    }//end validateSelect()

    /**
     * Multi-select validation: JSON array of strings, each in option set.
     *
     * Accepts either a PHP array directly or a JSON-encoded array string.
     *
     * @param mixed         $value The candidate value.
     * @param MetadataField $field The field definition.
     *
     * @return string The canonical JSON-array string.
     *
     * @throws InvalidMetadataFieldException When malformed or out of set.
     */
    private static function validateMultiSelect(mixed $value, MetadataField $field): string
    {
        $items = $value;
        if (is_string($items) === true) {
            $decoded = json_decode(json: $items, associative: true);
            if (is_array($decoded) === true) {
                $items = $decoded;
            }
        }

        if (is_array($items) === false) {
            throw new InvalidMetadataFieldException(
                message: "Field '".$field->getLabel()."' must be an array of options"
            );
        }

        $options = $field->getOptionsArray();
        foreach ($items as $entry) {
            if (is_string($entry) === false
                || in_array(needle: $entry, haystack: $options, strict: true) === false
            ) {
                $rendered = '';
                if (is_string($entry) === true) {
                    $rendered = $entry;
                }

                throw new InvalidMetadataFieldException(
                    message: "Field '".$field->getLabel()."' value '".$rendered."' not in allowed options"
                );
            }
        }

        return (string) json_encode($items);
    }//end validateMultiSelect()

    /**
     * Boolean validation: only literal `"0"` or `"1"` accepted.
     *
     * @param mixed         $value The candidate value.
     * @param MetadataField $field The field definition.
     *
     * @return string The canonical `"0"` / `"1"`.
     *
     * @throws InvalidMetadataFieldException When malformed.
     */
    private static function validateBoolean(mixed $value, MetadataField $field): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }

        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }

        throw new InvalidMetadataFieldException(
            message: "Field '".$field->getLabel()."' must be boolean (\"0\" or \"1\")"
        );
    }//end validateBoolean()
}//end class
