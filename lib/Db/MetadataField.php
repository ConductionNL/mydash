<?php

/**
 * MetadataField Entity
 *
 * Global field-definition record for the dashboard-metadata-fields
 * capability (REQ-MDFL-001). Each row represents a typed, queryable
 * attribute that administrators can attach to every dashboard.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
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

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Metadata field-definition entity.
 *
 * @method string getFieldKey()
 * @method void setFieldKey(string $fieldKey)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getOptions()
 * @method void setOptions(?string $options)
 * @method int getRequired()
 * @method void setRequired(int $required)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class MetadataField extends Entity implements JsonSerializable
{
    /**
     * Free-form text value type.
     *
     * @var string
     */
    public const TYPE_TEXT = 'text';

    /**
     * Decimal-number value type (validated as numeric at write).
     *
     * @var string
     */
    public const TYPE_NUMBER = 'number';

    /**
     * ISO-8601 date string (YYYY-MM-DD).
     *
     * @var string
     */
    public const TYPE_DATE = 'date';

    /**
     * Single-choice from a defined option set.
     *
     * @var string
     */
    public const TYPE_SELECT = 'select';

    /**
     * Multiple-choice from a defined option set; stored as JSON array.
     *
     * @var string
     */
    public const TYPE_MULTI_SELECT = 'multi-select';

    /**
     * Boolean flag, encoded as the literal string `"0"` or `"1"`.
     *
     * @var string
     */
    public const TYPE_BOOLEAN = 'boolean';

    /**
     * All valid field-type discriminators. Validated by the service
     * layer; rejected with HTTP 400 when a write supplies a value
     * outside this enumeration.
     *
     * @var array<int, string>
     */
    public const VALID_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_MULTI_SELECT,
        self::TYPE_BOOLEAN,
    ];

    /**
     * Machine-name slug (lowercase alphanumeric + underscore, max 64).
     *
     * @var string
     */
    protected string $fieldKey = '';

    /**
     * Human-readable label.
     *
     * @var string
     */
    protected string $label = '';

    /**
     * Type discriminator (one of {@see VALID_TYPES}).
     *
     * @var string
     */
    protected string $type = self::TYPE_TEXT;

    /**
     * JSON-encoded options array for select / multi-select types.
     *
     * @var string|null
     */
    protected ?string $options = null;

    /**
     * Required flag (0 = optional, 1 = required).
     *
     * @var integer
     */
    protected int $required = 0;

    /**
     * Sort order for admin UI rendering and list-endpoint ordering.
     *
     * @var integer
     */
    protected int $sortOrder = 0;

    /**
     * ISO-8601 creation timestamp.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * ISO-8601 update timestamp.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor — declare ORM column types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'required', type: 'integer');
        $this->addType(fieldName: 'sortOrder', type: 'integer');
    }//end __construct()

    /**
     * Returns true when the field accepts a constrained option set.
     *
     * @return bool True for `select` and `multi-select`.
     */
    public function isSelectType(): bool
    {
        return ($this->type === self::TYPE_SELECT
            || $this->type === self::TYPE_MULTI_SELECT);
    }//end isSelectType()

    /**
     * Decode the persisted `options` JSON into a PHP array.
     *
     * Defensive: returns an empty array when the column is NULL or
     * malformed. Never throws.
     *
     * @return array<int, string> The decoded option strings.
     */
    public function getOptionsArray(): array
    {
        if ($this->options === null || $this->options === '') {
            return [];
        }

        $decoded = json_decode(json: $this->options, associative: true);
        if (is_array($decoded) === false) {
            return [];
        }

        $strings = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) === true) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }//end getOptionsArray()

    /**
     * Encode and persist an options array (or NULL).
     *
     * @param array<int, string>|null $options The option list or null.
     *
     * @return void
     */
    public function setOptionsArray(?array $options): void
    {
        if ($options === null) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $this->setOptions(null);
            return;
        }

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->setOptions(json_encode($options));
    }//end setOptionsArray()

    /**
     * JSON serialise to the API contract shape.
     *
     * @return array<string, mixed> The serialised field.
     */
    public function jsonSerialize(): array
    {
        $options = null;
        if ($this->isSelectType() === true) {
            $options = $this->getOptionsArray();
        }

        return [
            'id'        => $this->getId(),
            'key'       => $this->fieldKey,
            'label'     => $this->label,
            'type'      => $this->type,
            'options'   => $options,
            'required'  => $this->required,
            'sortOrder' => $this->sortOrder,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
