<?php

/**
 * ResourceException
 *
 * Base exception for the resource-uploads capability. Carries a stable
 * machine-readable error code and a human-readable, translatable display
 * message that is safe to return to clients (never wraps raw underlying
 * exception strings).
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

use Exception;

/**
 * Base exception for resource-uploads.
 */
abstract class ResourceException extends Exception
{

    /**
     * Stable machine-readable error code for the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'unknown_error';

    /**
     * HTTP status code for the response.
     *
     * @var integer
     */
    protected int $httpStatus = 400;

    /**
     * Get the stable error code.
     *
     * @return string The machine-readable error code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }//end getErrorCode()

    /**
     * Get the HTTP status code for this exception.
     *
     * @return int The HTTP status code.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }//end getHttpStatus()

    /**
     * Get the display message safe for clients.
     *
     * The exception message itself is curated; we never use raw inner
     * messages. Subclasses should pass a translatable English string.
     *
     * @return string The display message.
     */
    public function getDisplayMessage(): string
    {
        return $this->getMessage();
    }//end getDisplayMessage()
}//end class
