<?php

/**
 * FileTooLargeException
 *
 * Raised when the decoded base64 payload exceeds the 5 MB hard cap.
 * Enforced BEFORE invoking `getimagesizefromstring` to avoid loading
 * an oversize blob into the image library. Maps to HTTP 400 +
 * error code `file_too_large`.
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
 * Decoded payload exceeds the 5 MB hard cap.
 */
class FileTooLargeException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'file_too_large';

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
    public function __construct(string $message='Maximum size is 5MB')
    {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
