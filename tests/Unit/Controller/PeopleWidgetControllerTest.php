<?php

/**
 * PeopleWidgetControllerTest
 *
 * Verifies REQ-PPL-003 controller behaviour:
 *  - HTTP 200 on a successful service call (success envelope passes through).
 *  - HTTP 400 on malformed `filters` JSON.
 *  - HTTP 400 on out-of-range `limit` propagated from the service.
 *  - HTTP 401 when the user is unauthenticated.
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

use InvalidArgumentException;
use OCA\MyDash\Controller\PeopleWidgetController;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\PeopleWidgetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PeopleWidgetControllerTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * @var PeopleWidgetService&MockObject
     */
    private $service;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var ActionAuthService&MockObject
     */
    private $actionAuth;

    /**
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(originalClassName: IRequest::class);
        $this->service     = $this->createMock(originalClassName: PeopleWidgetService::class);
        $this->logger      = $this->createMock(originalClassName: LoggerInterface::class);
        $this->actionAuth  = $this->createMock(originalClassName: ActionAuthService::class);
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
    }//end setUp()

    /**
     * @return void
     */
    public function testReturns200OnHappyPath(): void
    {
        $this->service->expects($this->once())
            ->method('listUsers')
            ->with(
                filters: [],
                excludeDisabled: true,
                showBirthdays: true,
                sortBy: 'displayName',
                limit: 50,
                offset: 0,
            )
            ->willReturn(
                [
                    'users'   => [['uid' => 'alice', 'displayName' => 'Alice']],
                    'total'   => 1,
                    'hasMore' => false,
                ]
            );

        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->getUsers();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertSame(expected: 1, actual: $data['total']);
        $this->assertFalse(condition: $data['hasMore']);
    }//end testReturns200OnHappyPath()

    /**
     * @return void
     */
    public function testReturns401WhenAnonymous(): void
    {
        $controller = $this->buildController(userId: null);
        $response   = $controller->getUsers();

        $this->assertSame(
            expected: Http::STATUS_UNAUTHORIZED,
            actual: $response->getStatus()
        );
    }//end testReturns401WhenAnonymous()

    /**
     * @return void
     */
    public function testReturns400OnMalformedFiltersJson(): void
    {
        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->getUsers(filters: 'not-json');

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
        $this->assertSame(
            expected: 'Invalid filters parameter',
            actual: $response->getData()['error']
        );
    }//end testReturns400OnMalformedFiltersJson()

    /**
     * @return void
     */
    public function testReturns400OnFiltersNotArray(): void
    {
        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->getUsers(filters: '"a-string"');

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
    }//end testReturns400OnFiltersNotArray()

    /**
     * @return void
     */
    public function testReturns400WhenServiceRejectsArguments(): void
    {
        $this->service->method('listUsers')
            ->willThrowException(
                exception: new InvalidArgumentException(message: 'limit too large')
            );

        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->getUsers(limit: 999);

        $this->assertSame(
            expected: Http::STATUS_BAD_REQUEST,
            actual: $response->getStatus()
        );
        $this->assertSame(
            expected: 'Invalid request parameters',
            actual: $response->getData()['error']
        );
    }//end testReturns400WhenServiceRejectsArguments()

    /**
     * @return void
     */
    public function testParsesValidFilterJson(): void
    {
        $this->service->expects($this->once())
            ->method('listUsers')
            ->with(
                filters: [
                    [
                        'fieldName' => 'group',
                        'operator'  => 'in',
                        'values'    => ['management'],
                    ],
                ],
                excludeDisabled: true,
                showBirthdays: true,
                sortBy: 'displayName',
                limit: 50,
                offset: 0,
            )
            ->willReturn(['users' => [], 'total' => 0, 'hasMore' => false]);

        $controller = $this->buildController(userId: 'alice');
        $controller->getUsers(
            filters: '[{"fieldName":"group","operator":"in","values":["management"]}]'
        );
    }//end testParsesValidFilterJson()

    /**
     * @param string|null $userId The user id (null for anonymous).
     *
     * @return PeopleWidgetController
     */
    private function buildController(?string $userId): PeopleWidgetController
    {
        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        } else {
            $this->userSession->method('getUser')->willReturn(null);
        }

        return new PeopleWidgetController(
            request: $this->request,
            service: $this->service,
            actionAuth: $this->actionAuth,
            userSession: $this->userSession,
            logger: $this->logger,
            userId: $userId,
        );
    }//end buildController()
}//end class
