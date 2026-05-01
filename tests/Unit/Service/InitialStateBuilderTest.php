<?php

/**
 * InitialStateBuilder Test
 *
 * Verifies REQ-INIT-001 (typed builder, required-key enforcement),
 * REQ-INIT-002 (schema version stamping), and REQ-INIT-003 (pass-through to
 * IInitialState::provideInitialState).
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

use OCA\MyDash\Exception\MissingInitialStateException;
use OCA\MyDash\Service\InitialStateBuilder;
use OCA\MyDash\Service\Page;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

class InitialStateBuilderTest extends TestCase
{
    private IInitialState $initialState;

    protected function setUp(): void
    {
        $this->initialState = $this->createMock(IInitialState::class);
    }

    public function testWorkspaceApplyPushesEveryKeyAndSchemaVersion(): void
    {
        $captured = [];
        $this->initialState
            ->expects($this->atLeastOnce())
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, $data) use (&$captured): void {
                $captured[$key] = $data;
            });

        $builder = new InitialStateBuilder($this->initialState, Page::WORKSPACE);
        $builder
            ->setWidgets([])
            ->setLayout([])
            ->setPrimaryGroup('default')
            ->setPrimaryGroupName('Default')
            ->setIsAdmin(false)
            ->setActiveDashboardId('')
            ->setDashboardSource('group')
            ->setGroupDashboards([])
            ->setUserDashboards([])
            ->setAllowUserDashboards(true)
            ->apply();

        $this->assertArrayHasKey('widgets', $captured);
        $this->assertArrayHasKey('layout', $captured);
        $this->assertArrayHasKey('primaryGroup', $captured);
        $this->assertArrayHasKey('primaryGroupName', $captured);
        $this->assertArrayHasKey('isAdmin', $captured);
        $this->assertArrayHasKey('activeDashboardId', $captured);
        $this->assertArrayHasKey('dashboardSource', $captured);
        $this->assertArrayHasKey('groupDashboards', $captured);
        $this->assertArrayHasKey('userDashboards', $captured);
        $this->assertArrayHasKey('allowUserDashboards', $captured);
        $this->assertArrayHasKey(InitialStateBuilder::SCHEMA_VERSION_KEY, $captured);
        $this->assertSame(
            InitialStateBuilder::INITIAL_STATE_SCHEMA_VERSION,
            $captured[InitialStateBuilder::SCHEMA_VERSION_KEY]
        );
        $this->assertSame('Default', $captured['primaryGroupName']);
        $this->assertTrue($captured['allowUserDashboards']);
    }

    public function testAdminApplyPushesEveryKeyAndSchemaVersion(): void
    {
        $captured = [];
        $this->initialState
            ->expects($this->atLeastOnce())
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, $data) use (&$captured): void {
                $captured[$key] = $data;
            });

        $builder = new InitialStateBuilder($this->initialState, Page::ADMIN);
        $builder
            ->setAllGroups([['id' => 'admin', 'displayName' => 'admin']])
            ->setConfiguredGroups(['admin'])
            ->setWidgets([])
            ->setAllowUserDashboards(false)
            ->apply();

        $this->assertCount(1, $captured['allGroups']);
        $this->assertSame(['admin'], $captured['configuredGroups']);
        $this->assertSame([], $captured['widgets']);
        $this->assertFalse($captured['allowUserDashboards']);
        $this->assertSame(
            InitialStateBuilder::INITIAL_STATE_SCHEMA_VERSION,
            $captured[InitialStateBuilder::SCHEMA_VERSION_KEY]
        );
    }

    public function testWorkspaceMissingRequiredKeyThrows(): void
    {
        $this->expectException(MissingInitialStateException::class);
        $this->expectExceptionMessageMatches('/layout/');

        $builder = new InitialStateBuilder($this->initialState, Page::WORKSPACE);
        $builder
            ->setWidgets([])
            // intentionally omit setLayout()
            ->setPrimaryGroup('default')
            ->setPrimaryGroupName('Default')
            ->setIsAdmin(false)
            ->setActiveDashboardId('')
            ->setDashboardSource('group')
            ->setGroupDashboards([])
            ->setUserDashboards([])
            ->setAllowUserDashboards(false)
            ->apply();
    }

    public function testAdminMissingRequiredKeyThrows(): void
    {
        $this->expectException(MissingInitialStateException::class);
        $this->expectExceptionMessageMatches('/configuredGroups/');

        $builder = new InitialStateBuilder($this->initialState, Page::ADMIN);
        $builder
            ->setAllGroups([])
            // intentionally omit setConfiguredGroups()
            ->setWidgets([])
            ->setAllowUserDashboards(false)
            ->apply();
    }

    public function testSchemaVersionAlwaysPushed(): void
    {
        $found = false;
        $this->initialState
            ->expects($this->atLeastOnce())
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, $data) use (&$found): void {
                if ($key === InitialStateBuilder::SCHEMA_VERSION_KEY) {
                    $found = true;
                    self::assertSame(
                        InitialStateBuilder::INITIAL_STATE_SCHEMA_VERSION,
                        $data
                    );
                }
            });

        $builder = new InitialStateBuilder($this->initialState, Page::ADMIN);
        $builder
            ->setAllGroups([])
            ->setConfiguredGroups([])
            ->setWidgets([])
            ->setAllowUserDashboards(false)
            ->apply();

        $this->assertTrue($found, 'Schema version key was not pushed');
    }
}
