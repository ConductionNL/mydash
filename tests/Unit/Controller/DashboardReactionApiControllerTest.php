<?php

/**
 * DashboardReactionApiController Test
 *
 * Covers M4: ADR-023 action-auth wiring on all four reaction endpoints.
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

use OCA\MyDash\Controller\DashboardReactionApiController;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\ReactionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DashboardReactionApiController (M4 ADR-023 wiring).
 */
class DashboardReactionApiControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;
    /** @var ReactionService&MockObject */
    private $reactionService;
    /** @var ActionAuthService&MockObject */
    private $actionAuth;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @var IUser&MockObject */
    private $user;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->reactionService = $this->createMock(ReactionService::class);
        $this->actionAuth      = $this->createMock(ActionAuthService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($this->user);
    }//end setUp()

    private function makeController(?string $userId='user1'): DashboardReactionApiController
    {
        return new DashboardReactionApiController(
            request: $this->request,
            reactionService: $this->reactionService,
            actionAuth: $this->actionAuth,
            userSession: $this->userSession,
            logger: $this->logger,
            userId: $userId,
        );
    }//end makeController()

    /**
     * M4: getReactions() must call requireAction with the correct action name.
     */
    public function testGetReactionsCallsRequireAction(): void
    {
        $this->reactionService
            ->method('getReactionsSummary')
            ->willReturn(['counts' => [], 'mine' => [], 'enabled' => true]);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-reaction.get-reactions');

        $controller = $this->makeController();
        $response   = $controller->getReactions('some-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testGetReactionsCallsRequireAction()

    /**
     * M4: getReactions() must propagate OCSForbiddenException when denied.
     */
    public function testGetReactionsPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->getReactions('some-uuid');
    }//end testGetReactionsPropagatesForbiddenWhenActionDenied()

    /**
     * M4: addReaction() must call requireAction with the correct action name.
     */
    public function testAddReactionCallsRequireAction(): void
    {
        $this->reactionService
            ->method('addReaction')
            ->willReturn(['counts' => [], 'mine' => [], 'enabled' => true]);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-reaction.add-reaction');

        $controller = $this->makeController();
        $response   = $controller->addReaction('some-uuid', '👍');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testAddReactionCallsRequireAction()

    /**
     * M4: addReaction() must propagate OCSForbiddenException when denied.
     */
    public function testAddReactionPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->addReaction('some-uuid', '👍');
    }//end testAddReactionPropagatesForbiddenWhenActionDenied()

    /**
     * M4: removeReaction() must call requireAction with the correct action name.
     */
    public function testRemoveReactionCallsRequireAction(): void
    {
        $this->reactionService->method('removeReaction')->willReturn(true);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-reaction.remove-reaction');

        $controller = $this->makeController();
        $response   = $controller->removeReaction('some-uuid', '👍');

        $this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }//end testRemoveReactionCallsRequireAction()

    /**
     * M4: removeReaction() must propagate OCSForbiddenException when denied.
     */
    public function testRemoveReactionPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->removeReaction('some-uuid', '👍');
    }//end testRemoveReactionPropagatesForbiddenWhenActionDenied()

    /**
     * M4: getReactorsByEmoji() must call requireAction with the correct action name.
     */
    public function testGetReactorsByEmojiCallsRequireAction(): void
    {
        $this->reactionService
            ->method('getReactorsByEmoji')
            ->willReturn(['items' => [], 'nextCursor' => null]);

        $this->actionAuth
            ->expects($this->once())
            ->method('requireAction')
            ->with($this->user, 'dashboard-reaction.get-reactors-by-emoji');

        $controller = $this->makeController();
        $response   = $controller->getReactorsByEmoji('some-uuid', '👍');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testGetReactorsByEmojiCallsRequireAction()

    /**
     * M4: getReactorsByEmoji() must propagate OCSForbiddenException when denied.
     */
    public function testGetReactorsByEmojiPropagatesForbiddenWhenActionDenied(): void
    {
        $this->actionAuth
            ->method('requireAction')
            ->willThrowException(new OCSForbiddenException('Forbidden'));

        $this->expectException(OCSForbiddenException::class);

        $controller = $this->makeController();
        $controller->getReactorsByEmoji('some-uuid', '👍');
    }//end testGetReactorsByEmojiPropagatesForbiddenWhenActionDenied()

    /**
     * All endpoints must return 401 when no authenticated user exists.
     */
    public function testGetReactionsReturns401WhenUnauthenticated(): void
    {
        $anonSession = $this->createMock(IUserSession::class);
        $anonSession->method('getUser')->willReturn(null);

        $controller = new DashboardReactionApiController(
            request: $this->request,
            reactionService: $this->reactionService,
            actionAuth: $this->actionAuth,
            userSession: $anonSession,
            logger: $this->logger,
            userId: null,
        );
        $response = $controller->getReactions('some-uuid');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetReactionsReturns401WhenUnauthenticated()
}//end class
