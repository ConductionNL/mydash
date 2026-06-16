<?php

/**
 * AdminCleanupController Test
 *
 * Covers the admin guard (REQ-CLN-004 / REQ-CLN-005 "Non-admin user
 * is denied access"), the unknown-category 400 path on the purge
 * endpoint, the cache-hit envelope on the scan endpoint, and the
 * happy-path purge response shape.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\AdminCleanupController;
use OCA\MyDash\Db\CleanupResult;
use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\OrphanedDataCleanupService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminCleanupController.
 */
class AdminCleanupControllerTest extends TestCase
{
    /**
     * Cleanup service mock.
     *
     * @var OrphanedDataCleanupService&MockObject
     */
    private $cleanupService;

    /**
     * Registry mock.
     *
     * @var CategoryRegistryService&MockObject
     */
    private $registry;

    /**
     * Group manager mock (admin checks).
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * User session mock.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * Request mock.
     *
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * Build mocks for every collaborator.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->cleanupService = $this->createMock(originalClassName: OrphanedDataCleanupService::class);
        $this->registry       = $this->createMock(originalClassName: CategoryRegistryService::class);
        $this->groupManager   = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession    = $this->createMock(originalClassName: IUserSession::class);
        $this->request        = $this->createMock(originalClassName: IRequest::class);
    }

    /**
     * Build the controller using the configured mocks.
     *
     * @return AdminCleanupController The controller.
     */
    private function makeController(): AdminCleanupController
    {
        return new AdminCleanupController(
            request: $this->request,
            cleanupService: $this->cleanupService,
            registry: $this->registry,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );
    }

    /**
     * Stub the user session to return a logged-in user with the
     * supplied admin flag.
     *
     * @param string|null $userId    Logged-in user id, null = no user.
     * @param bool        $isAdmin   Admin status.
     *
     * @return void
     */
    private function stubSession(?string $userId, bool $isAdmin=false): void
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);
    }

    /**
     * `scan` MUST return 403 when no user is logged in.
     *
     * @return void
     */
    public function testScanReturns403WhenNotLoggedIn(): void
    {
        $this->stubSession(userId: null);

        $response = $this->makeController()->scan();

        $this->assertSame(
            expected: Http::STATUS_FORBIDDEN,
            actual: $response->getStatus()
        );
    }

    /**
     * `scan` MUST return 403 when the user is not an admin.
     *
     * @return void
     */
    public function testScanReturns403WhenNotAdmin(): void
    {
        $this->stubSession(userId: 'bob', isAdmin: false);

        $response = $this->makeController()->scan();

        $this->assertSame(
            expected: Http::STATUS_FORBIDDEN,
            actual: $response->getStatus()
        );
    }

    /**
     * `scan` MUST return the cached envelope (with `cached:true`)
     * when the cleanup service has a cached result.
     *
     * @return void
     */
    public function testScanReturnsCachedEnvelopeWhenCacheHit(): void
    {
        $this->stubSession(userId: 'admin', isAdmin: true);

        $cached = CleanupResult::fromCounts(
            byCategory: ['expired_locks' => 2],
            durationMs: 1,
        );

        $this->cleanupService->method('getCachedScanResult')->willReturn($cached);
        $this->cleanupService->expects($this->never())->method('scan');

        $response = $this->makeController()->scan();
        $body     = $response->getData();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertTrue(condition: $body['cached']);
        $this->assertSame(expected: 2, actual: $body['totalRows']);
    }

    /**
     * `purge` MUST return 400 when an unknown category name is sent.
     *
     * @return void
     */
    public function testPurgeReturns400OnUnknownCategory(): void
    {
        $this->stubSession(userId: 'admin', isAdmin: true);

        $this->registry->method('getCategoryNames')->willReturn(
            ['expired_locks', 'expired_share_tokens']
        );

        $response = $this->makeController()->purge(
            categories: ['ghost_category']
        );

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
        $body = $response->getData();
        $this->assertSame(
            expected: ['expired_locks', 'expired_share_tokens'],
            actual: $body['validCategories']
        );
    }

    /**
     * `purge` MUST forward to the service and return the
     * `purgedByCategory` shape required by REQ-CLN-005.
     *
     * @return void
     */
    public function testPurgeReturnsPurgedByCategoryEnvelope(): void
    {
        $this->stubSession(userId: 'admin', isAdmin: true);
        $this->registry->method('getCategoryNames')->willReturn(['expired_locks']);

        $this->cleanupService->expects($this->once())
            ->method('purge')
            ->willReturn(CleanupResult::fromCounts(
                byCategory: ['expired_locks' => 4],
                durationMs: 12,
            ));

        $response = $this->makeController()->purge(
            categories: ['expired_locks'],
            dryRun: false,
        );

        $body = $response->getData();
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(
            expected: ['expired_locks' => 4],
            actual: $body['purgedByCategory']
        );
        $this->assertSame(expected: 4, actual: $body['totalRows']);
        $this->assertFalse(condition: $body['dryRun']);
    }
}
