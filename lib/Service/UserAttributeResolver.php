<?php

/**
 * UserAttributeResolver
 *
 * Service for resolving user attribute values and evaluating operators.
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

use OCP\IConfig;
use OCP\IUserManager;

/**
 * Service for resolving user attribute values and evaluating operators.
 */
class UserAttributeResolver
{
    /**
     * Constructor
     *
     * @param IUserManager $userManager The user manager interface.
     * @param IConfig      $config      Config service for per-user prefs (e.g. language).
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
    ) {
    }//end __construct()

    /**
     * Get a user attribute value by name.
     *
     * @param string $userId    The user ID.
     * @param string $attribute The attribute name.
     *
     * @return string|null The attribute value or null.
     */
    public function getUserAttributeValue(
        string $userId,
        string $attribute
    ): ?string {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return null;
        }

        return match ($attribute) {
            'locale' => $this->config->getUserValue(
                userId: $userId,
                appName: 'core',
                key: 'lang',
                default: 'en'
            ),
            'email' => $user->getEMailAddress(),
            'displayName' => $user->getDisplayName(),
            'quota' => (string) $user->getQuota(),
            default => null,
        };
    }//end getUserAttributeValue()

    /**
     * Evaluate a comparison operator against a value.
     *
     * @param string      $userValue The user's attribute value.
     * @param string      $operator  The comparison operator.
     * @param string|null $value     The target comparison value.
     *
     * @return bool Whether the comparison matches.
     */
    public function evaluateOperator(
        string $userValue,
        string $operator,
        ?string $value
    ): bool {
        return match ($operator) {
            'equals' => $userValue === $value,
            'not_equals' => $userValue !== $value,
            'contains' => str_contains(
                haystack: $userValue,
                needle: $value ?? ''
            ),
            'starts_with' => str_starts_with(
                haystack: $userValue,
                needle: $value ?? ''
            ),
            'ends_with' => str_ends_with(
                haystack: $userValue,
                needle: $value ?? ''
            ),
            default => false,
        };
    }//end evaluateOperator()
}//end class
