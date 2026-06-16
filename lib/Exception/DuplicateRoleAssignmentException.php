<?php

/**
 * DuplicateRoleAssignmentException
 *
 * Raised when an admin attempts to create a role assignment that already
 * exists for the same target (user or group) and role. Maps to HTTP 409
 * with the stable error code `duplicate_role_assignment`. REQ-ROLE-004.
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
 * Duplicate role assignment (REQ-ROLE-004).
 */
class DuplicateRoleAssignmentException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'duplicate_role_assignment';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 409;

    /**
     * Constructor.
     *
     * @param string $message Display message (translatable English string).
     */
    public function __construct(
        string $message='That target already has the requested role assigned'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
