<?php

/**
 * MissingInitialStateException
 *
 * Raised by {@see \OCA\MyDash\Service\InitialStateBuilder::apply()} when a
 * required initial-state key was not set for the chosen page before the
 * builder was applied. The exception message names the page and the
 * missing key, so the failure is actionable in dev and CI alike. Catching
 * this at apply() time guarantees the frontend never renders against a
 * half-populated initial-state payload.
 *
 * Maps to HTTP 500 in production (the page cannot render without its
 * boot snapshot); the CI test suite catches the same condition earlier
 * via the per-page builder tests.
 *
 * Part of the `initial-state-contract` capability — see REQ-INIT-001.
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

use RuntimeException;

/**
 * Required initial-state key was not set on the builder before apply (REQ-INIT-001).
 */
class MissingInitialStateException extends RuntimeException
{

    /**
     * Stable error code for the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'missing_initial_state_key';

    /**
     * HTTP status code.
     *
     * @var integer
     */
    protected int $httpStatus = 500;

    /**
     * Constructor.
     *
     * @param string $page The page identifier (e.g. 'workspace', 'admin').
     * @param string $key  The missing required key.
     */
    public function __construct(string $page, string $key)
    {
        $template  = 'MyDash initial-state contract violation: page "%s" requires key "%s"';
        $template .= ' but it was not set on the builder before apply().';
        parent::__construct(message: sprintf($template, $page, $key));
    }//end __construct()

    /**
     * Get the stable error code.
     *
     * @return string The error code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }//end getErrorCode()

    /**
     * Get the HTTP status code.
     *
     * @return integer The status code.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }//end getHttpStatus()
}//end class
