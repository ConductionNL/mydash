<?php

/**
 * NoAccessException
 *
 * Thrown when the viewing user lacks the permission required for the
 * action they have requested via the files widget — read access on the
 * configured folder, write access on an upload, delete access on a
 * file, or because the placement disables that action entirely
 * (REQ-FLS-004 / REQ-FLS-007 / REQ-FLS-008).
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
 * Files-widget access-denied condition.
 */
class NoAccessException extends ResourceException
{

    /**
     * Stable error code surfaced to the API consumer.
     *
     * @var string
     */
    protected string $errorCode = 'no_access';

    /**
     * HTTP status code used by the controller envelope.
     *
     * @var integer
     */
    protected int $httpStatus = 403;

    /**
     * Constructor.
     *
     * @param string $message Optional override message.
     */
    public function __construct(string $message="You don't have access to this folder")
    {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
