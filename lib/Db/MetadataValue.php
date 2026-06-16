<?php

/**
 * MetadataValue Entity
 *
 * Per-dashboard typed value record for the dashboard-metadata-fields
 * capability (REQ-MDFL-004, REQ-MDFL-005). Each row is the unique
 * (dashboardUuid, fieldId) tuple with a type-encoded string value.
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
 * Metadata value entity.
 *
 * @method string getDashboardUuid()
 * @method void setDashboardUuid(string $dashboardUuid)
 * @method int getFieldId()
 * @method void setFieldId(int $fieldId)
 * @method string getValue()
 * @method void setValue(string $value)
 */
class MetadataValue extends Entity implements JsonSerializable
{

    /**
     * Dashboard UUID this value belongs to.
     *
     * @var string
     */
    protected string $dashboardUuid = '';

    /**
     * Foreign key to the field-definition row.
     *
     * @var integer
     */
    protected int $fieldId = 0;

    /**
     * Type-encoded value string.
     *
     * @var string
     */
    protected string $value = '';

    /**
     * Constructor — declare ORM column types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'fieldId', type: 'integer');
    }//end __construct()

    /**
     * JSON serialise.
     *
     * @return array<string, mixed> The serialised value record.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->getId(),
            'dashboardUuid' => $this->dashboardUuid,
            'fieldId'       => $this->fieldId,
            'value'         => $this->value,
        ];
    }//end jsonSerialize()
}//end class
