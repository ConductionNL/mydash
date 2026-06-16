<?php

/**
 * LockNotFoundException
 *
 * Raised by {@see \OCA\MyDash\Service\DashboardLockService} when an
 * operation that requires an active lock (heartbeat, query) is invoked
 * against a dashboard that has none (or only an expired one). Maps to
 * HTTP 404 (REQ-LOCK-002, REQ-LOCK-004).
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

/**
 * No active lock exists for the requested dashboard (REQ-LOCK-002).
 */
class LockNotFoundException extends Exception
{
    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    public const ERROR_CODE = 'lock_not_found';
}//end class
