<?php

/**
 * Page
 *
 * Enum identifying which Vue mount the initial-state payload targets. Each
 * case maps to a specific required-key set inside
 * {@see \OCA\MyDash\Service\InitialStateBuilder}.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

/**
 * Page identifier for the initial-state contract.
 *
 * See REQ-INIT-002 in the initial-state-contract spec for the per-page
 * required key set.
 */
enum Page: string
{
    case WORKSPACE = 'workspace';
    case ADMIN     = 'admin';
}//end enum
