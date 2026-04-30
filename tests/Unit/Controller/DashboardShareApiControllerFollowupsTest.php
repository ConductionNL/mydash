<?php

/**
 * DashboardShareApiControllerFollowupsTest
 *
 * Unit tests for DashboardShareApiController follow-up actions:
 * PUT /api/dashboard/{id}/shares (REQ-SHARE-009) and
 * DELETE /api/sharees/{shareType}/{shareWith} (REQ-SHARE-010).
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

use Exception;
use InvalidArgumentException;
use OCA\MyDash\Controller\DashboardShareApiController;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Service\DashboardShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardShareApiController follow-up actions.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DashboardShareApiControllerFollowupsTest extends TestCase
{

    /** @var DashboardShareService&MockObject */
    private $shareService;
    /** @var IRequest&MockObject */
    private $request;

    /**
     * Set up fresh mocks for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->shareService = $this->createMock(DashboardShareService::class);
        $this->request      = $this->createMock(IRequest::class);
    }//end setUp()

    /**
     * Build a controller for the given user.
     *
     * @param string|null $userId The user ID.
     *
     * @return DashboardShareApiController
     */
    private function makeController(
        ?string $userId='alice'
    ): DashboardShareApiController {
        return new DashboardShareApiController(
            request: $this->request,
            shareService: $this->shareService,
            userId: $userId,
        );
    }//end makeController()

    /**
     * Build a DashboardShare mock with jsonSerialize.
     *
     * @param int    $id    Share ID.
     * @param string $type  Share type.
     * @param string $with  Recipient.
     * @param string $level Permission level.
     *
     * @return DashboardShare&MockObject
     */
    private function makeShare(
        int $id,
        string $type,
        string $with,
        string $level='view_only'
    ): DashboardShare {
        $s = $this->getMockBuilder(DashboardShare::class)
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $s->method('jsonSerialize')->willReturn([
            'id'              => $id,
            'shareType'       => $type,
            'shareWith'       => $with,
            'permissionLevel' => $level,
        ]);
        return $s;
    }//end makeShare()

    // =========================================================================
    // replace() — PUT /api/dashboard/{id}/shares
    // =========================================================================

    /**
     * PUT shares returns 200 with the new list on success.
     *
     * @return void
     */
    public function testReplaceReturnsNewList(): void
    {
        $controller = $this->makeController();
        $shares     = [
            ['shareType' => 'user', 'shareWith' => 'bob', 'permissionLevel' => 'full'],
        ];

        $newShare = $this->makeShare(1, 'user', 'bob', 'full');
        $this->shareService->method('replaceShares')
            ->willReturn([$newShare]);

        $response = $controller->replace(id: 5, shares: $shares);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
    }//end testReplaceReturnsNewList()

    /**
     * PUT shares with empty body replaces with empty list.
     *
     * @return void
     */
    public function testReplaceWithEmptyBodyClearsShares(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('replaceShares')
            ->with(5, [], 'alice')
            ->willReturn([]);

        $response = $controller->replace(id: 5, shares: []);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData());
    }//end testReplaceWithEmptyBodyClearsShares()

    /**
     * PUT shares returns 401 when not logged in.
     *
     * @return void
     */
    public function testReplaceReturns401WhenNotLoggedIn(): void
    {
        $controller = $this->makeController(userId: null);
        $response   = $controller->replace(id: 5, shares: []);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testReplaceReturns401WhenNotLoggedIn()

    /**
     * PUT shares returns 403 when caller is not owner.
     *
     * @return void
     */
    public function testReplaceReturns403WhenNotOwner(): void
    {
        $controller = $this->makeController(userId: 'bob');

        $this->shareService->method('replaceShares')
            ->willThrowException(new Exception('Access denied'));

        $response = $controller->replace(id: 5, shares: []);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReplaceReturns403WhenNotOwner()

    /**
     * PUT shares returns 400 on invalid input.
     *
     * @return void
     */
    public function testReplaceReturns400OnInvalidInput(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('replaceShares')
            ->willThrowException(
                new InvalidArgumentException('Invalid shareType: blah')
            );

        $response = $controller->replace(
            id: 5,
            shares: [['shareType' => 'blah', 'shareWith' => 'bob', 'permissionLevel' => 'full']]
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testReplaceReturns400OnInvalidInput()

    /**
     * PUT shares returns 404 when dashboard not found.
     *
     * @return void
     */
    public function testReplaceReturns404WhenDashboardNotFound(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('replaceShares')
            ->willThrowException(new DoesNotExistException(''));

        $response = $controller->replace(id: 999, shares: []);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testReplaceReturns404WhenDashboardNotFound()

    // =========================================================================
    // revokeForRecipient() — DELETE /api/sharees/{shareType}/{shareWith}
    // =========================================================================

    /**
     * DELETE sharees returns count of deleted rows on success.
     *
     * @return void
     */
    public function testRevokeForRecipientReturnsCount(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('revokeAllForRecipient')
            ->with('user', 'bob', 'alice')
            ->willReturn(2);

        $response = $controller->revokeForRecipient(
            shareType: 'user',
            shareWith: 'bob'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['deleted' => 2], $response->getData());
    }//end testRevokeForRecipientReturnsCount()

    /**
     * DELETE sharees returns 401 when not logged in.
     *
     * @return void
     */
    public function testRevokeForRecipientReturns401WhenNotLoggedIn(): void
    {
        $controller = $this->makeController(userId: null);

        $response = $controller->revokeForRecipient(
            shareType: 'user',
            shareWith: 'bob'
        );

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testRevokeForRecipientReturns401WhenNotLoggedIn()

    /**
     * DELETE sharees returns 400 on invalid shareType.
     *
     * @return void
     */
    public function testRevokeForRecipientReturns400OnInvalidType(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('revokeAllForRecipient')
            ->willThrowException(
                new InvalidArgumentException('Invalid shareType: invalid')
            );

        $response = $controller->revokeForRecipient(
            shareType: 'invalid',
            shareWith: 'bob'
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testRevokeForRecipientReturns400OnInvalidType()

    /**
     * DELETE sharees returns 0 when caller has no shares for that recipient.
     *
     * @return void
     */
    public function testRevokeForRecipientReturnsZeroWhenNothingToRemove(): void
    {
        $controller = $this->makeController();

        $this->shareService->method('revokeAllForRecipient')
            ->willReturn(0);

        $response = $controller->revokeForRecipient(
            shareType: 'user',
            shareWith: 'bob'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['deleted' => 0], $response->getData());
    }//end testRevokeForRecipientReturnsZeroWhenNothingToRemove()

    /**
     * DELETE sharees with group type removes only caller's owned shares.
     *
     * @return void
     */
    public function testRevokeForRecipientOnlyRemovesCallerOwnedGroupShares(): void
    {
        $controller = $this->makeController(userId: 'alice');

        $this->shareService->expects($this->once())
            ->method('revokeAllForRecipient')
            ->with('group', 'marketing', 'alice')
            ->willReturn(1);

        $response = $controller->revokeForRecipient(
            shareType: 'group',
            shareWith: 'marketing'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['deleted' => 1], $response->getData());
    }//end testRevokeForRecipientOnlyRemovesCallerOwnedGroupShares()
}//end class
