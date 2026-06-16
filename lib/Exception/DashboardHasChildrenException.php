<?php

/**
 * DashboardHasChildrenException
 *
 * Raised by {@see \OCA\MyDash\Service\DashboardService::deleteDashboard()}
 * when the caller attempts to delete a dashboard with children but did
 * not pass `?cascade=true`. Maps to HTTP 409 with the child count so the
 * frontend can display "Delete N children?" before retrying with the
 * cascade flag (REQ-DASH-030).
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
 * Cascade-delete guard tripped — the dashboard has children
 * (REQ-DASH-030).
 */
class DashboardHasChildrenException extends Exception
{
    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    public const ERROR_CODE = 'dashboard_has_children';

    /**
     * Constructor.
     *
     * @param int $childCount The number of direct children blocking the
     *                        delete. Surfaced in the HTTP 409 response
     *                        body so the UI can display the count.
     */
    public function __construct(
        private readonly int $childCount
    ) {
        parent::__construct(
            message: 'Dashboard has '.$childCount.' children. Use ?cascade=true to delete the subtree.'
        );
    }//end __construct()

    /**
     * Returns the number of direct children blocking the delete.
     *
     * @return int The child count.
     */
    public function getChildCount(): int
    {
        return $this->childCount;
    }//end getChildCount()
}//end class
