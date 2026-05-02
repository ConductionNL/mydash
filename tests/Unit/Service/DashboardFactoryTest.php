<?php

/**
 * DashboardFactory Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\DashboardFactory;
use PHPUnit\Framework\TestCase;

class DashboardFactoryTest extends TestCase
{
    private DashboardFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DashboardFactory();
    }

    public function testCreateReturnsDashboardEntity(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'My Dashboard'
        );

        $this->assertInstanceOf(Dashboard::class, $dashboard);
    }

    public function testCreateSetsUserId(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'My Dashboard'
        );

        $this->assertSame('alice', $dashboard->getUserId());
    }

    public function testCreateSetsName(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Work Dashboard'
        );

        $this->assertSame('Work Dashboard', $dashboard->getName());
    }

    public function testCreateSetsDescription(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test',
            description: 'My test dashboard'
        );

        $this->assertSame('My test dashboard', $dashboard->getDescription());
    }

    public function testCreateSetsNullDescriptionByDefault(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertNull($dashboard->getDescription());
    }

    public function testCreateSetsTypeUser(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertSame(Dashboard::TYPE_USER, $dashboard->getType());
    }

    public function testCreateSetsGridColumns12(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertSame(12, $dashboard->getGridColumns());
    }

    public function testCreateSetsPermissionFull(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertSame(Dashboard::PERMISSION_FULL, $dashboard->getPermissionLevel());
    }

    public function testCreateRespectsExplicitPermissionLevel(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test',
            permissionLevel: Dashboard::PERMISSION_ADD_ONLY
        );

        $this->assertSame(Dashboard::PERMISSION_ADD_ONLY, $dashboard->getPermissionLevel());
    }

    public function testCreateRespectsExplicitGridColumns(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test',
            gridColumns: 6
        );

        $this->assertSame(6, $dashboard->getGridColumns());
    }

    public function testCreateSetsIsActive(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertSame(1, $dashboard->getIsActive());
    }

    public function testCreateGeneratesUuid(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $uuid = $dashboard->getUuid();
        $this->assertNotNull($uuid);
        $this->assertNotEmpty($uuid);
        // UUID v4 format: 8-4-4-4-12
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testCreateGeneratesUniqueUuids(): void
    {
        $dashboard1 = $this->factory->create(
            userId: 'alice',
            name: 'Dashboard 1'
        );
        $dashboard2 = $this->factory->create(
            userId: 'alice',
            name: 'Dashboard 2'
        );

        $this->assertNotSame(
            $dashboard1->getUuid(),
            $dashboard2->getUuid()
        );
    }

    public function testCreateSetsTimestamps(): void
    {
        $dashboard = $this->factory->create(
            userId: 'alice',
            name: 'Test'
        );

        $this->assertNotNull($dashboard->getCreatedAt());
        $this->assertNotNull($dashboard->getUpdatedAt());
        $this->assertSame(
            $dashboard->getCreatedAt(),
            $dashboard->getUpdatedAt()
        );
    }

    /**
     * REQ-DASH-011: a `group_shared` dashboard MUST carry a non-null
     * `groupId`; the factory rejects the call before any row is built.
     *
     * @return void
     */
    public function testCreateRejectsGroupSharedWithoutGroupId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create(
            userId: null,
            name: 'Bad Dashboard',
            type: \OCA\MyDash\Db\Dashboard::TYPE_GROUP_SHARED
        );
    }

    /**
     * REQ-DASH-011: a `user`-type dashboard MUST NOT carry a `groupId`.
     *
     * @return void
     */
    public function testCreateRejectsUserTypeWithGroupId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create(
            userId: 'alice',
            name: 'Bad Dashboard',
            type: \OCA\MyDash\Db\Dashboard::TYPE_USER,
            groupId: 'marketing'
        );
    }

    /**
     * REQ-DASH-011: a valid `group_shared` row carries `userId === null`,
     * the matching `groupId`, and is non-active.
     *
     * @return void
     */
    public function testCreateGroupSharedSetsUserIdNullAndIsInactive(): void
    {
        $dashboard = $this->factory->create(
            userId: null,
            name: 'Marketing Overview',
            type: \OCA\MyDash\Db\Dashboard::TYPE_GROUP_SHARED,
            groupId: 'marketing'
        );

        $this->assertSame(\OCA\MyDash\Db\Dashboard::TYPE_GROUP_SHARED, $dashboard->getType());
        $this->assertSame('marketing', $dashboard->getGroupId());
        $this->assertNull($dashboard->getUserId());
        $this->assertSame(0, $dashboard->getIsActive());
    }

    /**
     * REQ-DASH-012: the literal `'default'` is accepted as a synthetic
     * group sentinel even when no Nextcloud group with that id exists.
     *
     * @return void
     */
    public function testCreateAcceptsDefaultGroupSentinel(): void
    {
        $dashboard = $this->factory->create(
            userId: null,
            name: 'Welcome',
            type: \OCA\MyDash\Db\Dashboard::TYPE_GROUP_SHARED,
            groupId: \OCA\MyDash\Db\Dashboard::DEFAULT_GROUP_ID
        );

        $this->assertSame(
            \OCA\MyDash\Db\Dashboard::DEFAULT_GROUP_ID,
            $dashboard->getGroupId()
        );
    }
}
