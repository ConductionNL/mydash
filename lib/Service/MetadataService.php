<?php

/**
 * MetadataService
 *
 * Orchestrates the dashboard-metadata-fields capability
 * (REQ-MDFL-001..008): admin field-definition CRUD, per-dashboard
 * read/write, type validation, orphan-tolerant reads, soft/cascade
 * delete, and `?metadata.<key>=…` filter resolution.
 *
 * Pure facade — never touches HTTP. The controllers translate the
 * thrown exceptions into status codes (400, 403, 409).
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

use DateTime;
use OCA\MyDash\Db\MetadataField;
use OCA\MyDash\Db\MetadataFieldMapper;
use OCA\MyDash\Db\MetadataValue;
use OCA\MyDash\Db\MetadataValueMapper;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Exception\MetadataFieldHasValuesException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Coordinator for the dashboard-metadata-fields capability.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Orchestrates two
 *  mappers + the validation service + the logger; this is the
 *  single facade for the capability.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The full CRUD +
 *  read/write/filter surface is intentionally co-located here so
 *  controllers stay thin.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Per-type
 *  filter dispatch + multi-shape patch handling drive complexity;
 *  splitting would scatter the capability's invariants across
 *  multiple classes for no readability gain.
 */
class MetadataService
{
    /**
     * Maximum field-key length (matches schema column).
     *
     * @var int
     */
    private const MAX_KEY_LENGTH = 64;

    /**
     * Maximum label length (matches schema column).
     *
     * @var int
     */
    private const MAX_LABEL_LENGTH = 255;

    /**
     * Constructor.
     *
     * @param MetadataFieldMapper       $fieldMapper       The field mapper.
     * @param MetadataValueMapper       $valueMapper       The value mapper.
     * @param MetadataValidationService $validationService The validator.
     * @param LoggerInterface           $logger            PSR logger.
     */
    public function __construct(
        private readonly MetadataFieldMapper $fieldMapper,
        private readonly MetadataValueMapper $valueMapper,
        private readonly MetadataValidationService $validationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return all field definitions ordered by `sortOrder`.
     *
     * @return MetadataField[] The sorted field list.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function listFields(): array
    {
        return $this->fieldMapper->findAll();
    }//end listFields()

    /**
     * Look up a field definition by id or throw.
     *
     * @param int $id The field id.
     *
     * @return MetadataField The matching field.
     *
     * @throws DoesNotExistException When missing.
     */
    public function getField(int $id): MetadataField
    {
        return $this->fieldMapper->findById(id: $id);
    }//end getField()

    /**
     * Create a new field definition with full validation
     * (REQ-MDFL-001).
     *
     * @param string                  $key       The slugified key.
     * @param string                  $label     The display label.
     * @param string                  $type      One of {@see MetadataField::VALID_TYPES}.
     * @param array<int, string>|null $options   Option set (select types only).
     * @param int                     $required  0 / 1.
     * @param int                     $sortOrder UI sort order.
     *
     * @return MetadataField The persisted entity.
     *
     * @throws InvalidMetadataFieldException When validation fails.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function createFieldDefinition(
        string $key,
        string $label,
        string $type,
        ?array $options=null,
        int $required=0,
        int $sortOrder=0
    ): MetadataField {
        self::assertKeyShape(key: $key);
        self::assertLabelShape(label: $label);
        self::assertTypeShape(type: $type);
        self::assertOptionsShape(type: $type, options: $options);

        try {
            $this->fieldMapper->findByKey(key: $key);
            throw new InvalidMetadataFieldException(
                message: "Field key '".$key."' already exists"
            );
        } catch (DoesNotExistException) {
            // Expected — key is free.
        }

        $now          = (new DateTime())->format(format: 'c');
        $field        = new MetadataField();
        $requiredFlag = 0;
        if ($required === 1) {
            $requiredFlag = 1;
        }

        // Entity __call routes setter args via $args[0]; named params would
        // land in the wrong slot. Per-line phpcs ignore avoids the
        // codebase-wide named-args sniff that fires on every setter call.
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $field->setFieldKey($key);
        $field->setLabel($label);
        $field->setType($type);
        $field->setRequired($requiredFlag);
        $field->setSortOrder($sortOrder);
        $field->setCreatedAt($now);
        $field->setUpdatedAt($now);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        if ($options !== null) {
            $field->setOptionsArray(options: $options);
        } else {
            $field->setOptionsArray(options: null);
        }

        return $this->fieldMapper->insert(entity: $field);
    }//end createFieldDefinition()

    /**
     * Update an existing field definition (REQ-MDFL-002).
     *
     * The `key` slug is immutable — supplying it triggers a 400.
     * Allowed patch keys: `label`, `sortOrder`, `required`, `options`.
     *
     * @param int                  $id    The field id.
     * @param array<string, mixed> $patch The shallow patch.
     *
     * @return MetadataField The persisted entity.
     *
     * @throws DoesNotExistException        When the field is missing.
     * @throws InvalidMetadataFieldException When validation fails.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function updateFieldDefinition(int $id, array $patch): MetadataField
    {
        if (array_key_exists(key: 'key', array: $patch) === true) {
            throw new InvalidMetadataFieldException(
                message: 'Field key cannot be renamed'
            );
        }

        $field = $this->fieldMapper->findById(id: $id);

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        if (array_key_exists(key: 'label', array: $patch) === true) {
            $label = (string) $patch['label'];
            self::assertLabelShape(label: $label);
            $field->setLabel($label);
        }

        if (array_key_exists(key: 'sortOrder', array: $patch) === true) {
            $field->setSortOrder((int) $patch['sortOrder']);
        }

        if (array_key_exists(key: 'required', array: $patch) === true) {
            $required = 0;
            if ((int) $patch['required'] === 1) {
                $required = 1;
            }

            $field->setRequired($required);
        }

        if (array_key_exists(key: 'options', array: $patch) === true) {
            $options = $patch['options'];
            if ($options !== null && is_array($options) === false) {
                throw new InvalidMetadataFieldException(
                    message: 'Options must be an array of strings or null'
                );
            }

            self::assertOptionsShape(type: $field->getType(), options: $options);
            $field->setOptionsArray(options: $options);
        }

        $field->setUpdatedAt((new DateTime())->format(format: 'c'));
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        return $this->fieldMapper->update(entity: $field);
    }//end updateFieldDefinition()

    /**
     * Delete a field definition (REQ-MDFL-003).
     *
     * Soft-by-default: when the field has dependent value rows the
     * caller MUST opt in via `$cascade = true`, otherwise a 409 is
     * raised.
     *
     * @param int  $id      The field id.
     * @param bool $cascade Whether to cascade-delete dependent values.
     *
     * @return bool True on success.
     *
     * @throws DoesNotExistException             When the field is missing.
     * @throws MetadataFieldHasValuesException   When values exist and
     *                                            cascade is false.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function deleteFieldDefinition(int $id, bool $cascade=false): bool
    {
        $field      = $this->fieldMapper->findById(id: $id);
        $valueCount = $this->fieldMapper->countValuesForField(fieldId: $id);

        if ($valueCount > 0 && $cascade === false) {
            throw new MetadataFieldHasValuesException(valueCount: $valueCount);
        }

        if ($cascade === true) {
            return $this->fieldMapper->deleteWithCascade(fieldId: $id);
        }

        $this->fieldMapper->delete(entity: $field);
        return true;
    }//end deleteFieldDefinition()

    /**
     * Read every metadata value for the dashboard, as a flat key→value
     * object (REQ-MDFL-004). Orphan rows referencing a deleted field
     * are silently skipped (and logged at warning level) so the
     * dashboard load never crashes.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return array<string, string> The flat key→encoded-value map.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function getMetadataForDashboard(string $dashboardUuid): array
    {
        $rows = $this->valueMapper->findByDashboard(dashboardUuid: $dashboardUuid);
        if (count($rows) === 0) {
            return [];
        }

        $fieldIds = [];
        foreach ($rows as $row) {
            $fieldIds[] = (int) $row->getFieldId();
        }

        $fieldsById = $this->fieldMapper->findByIds(ids: $fieldIds);

        $result = [];
        foreach ($rows as $row) {
            $fieldId = (int) $row->getFieldId();
            if (array_key_exists(key: $fieldId, array: $fieldsById) === false) {
                $this->logger->warning(
                    message: 'Orphaned dashboard metadata value (no field definition)',
                    context: [
                        'dashboardUuid' => $dashboardUuid,
                        'fieldId'       => $fieldId,
                    ]
                );
                continue;
            }

            $field = $fieldsById[$fieldId];
            $result[$field->getFieldKey()] = $row->getValue();
        }

        return $result;
    }//end getMetadataForDashboard()

    /**
     * Upsert each (key → value) entry for the dashboard
     * (REQ-MDFL-005, REQ-MDFL-006). Unknown keys raise 400. Omitted
     * keys are NOT removed — only keys present in the payload are
     * touched.
     *
     * @param string               $dashboardUuid The dashboard UUID.
     * @param array<string, mixed> $keyValues     The patch payload.
     *
     * @return array<string, string> The full updated metadata object.
     *
     * @throws InvalidMetadataFieldException When any key is unknown
     *                                       or value invalid.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function setMetadataForDashboard(
        string $dashboardUuid,
        array $keyValues
    ): array {
        foreach ($keyValues as $key => $value) {
            $stringKey = (string) $key;
            if ($stringKey === '') {
                throw new InvalidMetadataFieldException(
                    message: 'Metadata keys must be non-empty strings'
                );
            }

            try {
                $field = $this->fieldMapper->findByKey(key: $stringKey);
            } catch (DoesNotExistException) {
                throw new InvalidMetadataFieldException(
                    message: "Unknown metadata field '".$stringKey."'"
                );
            }

            $encoded = $this->validationService->validateValue(
                value: $value,
                field: $field
            );

            if ($encoded === '' && $field->getRequired() === 0) {
                // Empty optional value: remove the row so the read
                // payload omits the key (matches scenario "omitted
                // keys are not deleted" by allowing explicit empty
                // to clear the value — keeps client UX coherent).
                $existing = $this->valueMapper->findOne(
                    dashboardUuid: $dashboardUuid,
                    fieldId: (int) $field->getId()
                );
                if ($existing !== null) {
                    $this->valueMapper->delete(entity: $existing);
                }

                continue;
            }

            $this->valueMapper->upsert(
                dashboardUuid: $dashboardUuid,
                fieldId: (int) $field->getId(),
                value: $encoded
            );
        }//end foreach

        return $this->getMetadataForDashboard(dashboardUuid: $dashboardUuid);
    }//end setMetadataForDashboard()

    /**
     * Apply `?metadata.<key>=…` filters to a dashboard list
     * (REQ-MDFL-007). Filter keys not registered as fields are
     * ignored (so a stale URL never silently empties the list when
     * a field is deleted).
     *
     * Recognised filter shapes per type:
     *   - text / select / boolean        — exact-match string
     *   - multi-select                   — substring of JSON-encoded value
     *   - number                         — `"min"` / `"max"` keys (inclusive)
     *   - date                           — `"after"` / `"before"` keys (inclusive)
     *
     * @param array<int, mixed>    $dashboards      The candidate dashboards
     *                                              (each MUST expose
     *                                              `getUuid()`).
     * @param array<string, mixed> $metadataFilters The filter set
     *                                              (raw
     *                                              `metadata.<key>`
     *                                              map).
     *
     * @return array<int, mixed> The filtered subset.
     */
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function filterDashboards(
        array $dashboards,
        array $metadataFilters
    ): array {
        if (count($metadataFilters) === 0 || count($dashboards) === 0) {
            return $dashboards;
        }

        $resolved = [];
        foreach ($metadataFilters as $key => $criterion) {
            try {
                $field = $this->fieldMapper->findByKey(key: (string) $key);
            } catch (DoesNotExistException) {
                continue;
            }

            $resolved[] = ['field' => $field, 'criterion' => $criterion];
        }

        if (count($resolved) === 0) {
            return $dashboards;
        }

        $matchingByField = [];
        foreach ($resolved as $entry) {
            $field     = $entry['field'];
            $criterion = $entry['criterion'];

            $matching = [];
            foreach ($this->valueMapper->findByField(fieldId: (int) $field->getId()) as $row) {
                if (self::matchesCriterion(
                    field: $field,
                    storedValue: $row->getValue(),
                    criterion: $criterion
                ) === true
                ) {
                    $matching[$row->getDashboardUuid()] = true;
                }
            }

            $matchingByField[] = $matching;
        }

        // AND the per-filter sets together.
        $intersection  = $matchingByField[0];
        $matchingCount = count($matchingByField);
        for ($i = 1; $i < $matchingCount; $i++) {
            $intersection = array_intersect_key(
                $intersection,
                $matchingByField[$i]
            );
        }

        $filtered = [];
        foreach ($dashboards as $dashboard) {
            $uuid = self::extractUuid(dashboard: $dashboard);
            if ($uuid === null) {
                continue;
            }

            if (array_key_exists(key: $uuid, array: $intersection) === true) {
                $filtered[] = $dashboard;
            }
        }

        return $filtered;
    }//end filterDashboards()

    /**
     * Pull a UUID off a dashboard entity OR a serialised array row.
     *
     * @param mixed $dashboard The candidate dashboard.
     *
     * @return string|null The UUID or null when unresolvable.
     */
    private static function extractUuid(mixed $dashboard): ?string
    {
        if (is_object($dashboard) === true && method_exists($dashboard, 'getUuid') === true) {
            $uuid = $dashboard->getUuid();
            if ($uuid === null) {
                return null;
            }

            return (string) $uuid;
        }

        if (is_array($dashboard) === true && array_key_exists(key: 'uuid', array: $dashboard) === true) {
            return (string) $dashboard['uuid'];
        }

        return null;
    }//end extractUuid()

    /**
     * Per-criterion match logic.
     *
     * @param MetadataField $field       The field definition.
     * @param string        $storedValue The persisted value string.
     * @param mixed         $criterion   The raw filter value.
     *
     * @return bool True when the value satisfies the criterion.
     */
    private static function matchesCriterion(
        MetadataField $field,
        string $storedValue,
        mixed $criterion
    ): bool {
        return match ($field->getType()) {
            MetadataField::TYPE_NUMBER       => self::matchesNumberRange(
                stored: $storedValue,
                criterion: $criterion
            ),
            MetadataField::TYPE_DATE         => self::matchesDateRange(
                stored: $storedValue,
                criterion: $criterion
            ),
            MetadataField::TYPE_MULTI_SELECT => self::matchesMultiSelect(
                stored: $storedValue,
                criterion: $criterion
            ),
            default                          => self::matchesExact(
                stored: $storedValue,
                criterion: $criterion
            ),
        };
    }//end matchesCriterion()

    /**
     * Exact-string match for text / select / boolean.
     *
     * @param string $stored    The persisted value.
     * @param mixed  $criterion The filter value.
     *
     * @return bool True on equality.
     */
    private static function matchesExact(string $stored, mixed $criterion): bool
    {
        if (is_array($criterion) === true) {
            return false;
        }

        return ($stored === (string) $criterion);
    }//end matchesExact()

    /**
     * Numeric range filter — supports `min` / `max` (inclusive) or
     * scalar exact-match.
     *
     * @param string $stored    The persisted decimal string.
     * @param mixed  $criterion The filter value.
     *
     * @return bool True on match.
     */
    private static function matchesNumberRange(string $stored, mixed $criterion): bool
    {
        if (is_numeric(value: $stored) === false) {
            return false;
        }

        $value = (float) $stored;

        if (is_array($criterion) === true) {
            if (array_key_exists(key: 'min', array: $criterion) === true
                && $value < (float) $criterion['min']
            ) {
                return false;
            }

            if (array_key_exists(key: 'max', array: $criterion) === true
                && $value > (float) $criterion['max']
            ) {
                return false;
            }

            return true;
        }

        if (is_numeric(value: $criterion) === false) {
            return false;
        }

        return ($value === (float) $criterion);
    }//end matchesNumberRange()

    /**
     * Date range filter — supports `after` / `before` (inclusive) or
     * exact-match.
     *
     * @param string $stored    The persisted ISO-8601 date.
     * @param mixed  $criterion The filter value.
     *
     * @return bool True on match.
     */
    private static function matchesDateRange(string $stored, mixed $criterion): bool
    {
        if (is_array($criterion) === true) {
            if (array_key_exists(key: 'after', array: $criterion) === true
                && strcmp(
                    string1: $stored,
                    string2: (string) $criterion['after']
                ) < 0
            ) {
                return false;
            }

            if (array_key_exists(key: 'before', array: $criterion) === true
                && strcmp(
                    string1: $stored,
                    string2: (string) $criterion['before']
                ) > 0
            ) {
                return false;
            }

            return true;
        }//end if

        return ($stored === (string) $criterion);
    }//end matchesDateRange()

    /**
     * Multi-select containment: the stored value is a JSON array;
     * the criterion is matched as substring of the JSON encoding so
     * `?metadata.tags=news` matches an array containing `"news"`.
     *
     * Documented limitation in design.md (D6 risks): the substring
     * match is sufficient for the MVP; v2 will switch to JSON_CONTAINS
     * or PHP-side array containment.
     *
     * @param string $stored    The persisted JSON-array string.
     * @param mixed  $criterion The filter value.
     *
     * @return bool True on match.
     */
    private static function matchesMultiSelect(string $stored, mixed $criterion): bool
    {
        if (is_array($criterion) === true) {
            return false;
        }

        $needle  = (string) $criterion;
        $decoded = json_decode(json: $stored, associative: true);
        if (is_array($decoded) === true) {
            return in_array(needle: $needle, haystack: $decoded, strict: true);
        }

        return str_contains(haystack: $stored, needle: $needle);
    }//end matchesMultiSelect()

    /**
     * Validate the slug shape: lowercase alphanumeric + underscore,
     * 1..MAX_KEY_LENGTH characters.
     *
     * @param string $key The candidate slug.
     *
     * @return void
     *
     * @throws InvalidMetadataFieldException When malformed.
     */
    private static function assertKeyShape(string $key): void
    {
        if ($key === '' || strlen(string: $key) > self::MAX_KEY_LENGTH) {
            throw new InvalidMetadataFieldException(
                message: 'Field key must be 1..'.self::MAX_KEY_LENGTH.' characters'
            );
        }

        if (preg_match(pattern: '/^[a-z0-9_]+$/', subject: $key) !== 1) {
            throw new InvalidMetadataFieldException(
                message: 'Field key must be lowercase alphanumeric with underscores only'
            );
        }
    }//end assertKeyShape()

    /**
     * Validate label length.
     *
     * @param string $label The candidate label.
     *
     * @return void
     *
     * @throws InvalidMetadataFieldException When malformed.
     */
    private static function assertLabelShape(string $label): void
    {
        if ($label === '' || strlen(string: $label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidMetadataFieldException(
                message: 'Field label must be 1..'.self::MAX_LABEL_LENGTH.' characters'
            );
        }
    }//end assertLabelShape()

    /**
     * Validate that the type is in the supported enum.
     *
     * @param string $type The candidate type.
     *
     * @return void
     *
     * @throws InvalidMetadataFieldException When unsupported.
     */
    private static function assertTypeShape(string $type): void
    {
        if (in_array(needle: $type, haystack: MetadataField::VALID_TYPES, strict: true) === false) {
            throw new InvalidMetadataFieldException(
                message: "Unsupported field type '".$type."'"
            );
        }
    }//end assertTypeShape()

    /**
     * Validate that `options` matches the type: select types REQUIRE
     * a non-empty string array; non-select types REQUIRE NULL.
     *
     * @param string                 $type    The field type.
     * @param array<int, mixed>|null $options The candidate options (validated entry-by-entry).
     *
     * @return void
     *
     * @throws InvalidMetadataFieldException When mismatched.
     */
    private static function assertOptionsShape(string $type, ?array $options): void
    {
        $isSelect = ($type === MetadataField::TYPE_SELECT
            || $type === MetadataField::TYPE_MULTI_SELECT);

        if ($isSelect === true) {
            if ($options === null || count($options) === 0) {
                throw new InvalidMetadataFieldException(
                    message: 'Select type requires non-empty options array'
                );
            }

            // Defensive runtime check: callers may pass non-string entries.
            foreach ($options as $option) {
                if (is_string($option) === false || $option === '') {
                    throw new InvalidMetadataFieldException(
                        message: 'Select options must be non-empty strings'
                    );
                }
            }

            return;
        }

        if ($options !== null && count($options) > 0) {
            throw new InvalidMetadataFieldException(
                message: "Type '".$type."' does not support options"
            );
        }
    }//end assertOptionsShape()
}//end class
