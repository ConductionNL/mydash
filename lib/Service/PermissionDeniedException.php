<?php

/**
 * PermissionDeniedException
 *
 * Raised by {@see ReactionService} when the calling user does not have
 * VIEW permission on the dashboard targeted by the reaction request,
 * AND by {@see BulkOperationService} when the all-or-nothing
 * permission pre-check finds at least one unauthorised dashboard in a
 * batch (REQ-BULK-011) — or when the caller is not a Nextcloud admin.
 * The controller maps this to HTTP 403; the bulk path additionally
 * surfaces the offending UUID list via {@see self::getDeniedUuids()}.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

use RuntimeException;

/**
 * Caller cannot VIEW / mutate the targeted dashboard(s).
 */
class PermissionDeniedException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string   $message     The error message surfaced to the
     *                              caller.
     * @param string[] $deniedUuids The UUIDs the caller could not act on.
     *                              Empty when the caller is not an admin
     *                              at all (no per-uuid breakdown applies)
     *                              or when the caller is a single-dashboard
     *                              reaction path that has no batch context.
     */
    public function __construct(
        string $message='',
        private readonly array $deniedUuids=[]
    ) {
        parent::__construct(message: $message);
    }//end __construct()

    /**
     * The UUIDs that caused the permission denial.
     *
     * @return string[] The denied UUID list.
     */
    public function getDeniedUuids(): array
    {
        return $this->deniedUuids;
    }//end getDeniedUuids()
}//end class
