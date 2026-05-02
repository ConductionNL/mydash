<?php

/**
 * InvalidDirectoryException
 *
 * Raised when the supplied target directory for `POST /api/files/create`
 * contains a path-traversal sequence (`..`) or a null byte. Maps to
 * HTTP 400 + error code `invalid_directory`.
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
 * Directory failed strict validation.
 */
class InvalidDirectoryException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_directory';

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
        string $message='Invalid directory'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
