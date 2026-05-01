<?php

/**
 * PersonalDashboardsDisabledException
 *
 * Raised when a user attempts to create a personal dashboard while the
 * admin setting `allow_user_dashboards` is disabled. Maps to HTTP 403
 * with stable error code `personal_dashboards_disabled`.
 *
 * @category  Exception
 * @package   OCA\MyDash\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Exception;

/**
 * Personal-dashboard creation blocked by admin flag (REQ-ASET-003).
 */
class PersonalDashboardsDisabledException extends ResourceException
{

    /**
     * Stable error code returned in the response envelope.
     *
     * @var string
     */
    protected string $errorCode = 'personal_dashboards_disabled';

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
        string $message='Personal dashboards are not enabled by your administrator'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
