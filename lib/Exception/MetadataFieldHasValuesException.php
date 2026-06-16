<?php

/**
 * MetadataFieldHasValuesException
 *
 * Raised by `MetadataService::deleteFieldDefinition()` when the caller
 * attempts to delete a field that still has dependent value rows but
 * did not pass `?cascade=true`. Maps to HTTP 409 (REQ-MDFL-003 /
 * design D5).
 *
 * @category  Exception
 * @package   OCA\MyDash\Exception
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

namespace OCA\MyDash\Exception;

use Exception;

/**
 * Cascade-delete guard tripped — the field still has values
 * (REQ-MDFL-003).
 */
class MetadataFieldHasValuesException extends Exception
{
    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    public const ERROR_CODE = 'metadata_field_has_values';

    /**
     * Constructor.
     *
     * @param int $valueCount The number of dependent value rows.
     */
    public function __construct(
        private readonly int $valueCount
    ) {
        parent::__construct(
            message: 'Metadata field has '.$valueCount.' values. Use ?cascade=true to delete them.'
        );
    }//end __construct()

    /**
     * Returns the number of dependent value rows.
     *
     * @return int The value count.
     */
    public function getValueCount(): int
    {
        return $this->valueCount;
    }//end getValueCount()
}//end class
