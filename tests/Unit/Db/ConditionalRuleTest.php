<?php

/**
 * ConditionalRule Entity Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\MyDash\Db\ConditionalRule;
use PHPUnit\Framework\TestCase;

class ConditionalRuleTest extends TestCase
{
    private ConditionalRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ConditionalRule();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->rule->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('integer', $fieldTypes['widgetPlacementId']);
        $this->assertSame('boolean', $fieldTypes['isInclude']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame(0, $this->rule->getWidgetPlacementId());
        $this->assertSame('', $this->rule->getRuleType());
        $this->assertNull($this->rule->getRuleConfig());
        $this->assertTrue($this->rule->getIsInclude());
        $this->assertNull($this->rule->getCreatedAt());
    }

    public function testConstants(): void
    {
        $this->assertSame('group', ConditionalRule::TYPE_GROUP);
        $this->assertSame('time', ConditionalRule::TYPE_TIME);
        $this->assertSame('date', ConditionalRule::TYPE_DATE);
        $this->assertSame('attribute', ConditionalRule::TYPE_ATTRIBUTE);
    }

    public function testSetAndGetWidgetPlacementId(): void
    {
        $this->rule->setWidgetPlacementId(10);
        $this->assertSame(10, $this->rule->getWidgetPlacementId());
    }

    public function testSetAndGetRuleType(): void
    {
        $this->rule->setRuleType(ConditionalRule::TYPE_GROUP);
        $this->assertSame('group', $this->rule->getRuleType());

        $this->rule->setRuleType(ConditionalRule::TYPE_TIME);
        $this->assertSame('time', $this->rule->getRuleType());

        $this->rule->setRuleType(ConditionalRule::TYPE_DATE);
        $this->assertSame('date', $this->rule->getRuleType());

        $this->rule->setRuleType(ConditionalRule::TYPE_ATTRIBUTE);
        $this->assertSame('attribute', $this->rule->getRuleType());
    }

    public function testSetAndGetIsInclude(): void
    {
        $this->rule->setIsInclude(false);
        $this->assertFalse($this->rule->getIsInclude());

        $this->rule->setIsInclude(true);
        $this->assertTrue($this->rule->getIsInclude());
    }

    public function testGetRuleConfigArrayEmpty(): void
    {
        $this->assertSame([], $this->rule->getRuleConfigArray());
    }

    public function testGetRuleConfigArrayWithGroupConfig(): void
    {
        $config = ['groups' => ['admin', 'marketing']];
        $this->rule->setRuleConfig(json_encode($config));
        $this->assertSame($config, $this->rule->getRuleConfigArray());
    }

    public function testGetRuleConfigArrayWithTimeConfig(): void
    {
        $config = [
            'startTime' => '09:00',
            'endTime' => '17:00',
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        ];
        $this->rule->setRuleConfig(json_encode($config));
        $this->assertSame($config, $this->rule->getRuleConfigArray());
    }

    public function testGetRuleConfigArrayWithDateConfig(): void
    {
        $config = [
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-31',
        ];
        $this->rule->setRuleConfig(json_encode($config));
        $this->assertSame($config, $this->rule->getRuleConfigArray());
    }

    public function testGetRuleConfigArrayWithAttributeConfig(): void
    {
        $config = [
            'attribute' => 'language',
            'operator' => 'equals',
            'value' => 'nl',
        ];
        $this->rule->setRuleConfig(json_encode($config));
        $this->assertSame($config, $this->rule->getRuleConfigArray());
    }

    public function testGetRuleConfigArrayWithInvalidJson(): void
    {
        $this->rule->setRuleConfig('not-valid-json');
        $this->assertSame([], $this->rule->getRuleConfigArray());
    }

    public function testSetRuleConfigArray(): void
    {
        $config = ['groups' => ['editors']];
        $this->rule->setRuleConfigArray($config);
        $this->assertSame($config, $this->rule->getRuleConfigArray());
    }

    public function testJsonSerialize(): void
    {
        $now = new DateTime();
        $this->rule->setWidgetPlacementId(10);
        $this->rule->setRuleType(ConditionalRule::TYPE_GROUP);
        $this->rule->setRuleConfigArray(['groups' => ['admin']]);
        $this->rule->setIsInclude(true);
        $this->rule->setCreatedAt($now);

        $serialized = $this->rule->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame(10, $serialized['widgetPlacementId']);
        $this->assertSame('group', $serialized['ruleType']);
        $this->assertSame(['groups' => ['admin']], $serialized['ruleConfig']);
        $this->assertTrue($serialized['isInclude']);
        $this->assertSame($now->format('c'), $serialized['createdAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testJsonSerializeNullCreatedAt(): void
    {
        $serialized = $this->rule->jsonSerialize();
        $this->assertNull($serialized['createdAt']);
    }
}
