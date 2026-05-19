<?php

/**
 * Page
 *
 * Enumerates the Vue mount points whose initial-state payloads are
 * formalised by the `initial-state-contract` capability. Each case maps
 * to a distinct entry-point bundle and a distinct required-key set in
 * {@see \OCA\MyDash\Service\InitialStateBuilder}.
 *
 * Adding a new page is a deliberate spec change: extend this enum,
 * register the page's required-key set in the builder, and bump
 * `INITIAL_STATE_SCHEMA_VERSION` per REQ-INIT-002.
 *
 * @category  Service
 * @package   OCA\MyDash\Service\InitialState
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

namespace OCA\MyDash\Service\InitialState;

/**
 * Page identifier for the initial-state contract (REQ-INIT-001).
 */
enum Page: string
{
    case WORKSPACE = 'workspace';
    case ADMIN     = 'admin';
}//end enum
