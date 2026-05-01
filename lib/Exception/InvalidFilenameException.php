<?php

/**
 * InvalidFilenameException
 *
 * Raised when the supplied filename fails the strict regex validation
 * or contains path-traversal characters. Maps to HTTP 400 + error code
 * `invalid_filename`.
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

/**
 * Supplied filename is empty, too long, or contains disallowed characters.
 */
class InvalidFilenameException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_filename';

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
    public function __construct(string $message='Invalid filename')
    {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
