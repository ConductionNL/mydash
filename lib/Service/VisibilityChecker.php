<?php

/**
 * VisibilityChecker
 *
 * Service for checking widget visibility based on conditional rules.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Db\ConditionalRule;

/**
 * Service for checking widget visibility based on conditional rules.
 */
class VisibilityChecker
{
    /**
     * Constructor
     *
     * @param RuleEvaluatorService $ruleEvaluator The rule evaluator service.
     */
    public function __construct(
        private readonly RuleEvaluatorService $ruleEvaluator,
    ) {
    }//end __construct()

    /**
     * Check rules to determine visibility.
     *
     * Include rules use OR logic (at least one must match).
     * Exclude rules use AND logic (any match hides the widget).
     *
     * @param ConditionalRule[] $rules  The rules to check.
     * @param string            $userId The user ID.
     *
     * @return bool Whether the widget should be visible.
     */
    public function checkRules(array $rules, string $userId): bool
    {
        $includeRules = $this->filterByType(
            rules: $rules,
            isInclude: true
        );
        $excludeRules = $this->filterByType(
            rules: $rules,
            isInclude: false
        );

        if ($this->passesIncludeRules(
            rules: $includeRules,
            userId: $userId
        ) === false
        ) {
            return false;
        }

        return $this->passesExcludeRules(
            rules: $excludeRules,
            userId: $userId
        );
    }//end checkRules()

    /**
     * Filter rules by include/exclude type.
     *
     * @param ConditionalRule[] $rules     The rules to filter.
     * @param bool              $isInclude Whether to get include rules.
     *
     * @return ConditionalRule[] The filtered rules.
     */
    private function filterByType(array $rules, bool $isInclude): array
    {
        $filtered = [];
        foreach ($rules as $rule) {
            if ($rule->getIsInclude() === $isInclude) {
                $filtered[] = $rule;
            }
        }

        return $filtered;
    }//end filterByType()

    /**
     * Check if include rules pass (at least one must match).
     *
     * @param ConditionalRule[] $rules  The include rules.
     * @param string            $userId The user ID.
     *
     * @return bool Whether include rules pass.
     */
    private function passesIncludeRules(
        array $rules,
        string $userId
    ): bool {
        if (empty($rules) === true) {
            return true;
        }

        foreach ($rules as $rule) {
            if ($this->ruleEvaluator->evaluateRule(
                rule: $rule,
                userId: $userId
            ) === true
            ) {
                return true;
            }
        }

        return false;
    }//end passesIncludeRules()

    /**
     * Check if exclude rules pass (none must match).
     *
     * @param ConditionalRule[] $rules  The exclude rules.
     * @param string            $userId The user ID.
     *
     * @return bool Whether exclude rules pass.
     */
    private function passesExcludeRules(
        array $rules,
        string $userId
    ): bool {
        foreach ($rules as $rule) {
            if ($this->ruleEvaluator->evaluateRule(
                rule: $rule,
                userId: $userId
            ) === true
            ) {
                return false;
            }
        }

        return true;
    }//end passesExcludeRules()
}//end class
