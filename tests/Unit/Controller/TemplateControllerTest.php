<?php

/**
 * TemplateController Test
 *
 * Unit tests for the gallery + save-as-template controller actions
 * (REQ-TMPL-014, REQ-TMPL-015):
 *   - Gallery returns HTTP 200 with the wrapped envelope.
 *   - Save-as-template happy path returns HTTP 201 with the new entity.
 *   - Save-as-template with no logged-in user returns HTTP 401.
 *   - Save-as-template forbidden surface returns HTTP 403 with the
 *     stable `forbidden` error code.
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

use OCA\MyDash\Controller\TemplateController;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var AdminTemplateService&MockObject */
    private $service;

    /** @var IUserSession&MockObject */
    private $userSession;

    /** @var ActionAuthService&MockObject */
    private $actionAuth;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->service    = $this->createMock(AdminTemplateService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->actionAuth  = $this->createMock(ActionAuthService::class);
    }//end setUp()

    /**
     * Build a controller with the given session-user.
     *
     * @param string|null $userId The user ID, or null for anonymous.
     *
     * @return TemplateController
     */
    private function buildController(?string $userId): TemplateController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        }

        return new TemplateController(
            request: $this->request,
            templateService: $this->service,
            userSession: $this->userSession,
            actionAuth: $this->actionAuth,
        );
    }//end buildController()

    /**
     * REQ-TMPL-014: gallery returns the success envelope with the
     * `templates` array.
     *
     * @return void
     */
    public function testGalleryReturnsSuccessEnvelope(): void
    {
        $payload = [
            [
                'uuid'          => 'uuid-1',
                'name'          => 'Marketing dashboard',
                'description'   => 'Gallery description',
                'category'      => 'marketing',
                'previewImage'  => null,
                'gridColumns'   => 12,
                'widgetCount'   => 4,
                'lastUpdatedAt' => '2026-05-01 09:00:00',
            ],
        ];

        $this->service
            ->expects($this->once())
            ->method('getGallery')
            ->with('marketing', 'name')
            ->willReturn($payload);

        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->gallery(category: 'marketing');

        $this->assertSame(
            expected: Http::STATUS_OK,
            actual: $response->getStatus()
        );
        $data = $response->getData();
        $this->assertSame(expected: 'success', actual: $data['status']);
        $this->assertSame(expected: $payload, actual: $data['templates']);
    }//end testGalleryReturnsSuccessEnvelope()

    /**
     * REQ-TMPL-015: save-as-template returns HTTP 201 with the new
     * template envelope on success.
     *
     * @return void
     */
    public function testSaveAsTemplateHappyPathReturns201(): void
    {
        $template = new Dashboard();
        $template->setUuid('new-uuid');
        $template->setName('My Template');
        $template->setType(Dashboard::TYPE_ADMIN_TEMPLATE);

        $this->service
            ->expects($this->once())
            ->method('saveAsTemplate')
            ->with(
                'alice',
                'src-uuid',
                $this->callback(static function (array $metadata): bool {
                    return $metadata['name'] === 'My Template';
                })
            )
            ->willReturn($template);

        $controller = $this->buildController(userId: 'alice');
        $response   = $controller->saveAsTemplate(
            uuid: 'src-uuid',
            name: 'My Template'
        );

        $this->assertSame(
            expected: Http::STATUS_CREATED,
            actual: $response->getStatus()
        );
        $data = $response->getData();
        $this->assertSame(expected: 'success', actual: $data['status']);
        $this->assertSame(expected: 'new-uuid', actual: $data['template']['uuid']);
    }//end testSaveAsTemplateHappyPathReturns201()

    /**
     * REQ-TMPL-015: anonymous caller → HTTP 401.
     *
     * @return void
     */
    public function testSaveAsTemplateAnonymousReturns401(): void
    {
        $this->service
            ->expects($this->never())
            ->method('saveAsTemplate');

        $controller = $this->buildController(userId: null);
        $response   = $controller->saveAsTemplate(
            uuid: 'src-uuid',
            name: 'X'
        );

        $this->assertSame(
            expected: Http::STATUS_UNAUTHORIZED,
            actual: $response->getStatus()
        );
    }//end testSaveAsTemplateAnonymousReturns401()

    /**
     * REQ-TMPL-015: forbidden mapping → HTTP 403 with stable error code.
     *
     * @return void
     */
    public function testSaveAsTemplateForbiddenReturns403(): void
    {
        $this->service
            ->expects($this->once())
            ->method('saveAsTemplate')
            ->willThrowException(new ForbiddenException(message: 'Not yours'));

        $controller = $this->buildController(userId: 'bob');
        $response   = $controller->saveAsTemplate(
            uuid: 'alices-dashboard',
            name: 'Stolen'
        );

        $this->assertSame(
            expected: Http::STATUS_FORBIDDEN,
            actual: $response->getStatus()
        );
        $data = $response->getData();
        $this->assertSame(expected: 'error', actual: $data['status']);
        $this->assertSame(expected: 'forbidden', actual: $data['error']);
    }//end testSaveAsTemplateForbiddenReturns403()
}//end class
