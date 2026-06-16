<?php

/**
 * RoleServiceTest
 *
 * Unit tests for the RoleService that owns effective-role resolution,
 * source tracking, validation, assignment CRUD and cascade cleanup
 * (REQ-ROLE-001..011).
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

use OCA\MyDash\Db\RoleAssignment;
use OCA\MyDash\Db\RoleAssignmentMapper;
use OCA\MyDash\Exception\DuplicateRoleAssignmentException;
use OCA\MyDash\Exception\InvalidRoleAssignmentException;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RoleService.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class RoleServiceTest extends TestCase
{
    /** @var RoleAssignmentMapper&MockObject */
    private $mapper;
    /** @var IUserManager&MockObject */
    private $userManager;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var AdminTemplateService&MockObject */
    private $adminTemplateService;

    private RoleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper               = $this->createMock(RoleAssignmentMapper::class);
        $this->userManager          = $this->createMock(IUserManager::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $this->adminTemplateService = $this->createMock(AdminTemplateService::class);

        $this->service = new RoleService(
            mapper: $this->mapper,
            userManager: $this->userManager,
            groupManager: $this->groupManager,
            adminTemplateService: $this->adminTemplateService,
        );
    }

    private function makeAssignment(
        ?string $userId,
        ?string $groupId,
        string $role
    ): RoleAssignment {
        $assignment = new RoleAssignment();
        $assignment->setUserId($userId);
        $assignment->setGroupId($groupId);
        $assignment->setRole($role);
        $assignment->setAssignedBy('admin-user');
        $assignment->setAssignedAt('2026-05-02T12:00:00+00:00');
        return $assignment;
    }

    // ==================================================================
    // Effective-role resolution (REQ-ROLE-005)
    // ==================================================================

    public function testNcAdminAlwaysGetsAdminRole(): void
    {
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);

        // No mapper lookup is required for NC admins.
        $this->mapper->expects($this->never())->method('findByUser');
        $this->mapper->expects($this->never())->method('findByGroupIds');

        $this->assertSame(
            RoleAssignment::ROLE_ADMIN,
            $this->service->getEffectiveRole(userId: 'alice')
        );
        $this->assertSame(
            RoleAssignment::SOURCE_NC_ADMIN,
            $this->service->getRoleSource(userId: 'alice')
        );
    }

    public function testDirectUserAssignmentUsedAsIs(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->with('bob')->willReturn(
            [$this->makeAssignment('bob', null, RoleAssignment::ROLE_VIEWER)]
        );

        // Group lookups MUST be skipped per REQ-ROLE-005 step 2.
        $this->mapper->expects($this->never())->method('findByGroupIds');
        $this->adminTemplateService->expects($this->never())->method('getUserGroupIdsFor');

        $this->assertSame(
            RoleAssignment::ROLE_VIEWER,
            $this->service->getEffectiveRole(userId: 'bob')
        );
    }

    public function testDirectAssignmentBeatsHigherGroupRole(): void
    {
        // REQ-ROLE-009 scenario 1: direct viewer beats group admin.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->with('bob')->willReturn(
            [$this->makeAssignment('bob', null, RoleAssignment::ROLE_VIEWER)]
        );

        $this->mapper->expects($this->never())->method('findByGroupIds');

        $this->assertSame(
            RoleAssignment::ROLE_VIEWER,
            $this->service->getEffectiveRole(userId: 'bob')
        );
        $this->assertSame(
            RoleAssignment::SOURCE_USER_ASSIGNED,
            $this->service->getRoleSource(userId: 'bob')
        );
    }

    public function testHighestGroupRoleWins(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->willReturn([]);

        $this->adminTemplateService->method('getUserGroupIdsFor')->willReturn(
            ['engineering', 'sales']
        );

        $this->mapper->method('findByGroupIds')->willReturn([
            $this->makeAssignment(null, 'sales', RoleAssignment::ROLE_VIEWER),
            $this->makeAssignment(null, 'engineering', RoleAssignment::ROLE_EDITOR),
        ]);

        $this->assertSame(
            RoleAssignment::ROLE_EDITOR,
            $this->service->getEffectiveRole(userId: 'charlie')
        );
        $this->assertSame(
            RoleAssignment::SOURCE_GROUP_ASSIGNED_PREFIX.'engineering',
            $this->service->getRoleSource(userId: 'charlie')
        );
    }

    public function testNoAssignmentReturnsNullRoleAndSource(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->willReturn([]);
        $this->adminTemplateService->method('getUserGroupIdsFor')->willReturn([]);
        $this->mapper->method('findByGroupIds')->willReturn([]);

        $this->assertNull($this->service->getEffectiveRole(userId: 'eve'));
        $this->assertNull($this->service->getRoleSource(userId: 'eve'));
    }

    // ==================================================================
    // Validation (REQ-ROLE-004)
    // ==================================================================

    public function testValidateRoleAcceptsKnownRoles(): void
    {
        $this->service->validateRole(role: 'admin');
        $this->service->validateRole(role: 'editor');
        $this->service->validateRole(role: 'viewer');
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRoleRejectsUnknown(): void
    {
        $this->expectException(InvalidRoleAssignmentException::class);
        $this->service->validateRole(role: 'superuser');
    }

    public function testValidateTargetRequiresOneOfUserOrGroup(): void
    {
        $this->expectException(InvalidRoleAssignmentException::class);
        $this->service->validateTarget(userId: null, groupId: null);
    }

    public function testValidateTargetRejectsBoth(): void
    {
        $this->expectException(InvalidRoleAssignmentException::class);
        $this->service->validateTarget(userId: 'bob', groupId: 'engineering');
    }

    public function testValidateTargetRejectsUnknownUser(): void
    {
        $this->userManager->method('userExists')->with('ghost')->willReturn(false);

        $this->expectException(InvalidRoleAssignmentException::class);
        $this->service->validateTarget(userId: 'ghost', groupId: null);
    }

    public function testValidateTargetRejectsUnknownGroup(): void
    {
        $this->groupManager->method('groupExists')->with('phantom')->willReturn(false);

        $this->expectException(InvalidRoleAssignmentException::class);
        $this->service->validateTarget(userId: null, groupId: 'phantom');
    }

    // ==================================================================
    // Assignment CRUD (REQ-ROLE-004)
    // ==================================================================

    public function testAssignRolePersistsAndReturnsEntity(): void
    {
        $this->userManager->method('userExists')->with('bob')->willReturn(true);
        $this->mapper->method('findUserRole')->with('bob', 'editor')->willReturn(null);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn(RoleAssignment $a) => $a);

        $assignment = $this->service->assignRole(
            userId: 'bob',
            groupId: null,
            role: 'editor',
            assignedBy: 'admin-user'
        );

        $this->assertSame('bob', $assignment->getUserId());
        $this->assertNull($assignment->getGroupId());
        $this->assertSame('editor', $assignment->getRole());
        $this->assertSame('admin-user', $assignment->getAssignedBy());
        $this->assertNotNull($assignment->getAssignedAt());
    }

    public function testAssignRoleRejectsDuplicateUserRole(): void
    {
        $this->userManager->method('userExists')->with('bob')->willReturn(true);
        $existing = $this->makeAssignment('bob', null, 'editor');
        $this->mapper->method('findUserRole')->with('bob', 'editor')->willReturn($existing);

        $this->expectException(DuplicateRoleAssignmentException::class);
        $this->service->assignRole(
            userId: 'bob',
            groupId: null,
            role: 'editor',
            assignedBy: 'admin-user'
        );
    }

    public function testAssignRoleRejectsDuplicateGroupRole(): void
    {
        $this->groupManager->method('groupExists')->with('engineering')->willReturn(true);
        $existing = $this->makeAssignment(null, 'engineering', 'editor');
        $this->mapper->method('findGroupRole')->with('engineering', 'editor')->willReturn($existing);

        $this->expectException(DuplicateRoleAssignmentException::class);
        $this->service->assignRole(
            userId: null,
            groupId: 'engineering',
            role: 'editor',
            assignedBy: 'admin-user'
        );
    }

    public function testRemoveRoleThrowsWhenNoRowAffected(): void
    {
        $this->mapper->method('deleteById')->with(99)->willReturn(0);

        $this->expectException(DoesNotExistException::class);
        $this->service->removeRole(id: 99);
    }

    public function testRemoveRoleSucceedsWhenRowDeleted(): void
    {
        $this->mapper->method('deleteById')->with(7)->willReturn(1);

        $this->service->removeRole(id: 7);
        $this->expectNotToPerformAssertions();
    }

    // ==================================================================
    // Cascade entry points (REQ-ROLE-010, REQ-ROLE-011)
    // ==================================================================

    public function testDeleteByUserIdDelegatesToMapper(): void
    {
        $this->mapper->expects($this->once())
            ->method('deleteByUserId')
            ->with('bob')
            ->willReturn(2);

        $this->assertSame(2, $this->service->deleteByUserId(userId: 'bob'));
    }

    public function testDeleteByGroupIdDelegatesToMapper(): void
    {
        $this->mapper->expects($this->once())
            ->method('deleteByGroupId')
            ->with('engineering')
            ->willReturn(3);

        $this->assertSame(
            3,
            $this->service->deleteByGroupId(groupId: 'engineering')
        );
    }

    // ==================================================================
    // Authorization helpers (REQ-ROLE-001..003, REQ-ROLE-008)
    // ==================================================================

    public function testIsAdminForNcAdmin(): void
    {
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
        $this->assertTrue($this->service->isAdmin(userId: 'alice'));
    }

    public function testIsViewerWhenViewerAssigned(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->willReturn(
            [$this->makeAssignment('charlie', null, RoleAssignment::ROLE_VIEWER)]
        );

        $this->assertTrue($this->service->isViewer(userId: 'charlie'));
        $this->assertFalse($this->service->canMutate(userId: 'charlie'));
    }

    public function testCanMutateForUnassignedUser(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->mapper->method('findByUser')->willReturn([]);
        $this->adminTemplateService->method('getUserGroupIdsFor')->willReturn([]);
        $this->mapper->method('findByGroupIds')->willReturn([]);

        $this->assertTrue($this->service->canMutate(userId: 'eve'));
    }

    public function testIsEditorOrHigherTrueForAdminAndEditor(): void
    {
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
        $this->assertTrue($this->service->isEditorOrHigher(userId: 'alice'));
    }
}//end class
