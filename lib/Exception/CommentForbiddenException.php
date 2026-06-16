<?php

/**
 * CommentForbiddenException
 *
 * Raised when the calling user lacks the necessary permission to mutate
 * a comment (edit / delete by non-author non-admin) or when comments are
 * effectively disabled for the dashboard. Maps to HTTP 403 with the
 * stable error code `comment_forbidden`. REQ-CMNT-002, REQ-CMNT-004,
 * REQ-CMNT-005, REQ-CMNT-007, REQ-CMNT-009.
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
 * Comment mutation refused (REQ-CMNT-002, REQ-CMNT-004, REQ-CMNT-005,
 * REQ-CMNT-007, REQ-CMNT-009).
 */
class CommentForbiddenException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'comment_forbidden';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 403;

    /**
     * Constructor.
     *
     * @param string $message Display message (translatable English string).
     */
    public function __construct(
        string $message='Comment mutation refused'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
