<?php

/**
 * InvalidRoleAssignmentException
 *
 * Raised when a role assignment payload is structurally invalid: the role
 * name is unknown, neither user nor group is set, both are set, or the
 * referenced user/group does not exist. Maps to HTTP 400 with the stable
 * error code `invalid_role_assignment`. REQ-ROLE-004.
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
 * Invalid role-assignment payload (REQ-ROLE-004).
 */
class InvalidRoleAssignmentException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_role_assignment';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 400;

    /**
     * Constructor.
     *
     * @param string $message Display message (translatable English string).
     */
    public function __construct(
        string $message='Role assignment payload is invalid'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
