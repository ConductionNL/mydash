<?php

/**
 * DashboardFactory Group-Shared Test
 *
 * Covers the (type, groupId) invariant guard added by REQ-DASH-011 and
 * the new keyword args on {@see DashboardFactory::create()}.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\DashboardFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the REQ-DASH-011 invariant in DashboardFactory.
 */
class DashboardFactoryGroupSharedTest extends TestCase
{
    /**
     * Factory under test.
     *
     * @var DashboardFactory
     */
    private DashboardFactory $factory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->factory = new DashboardFactory();
    }//end setUp()

    /**
     * @return void
     */
    public function testCreateGroupSharedRequiresGroupId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->create(
            userId: null,
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: null
        );
    }//end testCreateGroupSharedRequiresGroupId()

    /**
     * @return void
     */
    public function testCreateGroupSharedRejectsEmptyGroupId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->create(
            userId: null,
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: ''
        );
    }//end testCreateGroupSharedRejectsEmptyGroupId()

    /**
     * @return void
     */
    public function testCreateUserTypeRejectsGroupId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->create(
            userId: 'alice',
            name: 'Personal',
            type: Dashboard::TYPE_USER,
            groupId: 'marketing'
        );
    }//end testCreateUserTypeRejectsGroupId()

    /**
     * @return void
     */
    public function testCreateGroupSharedSetsTypeAndGroupId(): void
    {
        $dashboard = $this->factory->create(
            userId: null,
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'marketing'
        );

        $this->assertSame(
            Dashboard::TYPE_GROUP_SHARED,
            $dashboard->getType()
        );
        $this->assertSame('marketing', $dashboard->getGroupId());
        $this->assertNull($dashboard->getUserId());
    }//end testCreateGroupSharedSetsTypeAndGroupId()

    /**
     * @return void
     */
    public function testCreateGroupSharedDefaultGroup(): void
    {
        $dashboard = $this->factory->create(
            userId: null,
            name: 'Welcome',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: Dashboard::DEFAULT_GROUP_ID
        );

        $this->assertSame(
            Dashboard::DEFAULT_GROUP_ID,
            $dashboard->getGroupId()
        );
    }//end testCreateGroupSharedDefaultGroup()

    /**
     * @return void
     */
    public function testCreateGroupSharedIsNotActive(): void
    {
        $dashboard = $this->factory->create(
            userId: null,
            name: 'Welcome',
            type: Dashboard::TYPE_GROUP_SHARED,
            groupId: 'marketing'
        );

        $this->assertSame(0, $dashboard->getIsActive());
    }//end testCreateGroupSharedIsNotActive()

    /**
     * @return void
     */
    public function testCreateUserDashboardStillWorks(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Personal'
        );

        $this->assertSame(Dashboard::TYPE_USER, $dashboard->getType());
        $this->assertNull($dashboard->getGroupId());
        $this->assertSame('alice', $dashboard->getUserId());
        $this->assertSame(1, $dashboard->getIsActive());
    }//end testCreateUserDashboardStillWorks()
}//end class
