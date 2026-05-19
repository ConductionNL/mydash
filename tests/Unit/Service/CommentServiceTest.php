<?php

/**
 * CommentServiceTest
 *
 * Unit tests for the CommentService that owns the dashboard-comments
 * capability (REQ-CMNT-001..009).
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

use DateTime;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Exception\CommentForbiddenException;
use OCA\MyDash\Exception\CommentNotFoundException;
use OCA\MyDash\Exception\InvalidCommentException;
use OCA\MyDash\Service\CommentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentManagerNotFoundException;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CommentService.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CommentServiceTest extends TestCase
{
    /** @var ICommentsManager&MockObject */
    private $commentsManager;
    /** @var IUserManager&MockObject */
    private $userManager;
    /** @var INotificationManager&MockObject */
    private $notificationManager;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IURLGenerator&MockObject */
    private $urlGenerator;
    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    private CommentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commentsManager     = $this->createMock(ICommentsManager::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->urlGenerator        = $this->createMock(IURLGenerator::class);
        $this->settingMapper       = $this->createMock(AdminSettingMapper::class);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://nc.example/index.php/apps/mydash/');

        $this->service = new CommentService(
            commentsManager: $this->commentsManager,
            userManager: $this->userManager,
            notificationManager: $this->notificationManager,
            groupManager: $this->groupManager,
            urlGenerator: $this->urlGenerator,
            settingMapper: $this->settingMapper,
        );
    }

    /**
     * @return IComment&MockObject
     */
    private function makeComment(
        int $id,
        int $parentId,
        string $author,
        string $message,
        string $objectId='dash-001',
        ?DateTime $createdAt=null,
        ?DateTime $updatedAt=null
    ) {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn((string) $id);
        $comment->method('getParentId')->willReturn((string) $parentId);
        $comment->method('getActorId')->willReturn($author);
        $comment->method('getActorType')->willReturn('users');
        $comment->method('getMessage')->willReturn($message);
        $comment->method('getObjectType')->willReturn(CommentService::OBJECT_TYPE);
        $comment->method('getObjectId')->willReturn($objectId);
        $comment->method('getCreationDateTime')->willReturn(
            $createdAt ?? new DateTime('2026-05-02T10:00:00+00:00')
        );
        $comment->method('getLatestChildDateTime')->willReturn($updatedAt);
        return $comment;
    }

    // ==================================================================
    // Global toggle resolution (REQ-CMNT-008)
    // ==================================================================

    public function testIsCommentsEnabledGloballyDefaultsToTrue(): void
    {
        $this->settingMapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(msg: 'missing'));

        $this->assertTrue($this->service->isCommentsEnabledGlobally());
    }

    public function testIsCommentsEnabledGloballyHonoursStoredFalse(): void
    {
        $setting = new AdminSetting();
        $setting->setSettingKey(AdminSetting::KEY_COMMENTS_ENABLED_DEFAULT);
        $setting->setValueEncoded(false);
        $this->settingMapper->method('findByKey')->willReturn($setting);

        $this->assertFalse($this->service->isCommentsEnabledGlobally());
    }

    // ==================================================================
    // Per-dashboard precedence (REQ-CMNT-007)
    // ==================================================================

    public function testIsEnabledForUsesDashboardPrecedence(): void
    {
        $this->settingMapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(msg: 'missing'));

        $forcedOn = new Dashboard();
        $forcedOn->setCommentsEnabled(1);
        $this->assertTrue($this->service->isEnabledFor(dashboard: $forcedOn));

        $forcedOff = new Dashboard();
        $forcedOff->setCommentsEnabled(0);
        $this->assertFalse($this->service->isEnabledFor(dashboard: $forcedOff));

        $inherits = new Dashboard();
        $inherits->setCommentsEnabled(null);
        $this->assertTrue($this->service->isEnabledFor(dashboard: $inherits));
    }

    // ==================================================================
    // List + thread shape (REQ-CMNT-001)
    // ==================================================================

    public function testGetCommentsForDashboardGroupsRepliesUnderParents(): void
    {
        $top1 = $this->makeComment(
            id: 100,
            parentId: 0,
            author: 'alice',
            message: 'first',
            createdAt: new DateTime('2026-05-02T10:00:00+00:00')
        );
        $top2 = $this->makeComment(
            id: 200,
            parentId: 0,
            author: 'bob',
            message: 'second',
            createdAt: new DateTime('2026-05-02T11:00:00+00:00')
        );
        $reply = $this->makeComment(
            id: 101,
            parentId: 100,
            author: 'carol',
            message: 'agreed',
            createdAt: new DateTime('2026-05-02T10:30:00+00:00')
        );

        $this->commentsManager->method('getForObject')
            ->willReturn([$top1, $reply, $top2]);

        $this->settingMapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(msg: 'missing'));
        $this->userManager->method('get')->willReturn(null);

        $thread = $this->service->getCommentsForDashboard(
            dashboardUuid: 'dash-001'
        );

        $this->assertCount(2, $thread);
        // Newest-first ordering — bob's "second" comes before alice's "first".
        $this->assertSame(200, $thread[0]['id']);
        $this->assertSame(100, $thread[1]['id']);
        $this->assertCount(0, $thread[0]['replies']);
        $this->assertCount(1, $thread[1]['replies']);
        $this->assertSame(101, $thread[1]['replies'][0]['id']);
        $this->assertSame(100, $thread[1]['replies'][0]['parentId']);
    }

    // ==================================================================
    // Create + nesting (REQ-CMNT-002, REQ-CMNT-003)
    // ==================================================================

    public function testCreateCommentRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidCommentException::class);
        $this->service->createComment(
            dashboardUuid: 'dash-001',
            userId: 'alice',
            message: '   '
        );
    }

    public function testCreateCommentTopLevelHappyPath(): void
    {
        $created = $this->createMock(IComment::class);
        $created->expects($this->once())->method('setMessage')->with('hello');
        $created->expects($this->once())->method('setVerb')->with('comment');
        $created->expects($this->never())->method('setParentId');

        $this->commentsManager->method('create')
            ->with('users', 'alice', CommentService::OBJECT_TYPE, 'dash-001')
            ->willReturn($created);
        $this->commentsManager->expects($this->once())->method('save')->with($created);

        $this->userManager->method('get')->willReturn(null);

        $result = $this->service->createComment(
            dashboardUuid: 'dash-001',
            userId: 'alice',
            message: 'hello'
        );

        $this->assertSame($created, $result);
    }

    public function testCreateCommentRejectsNestedReply(): void
    {
        $reply = $this->makeComment(
            id: 101,
            parentId: 100,
            author: 'bob',
            message: 'reply'
        );
        $this->commentsManager->method('get')->with('101')->willReturn($reply);

        $this->expectException(InvalidCommentException::class);
        $this->expectExceptionMessage('replied to once');
        $this->service->createComment(
            dashboardUuid: 'dash-001',
            userId: 'alice',
            message: 'reply to reply',
            parentId: 101
        );
    }

    public function testCreateCommentRejectsParentFromAnotherDashboard(): void
    {
        $cross = $this->makeComment(
            id: 50,
            parentId: 0,
            author: 'eve',
            message: 'other',
            objectId: 'dash-other'
        );
        $this->commentsManager->method('get')->with('50')->willReturn($cross);

        $this->expectException(CommentNotFoundException::class);
        $this->service->createComment(
            dashboardUuid: 'dash-001',
            userId: 'alice',
            message: 'reply',
            parentId: 50
        );
    }

    public function testCreateCommentMissingParentReturns404(): void
    {
        $this->commentsManager->method('get')
            ->willThrowException(new CommentManagerNotFoundException());

        $this->expectException(CommentNotFoundException::class);
        $this->service->createComment(
            dashboardUuid: 'dash-001',
            userId: 'alice',
            message: 'reply',
            parentId: 999
        );
    }

    // ==================================================================
    // Edit + delete authorisation (REQ-CMNT-004, REQ-CMNT-005)
    // ==================================================================

    public function testUpdateCommentBlocksNonAuthor(): void
    {
        $comment = $this->makeComment(
            id: 100,
            parentId: 0,
            author: 'kate',
            message: 'original'
        );
        $this->commentsManager->method('get')->with('100')->willReturn($comment);
        $this->groupManager->method('isAdmin')->with('liam')->willReturn(false);

        $this->expectException(CommentForbiddenException::class);
        $this->service->updateComment(
            commentId: 100,
            newMessage: 'tampered',
            currentUserId: 'liam'
        );
    }

    public function testUpdateCommentAllowsAdmin(): void
    {
        $comment = $this->makeComment(
            id: 100,
            parentId: 0,
            author: 'mike',
            message: 'original'
        );
        $comment->expects($this->once())->method('setMessage')->with('admin edit');
        $comment->expects($this->once())->method('setLatestChildDateTime');

        $this->commentsManager->method('get')->with('100')->willReturn($comment);
        $this->commentsManager->expects($this->once())->method('save')->with($comment);
        $this->groupManager->method('isAdmin')->with('nora')->willReturn(true);
        $this->userManager->method('get')->willReturn(null);

        $this->service->updateComment(
            commentId: 100,
            newMessage: 'admin edit',
            currentUserId: 'nora'
        );
    }

    public function testDeleteCommentCascadesRepliesForTopLevel(): void
    {
        $top = $this->makeComment(
            id: 100,
            parentId: 0,
            author: 'quinn',
            message: 'top'
        );
        $reply1 = $this->makeComment(
            id: 101,
            parentId: 100,
            author: 'pat',
            message: 'r1'
        );
        $reply2 = $this->makeComment(
            id: 102,
            parentId: 100,
            author: 'sam',
            message: 'r2'
        );
        $unrelated = $this->makeComment(
            id: 200,
            parentId: 0,
            author: 'tim',
            message: 'unrelated'
        );

        $this->commentsManager->method('get')->with('100')->willReturn($top);
        $this->commentsManager->method('getForObject')
            ->willReturn([$top, $reply1, $reply2, $unrelated]);

        $deleted = [];
        $this->commentsManager->method('delete')->willReturnCallback(
            function ($id) use (&$deleted): bool {
                $deleted[] = (string) $id;
                return true;
            }
        );

        $this->service->deleteComment(
            commentId: 100,
            currentUserId: 'quinn'
        );

        // Both children + the parent must be deleted; the unrelated
        // top-level comment must NOT be touched.
        $this->assertContains('100', $deleted);
        $this->assertContains('101', $deleted);
        $this->assertContains('102', $deleted);
        $this->assertNotContains('200', $deleted);
    }

    public function testDeleteCommentReplyOnlyDeletesItself(): void
    {
        $reply = $this->makeComment(
            id: 101,
            parentId: 100,
            author: 'rita',
            message: 'reply'
        );
        $this->commentsManager->method('get')->with('101')->willReturn($reply);

        $deleted = [];
        $this->commentsManager->method('delete')->willReturnCallback(
            function ($id) use (&$deleted): bool {
                $deleted[] = (string) $id;
                return true;
            }
        );

        $this->service->deleteComment(
            commentId: 101,
            currentUserId: 'rita'
        );

        $this->assertSame(['101'], $deleted);
    }

    // ==================================================================
    // Mention parsing (REQ-CMNT-006)
    // ==================================================================

    public function testParseAndResolveMentionsDeduplicates(): void
    {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getDisplayName')->willReturn('Alice User');

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob User');

        $this->userManager->method('get')->willReturnCallback(
            function (string $uid) use ($alice, $bob): ?IUser {
                if ($uid === 'alice') {
                    return $alice;
                }
                if ($uid === 'bob') {
                    return $bob;
                }
                return null;
            }
        );

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        // alice and bob each get notified once; nonexistent skipped.
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $resolved = $this->service->parseAndResolveMentions(
            message: '@alice @Alice @bob @nonexistent thanks',
            dashboardUuid: 'dash-001',
            authorUserId: 'eve'
        );

        $ids = array_column($resolved, 'userId');
        $this->assertContains('alice', $ids);
        $this->assertContains('bob', $ids);
        $this->assertNotContains('nonexistent', $ids);
        $this->assertCount(2, $resolved);
    }

    public function testParseAndResolveMentionsSkipsSelfNotification(): void
    {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getDisplayName')->willReturn('Alice User');

        $this->userManager->method('get')->willReturn($alice);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $resolved = $this->service->parseAndResolveMentions(
            message: '@alice noted to self',
            dashboardUuid: 'dash-001',
            authorUserId: 'alice'
        );

        // Self-mention still appears in the resolved list (so the wire
        // payload is consistent), but no notification is dispatched.
        $this->assertSame([['userId' => 'alice', 'displayName' => 'Alice User']], $resolved);
    }

    // ==================================================================
    // Cascade entry point (D6)
    // ==================================================================

    public function testDeleteAllForDashboardDelegatesToCommentsManager(): void
    {
        $this->commentsManager->expects($this->once())
            ->method('deleteCommentsAtObject')
            ->with(CommentService::OBJECT_TYPE, 'dash-001');

        $this->service->deleteAllForDashboard(dashboardUuid: 'dash-001');
    }
}
