<?php

/**
 * ForbiddenException
 *
 * Raised when a non-admin user attempts to invoke an admin-only
 * resource endpoint. Maps to HTTP 403 + error code `forbidden`.
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
 * Admin-only endpoint accessed by a non-admin user.
 */
class ForbiddenException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'forbidden';

    /**
     * HTTP status.
     *
     * @var integer
     */
    protected int $httpStatus = 403;

    /**
     * Constructor.
     *
     * @param string $message Display message.
     */
    public function __construct(string $message='Admin privileges required')
    {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
