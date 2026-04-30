<?php

/**
 * MissingInitialStateException
 *
 * Thrown by {@see \OCA\MyDash\Service\InitialStateBuilder::apply()} when a
 * controller invokes apply() without first having set every key declared as
 * required for the chosen Page. Catching this at apply() time guarantees the
 * frontend never renders against a half-populated initial-state payload.
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

use RuntimeException;

/**
 * Raised when a required initial-state key was not set before apply().
 *
 * See REQ-INIT-001 in the initial-state-contract spec.
 */
class MissingInitialStateException extends RuntimeException
{
    /**
     * Construct a new MissingInitialStateException.
     *
     * @param string $page The page name (workspace|admin) the builder targets.
     * @param string $key  The missing required key.
     */
    public function __construct(string $page, string $key)
    {
        $message = sprintf(
            'Missing required initial-state key "%s" for page "%s". Use InitialStateBuilder setters before apply().',
            $key,
            $page
        );
        parent::__construct(message: $message);
    }//end __construct()
}//end class
