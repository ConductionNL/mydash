<?php

/**
 * MimeMismatchException
 *
 * Raised when the declared image type in the data URL prefix does
 * not match the MIME type detected by `getimagesizefromstring` for
 * raster types. Maps to HTTP 400 + error code `mime_mismatch`.
 *
 * @category  Exception
 * @package   OCA\MyDash\Exception
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

namespace OCA\MyDash\Exception;

/**
 * Declared image type does not match the detected MIME type.
 */
class MimeMismatchException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'mime_mismatch';

    /**
     * HTTP status.
     *
     * @var integer
     */
    protected int $httpStatus = 400;

    /**
     * Constructor.
     *
     * @param string $message Display message.
     */
    public function __construct(
        string $message='Declared image type does not match file content'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
