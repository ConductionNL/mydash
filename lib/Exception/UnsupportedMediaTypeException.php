<?php

/**
 * UnsupportedMediaTypeException
 *
 * Raised when a non-JSON request body is sent to the resource
 * upload endpoint (e.g. multipart/form-data). Maps to HTTP 415 +
 * error code `unsupported_media_type`.
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
 * Request media type is not the expected JSON body.
 */
class UnsupportedMediaTypeException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'unsupported_media_type';

    /**
     * HTTP status.
     *
     * @var integer
     */
    protected int $httpStatus = 415;

    /**
     * Constructor.
     *
     * @param string $message Display message.
     */
    public function __construct(
        string $message='Use JSON body with base64 field'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
