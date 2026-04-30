<?php

/**
 * VisibilityChecker Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\ConditionalRule;
use OCA\MyDash\Service\RuleEvaluatorService;
use OCA\MyDash\Service\VisibilityChecker;
use PHPUnit\Framework\TestCase;

class VisibilityCheckerTest extends TestCase
{
    private VisibilityChecker $checker;
    private RuleEvaluatorService $ruleEvaluator;

    protected function setUp(): void
    {
        $this->ruleEvaluator = $this->createMock(RuleEvaluatorService::class);
        $this->checker = new VisibilityChecker(
            ruleEvaluator: $this->ruleEvaluator,
        );
    }

    private function createRule(bool $isInclude): ConditionalRule
    {
        $rule = new ConditionalRule();
        $rule->setIsInclude($isInclude);
        $rule->setRuleType('group');
        return $rule;
    }

    public function testNoRulesReturnsVisible(): void
    {
        $this->assertTrue(
            $this->checker->checkRules(rules: [], userId: 'alice')
        );
    }

    public function testSingleIncludeRuleMatching(): void
    {
        $rule = $this->createRule(true);
        $this->ruleEvaluator->method('evaluateRule')->willReturn(true);

        $this->assertTrue(
            $this->checker->checkRules(rules: [$rule], userId: 'alice')
        );
    }

    public function testSingleIncludeRuleNotMatching(): void
    {
        $rule = $this->createRule(true);
        $this->ruleEvaluator->method('evaluateRule')->willReturn(false);

        $this->assertFalse(
            $this->checker->checkRules(rules: [$rule], userId: 'alice')
        );
    }

    public function testIncludeRulesOrLogicOneMatches(): void
    {
        $rule1 = $this->createRule(true);
        $rule2 = $this->createRule(true);

        $this->ruleEvaluator->method('evaluateRule')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->assertTrue(
            $this->checker->checkRules(
                rules: [$rule1, $rule2],
                userId: 'alice'
            )
        );
    }

    public function testIncludeRulesOrLogicNoneMatch(): void
    {
        $rule1 = $this->createRule(true);
        $rule2 = $this->createRule(true);

        $this->ruleEvaluator->method('evaluateRule')->willReturn(false);

        $this->assertFalse(
            $this->checker->checkRules(
                rules: [$rule1, $rule2],
                userId: 'alice'
            )
        );
    }

    public function testSingleExcludeRuleMatching(): void
    {
        $rule = $this->createRule(false);
        $this->ruleEvaluator->method('evaluateRule')->willReturn(true);

        $this->assertFalse(
            $this->checker->checkRules(rules: [$rule], userId: 'alice')
        );
    }

    public function testSingleExcludeRuleNotMatching(): void
    {
        $rule = $this->createRule(false);
        $this->ruleEvaluator->method('evaluateRule')->willReturn(false);

        $this->assertTrue(
            $this->checker->checkRules(rules: [$rule], userId: 'alice')
        );
    }

    public function testExcludeRulesAndLogicAnyMatchHides(): void
    {
        $rule1 = $this->createRule(false);
        $rule2 = $this->createRule(false);

        $this->ruleEvaluator->method('evaluateRule')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->assertFalse(
            $this->checker->checkRules(
                rules: [$rule1, $rule2],
                userId: 'alice'
            )
        );
    }

    public function testMixedRulesIncludePassesExcludeFails(): void
    {
        $include = $this->createRule(true);
        $exclude = $this->createRule(false);

        $this->ruleEvaluator->method('evaluateRule')
            ->willReturnCallback(function ($rule) {
                // Include rule matches, exclude rule also matches
                return true;
            });

        // Include passes (OR: one matches), but exclude fails (match = hide)
        $this->assertFalse(
            $this->checker->checkRules(
                rules: [$include, $exclude],
                userId: 'alice'
            )
        );
    }

    public function testMixedRulesIncludePassesExcludePasses(): void
    {
        $include = $this->createRule(true);
        $exclude = $this->createRule(false);

        $this->ruleEvaluator->method('evaluateRule')
            ->willReturnCallback(function ($rule) {
                // Include matches, exclude does NOT match
                return $rule->getIsInclude();
            });

        $this->assertTrue(
            $this->checker->checkRules(
                rules: [$include, $exclude],
                userId: 'alice'
            )
        );
    }
}
