<?php

/**
 * InvalidCommentException
 *
 * Raised when a comment payload is structurally invalid: empty message,
 * non-integer parent id, or a reply-to-reply attempt that violates the
 * one-level-deep nesting guard. Maps to HTTP 400 with the stable error
 * code `invalid_comment`. REQ-CMNT-002, REQ-CMNT-003.
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
 * Invalid comment payload (REQ-CMNT-002, REQ-CMNT-003).
 */
class InvalidCommentException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_comment';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 400;

    /**
     * Constructor.
     *
     * @param string $message   Display message (translatable English string).
     * @param string $errorCode Optional override for the error code so the
     *                          spec scenarios can distinguish empty-message
     *                          from nested-reply errors.
     */
    public function __construct(
        string $message='Comment payload is invalid',
        string $errorCode='invalid_comment'
    ) {
        $this->errorCode = $errorCode;
        parent::__construct(message: $message);
    }//end __construct()
}//end class
