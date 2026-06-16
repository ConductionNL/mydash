<?php

/**
 * CommentNotFoundException
 *
 * Raised when a comment lookup by ID returns no row, or when a parent
 * reference targets a non-existent comment. Maps to HTTP 404 with the
 * stable error code `comment_not_found`. REQ-CMNT-003, REQ-CMNT-004,
 * REQ-CMNT-005.
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
 * Comment not found (REQ-CMNT-003..005).
 */
class CommentNotFoundException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'comment_not_found';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 404;

    /**
     * Constructor.
     *
     * @param string $message Display message (translatable English string).
     */
    public function __construct(
        string $message='Comment not found'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
