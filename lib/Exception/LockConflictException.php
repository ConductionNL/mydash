<?php

/**
 * LockConflictException
 *
 * Raised by {@see \OCA\MyDash\Service\DashboardLockService::acquireLock()}
 * when a different user already holds an active editing lock on the
 * dashboard. Maps to HTTP 409 with the existing lock object as the
 * response body so the frontend can show "{displayName} is editing"
 * (REQ-LOCK-001).
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

use Exception;
use OCA\MyDash\Db\DashboardLock;

/**
 * Lock acquisition refused — a different user already holds an active
 * lock on the dashboard (REQ-LOCK-001).
 */
class LockConflictException extends Exception
{
    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    public const ERROR_CODE = 'lock_conflict';

    /**
     * Constructor.
     *
     * @param string        $message      Human-readable error message.
     * @param DashboardLock $existingLock The existing lock that blocked
     *                                    the acquire — surfaced verbatim
     *                                    in the 409 response body.
     */
    public function __construct(
        string $message,
        private readonly DashboardLock $existingLock,
    ) {
        parent::__construct(message: $message);
    }//end __construct()

    /**
     * The existing lock that triggered the conflict.
     *
     * @return DashboardLock The existing lock.
     */
    public function getExistingLock(): DashboardLock
    {
        return $this->existingLock;
    }//end getExistingLock()
}//end class
