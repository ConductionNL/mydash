<?php

/**
 * ConditionalService
 *
 * Service for managing conditional rules on widget placements.
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

use DateTime;
use OCA\MyDash\Db\ConditionalRule;
use OCA\MyDash\Db\ConditionalRuleMapper;
use OCA\MyDash\Db\WidgetPlacement;

/**
 * Service for managing conditional rules on widget placements.
 */
class ConditionalService
{
    /**
     * Constructor
     *
     * @param ConditionalRuleMapper $ruleMapper        The conditional rule mapper.
     * @param RuleEvaluatorService  $ruleEvaluator     The rule evaluator service.
     * @param VisibilityChecker     $visibilityChecker The visibility checker.
     */
    public function __construct(
        private readonly ConditionalRuleMapper $ruleMapper,
        private readonly RuleEvaluatorService $ruleEvaluator,
        private readonly VisibilityChecker $visibilityChecker,
    ) {
    }//end __construct()

    /**
     * Check if a widget placement should be visible for a user.
     *
     * @param WidgetPlacement $placement The widget placement.
     * @param string          $userId    The user ID.
     *
     * @return bool Whether the widget is visible.
     */
    public function isWidgetVisible(
        WidgetPlacement $placement,
        string $userId
    ): bool {
        if ($placement->getIsVisible() === false) {
            return false;
        }

        $rules = $this->ruleMapper->findByPlacementId(
            placementId: $placement->getId()
        );

        if (empty($rules) === true) {
            return true;
        }

        return $this->visibilityChecker->checkRules(
            rules: $rules,
            userId: $userId
        );
    }//end isWidgetVisible()

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
        return $this->ruleEvaluator->evaluateRule(
            rule: $rule,
            userId: $userId
        );
    }//end evaluateRule()

    /**
     * Get rules for a placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return ConditionalRule[] The list of rules.
     */
    public function getRules(int $placementId): array
    {
        return $this->ruleMapper->findByPlacementId(
            placementId: $placementId
        );
    }//end getRules()

    /**
     * Add a rule to a placement.
     *
     * @param int    $placementId The placement ID.
     * @param string $ruleType    The rule type.
     * @param array  $ruleConfig  The rule configuration.
     * @param bool   $isInclude   Whether this is an include rule.
     *
     * @return ConditionalRule The created rule.
     */
    public function addRule(
        int $placementId,
        string $ruleType,
        array $ruleConfig,
        bool $isInclude=true
    ): ConditionalRule {
        $rule = new ConditionalRule();
        $rule->setWidgetPlacementId(
            $placementId
        );
        $rule->setRuleType($ruleType);
        $rule->setRuleConfigArray($ruleConfig);
        $rule->setIsInclude($isInclude);
        $rule->setCreatedAt(new DateTime());

        return $this->ruleMapper->insert(entity: $rule);
    }//end addRule()

    /**
     * Update a rule.
     *
     * @param int   $ruleId The rule ID.
     * @param array $data   The data to update.
     *
     * @return ConditionalRule The updated rule.
     */
    public function updateRule(int $ruleId, array $data): ConditionalRule
    {
        $rule = $this->ruleMapper->find(id: $ruleId);

        if (isset($data['ruleType']) === true) {
            $rule->setRuleType($data['ruleType']);
        }

        if (isset($data['ruleConfig']) === true) {
            $rule->setRuleConfigArray($data['ruleConfig']);
        }

        if (isset($data['isInclude']) === true) {
            $rule->setIsInclude($data['isInclude']);
        }

        return $this->ruleMapper->update(entity: $rule);
    }//end updateRule()

    /**
     * Delete a rule.
     *
     * @param int $ruleId The rule ID.
     *
     * @return void
     */
    public function deleteRule(int $ruleId): void
    {
        $rule = $this->ruleMapper->find(id: $ruleId);
        $this->ruleMapper->delete(entity: $rule);
    }//end deleteRule()
}//end class
