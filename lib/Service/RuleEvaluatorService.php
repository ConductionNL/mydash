<?php

/**
 * RuleEvaluatorService
 *
 * Service for evaluating conditional rules against user context.
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

use DateTime;
use OCA\MyDash\Db\ConditionalRule;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service for evaluating conditional rules against user context.
 */
class RuleEvaluatorService
{
    /**
     * Constructor
     *
     * @param IGroupManager         $groupManager The group manager interface.
     * @param IUserManager          $userManager  The user manager interface.
     * @param UserAttributeResolver $attrResolver The attribute resolver.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly UserAttributeResolver $attrResolver,
    ) {
    }//end __construct()

    /**
     * Evaluate a single rule.
     *
     * @param ConditionalRule $rule   The rule to evaluate.
     * @param string          $userId The user ID.
     *
     * @return bool Whether the rule matches.
     */
    public function evaluateRule(
        ConditionalRule $rule,
        string $userId
    ): bool {
        return match ($rule->getRuleType()) {
            ConditionalRule::TYPE_GROUP => $this->evaluateGroupRule(
                rule: $rule,
                userId: $userId
            ),
            ConditionalRule::TYPE_TIME => $this->evaluateTimeRule(
                rule: $rule
            ),
            ConditionalRule::TYPE_DATE => $this->evaluateDateRule(
                rule: $rule
            ),
            ConditionalRule::TYPE_ATTRIBUTE => $this->evaluateAttributeRule(
                rule: $rule,
                userId: $userId
            ),
            default => false,
        };
    }//end evaluateRule()

    /**
     * Evaluate a group-based rule.
     * Config: { "groups": ["admin", "editors"] }.
     *
     * @param ConditionalRule $rule   The rule to evaluate.
     * @param string          $userId The user ID.
     *
     * @return bool Whether the rule matches.
     */
    private function evaluateGroupRule(
        ConditionalRule $rule,
        string $userId
    ): bool {
        $config       = $rule->getRuleConfigArray();
        $targetGroups = $config['groups'] ?? [];

        if (empty($targetGroups) === true) {
            return false;
        }

        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds(user: $user);

        return empty(array_intersect($userGroups, $targetGroups)) === false;
    }//end evaluateGroupRule()

    /**
     * Evaluate a time-based rule.
     * Config: { "startTime": "09:00", "endTime": "17:00", "days": ["mon"] }.
     *
     * @param ConditionalRule $rule The rule to evaluate.
     *
     * @return bool Whether the rule matches.
     */
    private function evaluateTimeRule(ConditionalRule $rule): bool
    {
        $config = $rule->getRuleConfigArray();

        $now         = new DateTime();
        $currentTime = $now->format(format: 'H:i');
        $currentDay  = strtolower(string: $now->format(format: 'D'));

        // Check day of week.
        if (isset($config['days']) === true
            && is_array($config['days']) === true
        ) {
            if (in_array(
                needle: $currentDay,
                haystack: $config['days']
            ) === false
            ) {
                return false;
            }
        }

        // Check time range.
        $startTime = $config['startTime'] ?? '00:00';
        $endTime   = $config['endTime'] ?? '23:59';

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }//end evaluateTimeRule()

    /**
     * Evaluate a date-based rule.
     * Config: { "startDate": "2024-01-01", "endDate": "2024-12-31" }.
     *
     * @param ConditionalRule $rule The rule to evaluate.
     *
     * @return bool Whether the rule matches.
     */
    private function evaluateDateRule(ConditionalRule $rule): bool
    {
        $config = $rule->getRuleConfigArray();

        $now         = new DateTime();
        $currentDate = $now->format(format: 'Y-m-d');

        $startDate = $config['startDate'] ?? null;
        $endDate   = $config['endDate'] ?? null;

        if ($startDate !== null && $currentDate < $startDate) {
            return false;
        }

        if ($endDate !== null && $currentDate > $endDate) {
            return false;
        }

        return true;
    }//end evaluateDateRule()

    /**
     * Evaluate an attribute-based rule.
     * Config: { "attribute": "locale", "operator": "equals", "value": "nl" }.
     *
     * @param ConditionalRule $rule   The rule to evaluate.
     * @param string          $userId The user ID.
     *
     * @return bool Whether the rule matches.
     */
    private function evaluateAttributeRule(
        ConditionalRule $rule,
        string $userId
    ): bool {
        $config = $rule->getRuleConfigArray();

        $attribute = $config['attribute'] ?? null;
        $operator  = $config['operator'] ?? 'equals';
        $value     = $config['value'] ?? null;

        if ($attribute === null) {
            return false;
        }

        $userValue = $this->attrResolver->getUserAttributeValue(
            userId: $userId,
            attribute: $attribute
        );

        if ($userValue === null) {
            return false;
        }

        return $this->attrResolver->evaluateOperator(
            userValue: $userValue,
            operator: $operator,
            value: $value
        );
    }//end evaluateAttributeRule()
}//end class
