<?php

/**
 * ReactionServiceTest
 *
 * Unit tests for ReactionService covering REQ-RXN-001..009 — emoji
 * whitelist, idempotent add, summary shape, per-dashboard + global
 * toggle resolution, permission gating, and cascade delete.
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
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardReaction;
use OCA\MyDash\Db\DashboardReactionMapper;
use OCA\MyDash\Service\PermissionDeniedException;
use OCA\MyDash\Service\PermissionService;
use OCA\MyDash\Service\ReactionService;
use OCA\MyDash\Service\ReactionsDisabledException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReactionService.
 */
class ReactionServiceTest extends TestCase
{
    private DashboardReactionMapper&MockObject $reactionMapper;
    private DashboardMapper&MockObject $dashboardMapper;
    private PermissionService&MockObject $permissionService;
    private IAppConfig&MockObject $appConfig;
    private IUserManager&MockObject $userManager;
    private ReactionService $service;

    protected function setUp(): void
    {
        $this->reactionMapper    = $this->createMock(originalClassName: DashboardReactionMapper::class);
        $this->dashboardMapper   = $this->createMock(originalClassName: DashboardMapper::class);
        $this->permissionService = $this->createMock(originalClassName: PermissionService::class);
        $this->appConfig         = $this->createMock(originalClassName: IAppConfig::class);
        $this->userManager       = $this->createMock(originalClassName: IUserManager::class);

        $this->service = new ReactionService(
            reactionMapper: $this->reactionMapper,
            dashboardMapper: $this->dashboardMapper,
            permissionService: $this->permissionService,
            appConfig: $this->appConfig,
            userManager: $this->userManager,
        );
    }

    private function makeDashboard(?int $perDashFlag, int $id=1, string $uuid='dash-123'): Dashboard
    {
        $dashboard = new Dashboard();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        // Entity __call uses $args[0] — named args break the magic forwarding.
        $dashboard->setId($id);
        $dashboard->setUuid($uuid);
        $dashboard->setReactionsEnabled($perDashFlag);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        return $dashboard;
    }

    /**
     * REQ-RXN-006 — null/1/0 tri-state resolution.
     */
    public function testIsReactionsEnabledTriState(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);

        $this->assertTrue($this->service->isReactionsEnabled(dashboard: $this->makeDashboard(perDashFlag: 1)));
        $this->assertFalse($this->service->isReactionsEnabled(dashboard: $this->makeDashboard(perDashFlag: 0)));
        $this->assertTrue($this->service->isReactionsEnabled(dashboard: $this->makeDashboard(perDashFlag: null)));
    }

    /**
     * REQ-RXN-007 scenario "Admin updates the allowed emoji list".
     */
    public function testValidateEmojiRejectsNonWhitelisted(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["👍","❤️"]');

        $this->expectException(InvalidArgumentException::class);
        $this->service->validateEmoji(emoji: '🚀');
    }

    public function testValidateEmojiAcceptsWhitelisted(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["👍","❤️"]');

        $this->service->validateEmoji(emoji: '❤️');
        $this->expectNotToPerformAssertions();
    }

    public function testValidateEmojiRejectsEmpty(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["👍"]');
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateEmoji(emoji: '');
    }

    /**
     * REQ-RXN-007 scenario "Default allowed emoji list".
     */
    public function testGetAllowedEmojisDefaults(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->assertSame(
            ReactionService::DEFAULT_ALLOWED_EMOJIS,
            $this->service->getAllowedEmojis()
        );
    }

    public function testGetAllowedEmojisFallsBackOnCorruptJson(): void
    {
        $this->appConfig->method('getValueString')->willReturn('not-json');
        $this->assertSame(
            ReactionService::DEFAULT_ALLOWED_EMOJIS,
            $this->service->getAllowedEmojis()
        );
    }

    /**
     * REQ-RXN-007 scenario "Empty emoji in whitelist" — admin-set
     * empty list returned as-is so validateEmoji rejects everything.
     */
    public function testGetAllowedEmojisEmptyAdminListSurfacesAsEmpty(): void
    {
        $this->appConfig->method('getValueString')->willReturn('[]');
        $this->assertSame([], $this->service->getAllowedEmojis());
    }

    /**
     * REQ-RXN-008 — non-VIEW user rejected with PermissionDeniedException.
     */
    public function testAddReactionPermissionDenied(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(false);

        $this->expectException(PermissionDeniedException::class);
        $this->service->addReaction(
            dashboardUuid: 'dash-123',
            userId: 'bob',
            emoji: '👍'
        );
    }

    /**
     * REQ-RXN-005 — global off + per-dashboard null returns
     * ReactionsDisabledException on POST.
     */
    public function testAddReactionDisabledThrows(): void
    {
        $dash = $this->makeDashboard(perDashFlag: null);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(false);

        $this->expectException(ReactionsDisabledException::class);
        $this->service->addReaction(
            dashboardUuid: 'dash-123',
            userId: 'alice',
            emoji: '👍'
        );
    }

    /**
     * REQ-RXN-001 scenario "User re-posts the same emoji" — duplicate
     * insert (unique constraint) is swallowed; summary returned as if
     * the row already existed.
     */
    public function testAddReactionIdempotentOnUniqueConstraint(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->appConfig->method('getValueString')->willReturn('["👍"]');
        $this->appConfig->method('getValueBool')->willReturn(true);

        $duplicate = $this->createMock(originalClassName: DbException::class);
        $duplicate->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->reactionMapper->method('addReaction')->willThrowException($duplicate);

        $this->reactionMapper->method('countByEmoji')->willReturn(['👍' => 1]);
        $existing = new DashboardReaction();
        $existing->setEmoji('👍');
        $this->reactionMapper->method('findByUser')->willReturn([$existing]);

        $summary = $this->service->addReaction(
            dashboardUuid: 'dash-123',
            userId: 'alice',
            emoji: '👍'
        );

        $this->assertTrue($summary['enabled']);
        $this->assertSame(['👍'], $summary['mine']);
        $this->assertSame(['👍' => 1], (array) $summary['counts']);
    }

    /**
     * REQ-RXN-003 scenario "Reactions disabled on dashboard" — GET
     * returns the empty-shape summary regardless of stored rows.
     */
    public function testGetReactionsSummaryDisabledShape(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 0);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(true);

        $this->reactionMapper->expects($this->never())->method('countByEmoji');

        $summary = $this->service->getReactionsSummary(
            dashboardUuid: 'dash-123',
            userId: 'alice'
        );

        $this->assertFalse($summary['enabled']);
        $this->assertSame([], (array) $summary['counts']);
        $this->assertSame([], $summary['mine']);
    }

    /**
     * REQ-RXN-003 scenario "User retrieves reactions on a dashboard
     * they can view" — counts + mine populated from mapper.
     */
    public function testGetReactionsSummaryEnabledShape(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(true);

        $this->reactionMapper->method('countByEmoji')->willReturn([
            '👍' => 3,
            '❤️' => 1,
            '🎉' => 2,
        ]);

        $a = new DashboardReaction();
        $a->setEmoji('👍');
        $b = new DashboardReaction();
        $b->setEmoji('🎉');
        $this->reactionMapper->method('findByUser')->willReturn([$a, $b]);

        $summary = $this->service->getReactionsSummary(
            dashboardUuid: 'dash-123',
            userId: 'alice'
        );

        $this->assertTrue($summary['enabled']);
        $this->assertSame(
            ['👍' => 3, '❤️' => 1, '🎉' => 2],
            (array) $summary['counts']
        );
        $this->assertSame(['👍', '🎉'], $summary['mine']);
    }

    /**
     * REQ-RXN-002 — DELETE delegates to mapper; idempotent return
     * value bubbles up.
     */
    public function testRemoveReactionDelegatesToMapper(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);

        $this->reactionMapper->expects($this->once())
            ->method('removeReaction')
            ->with(
                $this->equalTo('dash-123'),
                $this->equalTo('alice'),
                $this->equalTo('👍'),
            )
            ->willReturn(true);

        $result = $this->service->removeReaction(
            dashboardUuid: 'dash-123',
            userId: 'alice',
            emoji: '👍'
        );

        $this->assertTrue($result);
    }

    /**
     * REQ-RXN-009 — cascade delete delegates to mapper.
     */
    public function testDeleteReactionsByDashboardDelegates(): void
    {
        $this->reactionMapper->expects($this->once())
            ->method('deleteByDashboardUuid')
            ->with($this->equalTo('dash-123'))
            ->willReturn(7);

        $this->assertSame(
            7,
            $this->service->deleteReactionsByDashboard(dashboardUuid: 'dash-123')
        );
    }

    /**
     * REQ-RXN-004 — pagination cap + cursor advance.
     */
    public function testGetReactorsByEmojiPagination(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);

        $rows = [];
        for ($i = 0; $i < ReactionService::REACTORS_PAGE_SIZE; $i++) {
            $r = new DashboardReaction();
            $r->setUserId(sprintf('user%d', $i));
            $r->setEmoji('🎉');
            $rows[] = $r;
        }

        $this->reactionMapper->method('findByEmoji')->willReturn($rows);
        $this->reactionMapper->method('countReactorsByEmoji')->willReturn(150);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getDisplayName')->willReturn('User');
        $this->userManager->method('get')->willReturn($user);

        $page = $this->service->getReactorsByEmoji(
            dashboardUuid: 'dash-123',
            emoji: '🎉',
            userId: 'alice',
            cursor: null
        );

        $this->assertCount(ReactionService::REACTORS_PAGE_SIZE, $page['items']);
        $this->assertSame('100', $page['nextCursor']);
        $this->assertSame(150, $page['total']);
    }

    /**
     * Last page exposes nextCursor === null.
     */
    public function testGetReactorsByEmojiLastPageNoCursor(): void
    {
        $dash = $this->makeDashboard(perDashFlag: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->permissionService->method('canViewDashboard')->willReturn(true);

        $r = new DashboardReaction();
        $r->setUserId('alice');
        $r->setEmoji('🎉');
        $this->reactionMapper->method('findByEmoji')->willReturn([$r]);
        $this->reactionMapper->method('countReactorsByEmoji')->willReturn(1);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userManager->method('get')->willReturn($user);

        $page = $this->service->getReactorsByEmoji(
            dashboardUuid: 'dash-123',
            emoji: '🎉',
            userId: 'alice',
            cursor: null
        );

        $this->assertNull($page['nextCursor']);
        $this->assertSame(1, $page['total']);
    }
}
