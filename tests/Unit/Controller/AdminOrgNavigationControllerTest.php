<?php

/**
 * AdminOrgNavigationControllerTest
 *
 * Unit tests for the org-wide navigation editor controller
 * (REQ-ONAV-002, REQ-ONAV-003, REQ-ONAV-004).
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
use OCA\MyDash\Controller\AdminOrgNavigationController;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Service\OrgNavigationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminOrgNavigationControllerTest extends TestCase
{

    /** @var IRequest&MockObject */
    private $request;

    /** @var OrgNavigationService&MockObject */
    private $service;

    /** @var AdminSettingMapper&MockObject */
    private $settings;

    /** @var IUserSession&MockObject */
    private $userSession;

    private AdminOrgNavigationController $controller;


    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(OrgNavigationService::class);
        $this->settings    = $this->createMock(AdminSettingMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->controller = new AdminOrgNavigationController(
            request: $this->request,
            service: $this->service,
            settings: $this->settings,
            userSession: $this->userSession,
        );

    }//end setUp()


    private function loginAs(string $uid, bool $admin=false): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end loginAs()


    public function testGetReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->getOrgNavigation();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGetReturnsUnauthorizedWhenNoUser()


    public function testGetReturnsFilteredTreeForLoggedInUser(): void
    {
        $this->loginAs('alice', false);

        $this->service->method('getTree')
            ->with('nl')
            ->willReturn([['id' => 'x', 'label' => 'Raw']]);
        $this->service->method('filterTreeByUserGroups')
            ->willReturn([['id' => 'x', 'label' => 'Filtered']]);

        $response = $this->controller->getOrgNavigation(lang: 'nl');
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('nl', $body['language']);
        $this->assertSame('Filtered', $body['tree'][0]['label']);

    }//end testGetReturnsFilteredTreeForLoggedInUser()


    public function testGetRejectsUnsupportedLanguage(): void
    {
        $this->loginAs('alice', false);

        $response = $this->controller->getOrgNavigation(lang: 'de');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testGetRejectsUnsupportedLanguage()


    public function testUpdateReturns400WhenTreeMissing(): void
    {
        $this->loginAs('admin', true);

        $response = $this->controller->updateOrgNavigation(tree: null);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testUpdateReturns400WhenTreeMissing()


    public function testUpdateReturns400OnValidationFailure(): void
    {
        $this->loginAs('admin', true);

        $this->service->method('setTree')
            ->willThrowException(
                new InvalidArgumentException('Tree depth cannot exceed 3 levels')
            );

        $response = $this->controller->updateOrgNavigation(tree: [['id' => 'x']]);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString(
            'Tree depth',
            (string) $response->getData()['error']
        );

    }//end testUpdateReturns400OnValidationFailure()


    public function testUpdatePersistsTree(): void
    {
        $this->loginAs('admin', true);

        $tree = [['id' => 'one', 'label' => 'A']];
        $this->service->expects($this->once())
            ->method('setTree')
            ->with($tree, 'en');

        $response = $this->controller->updateOrgNavigation(tree: $tree, lang: 'en');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('en', $response->getData()['language']);

    }//end testUpdatePersistsTree()


    public function testGetPositionReturnsDefaultWhenUnset(): void
    {
        $this->loginAs('alice', false);

        $this->settings->method('getValue')
            ->with(AdminOrgNavigationController::SETTING_KEY_POSITION, 'hidden')
            ->willReturn('hidden');

        $response = $this->controller->getPosition();
        $this->assertSame('hidden', $response->getData()['position']);

    }//end testGetPositionReturnsDefaultWhenUnset()


    public function testUpdatePositionRejectsBadValue(): void
    {
        $this->loginAs('admin', true);

        $this->settings->expects($this->never())->method('setSetting');

        $response = $this->controller->updatePosition(position: 'sideways');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testUpdatePositionRejectsBadValue()


    public function testUpdatePositionPersistsAccepted(): void
    {
        $this->loginAs('admin', true);

        $this->settings->expects($this->once())
            ->method('setSetting')
            ->with(AdminOrgNavigationController::SETTING_KEY_POSITION, 'left');

        $response = $this->controller->updatePosition(position: 'left');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('left', $response->getData()['position']);

    }//end testUpdatePositionPersistsAccepted()


}//end class

