<?php

/**
 * MetadataAdminControllerTest
 *
 * Covers REQ-MDFL-001..003 — admin gating, CRUD success/failure
 * envelopes, and the cascade-delete confirmation flow.
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

use OCA\MyDash\Controller\MetadataAdminController;
use OCA\MyDash\Db\MetadataField;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Exception\MetadataFieldHasValuesException;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\MetadataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MetadataAdminControllerTest extends TestCase
{
    private MetadataAdminController $controller;
    /** @var MetadataService&MockObject */
    private MetadataService $metadataService;
    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;
    /** @var IUserSession&MockObject */
    private IUserSession $userSession;
    /** @var IRequest&MockObject */
    private IRequest $request;
    /** @var ActionAuthService&MockObject */
    private ActionAuthService $actionAuth;

    protected function setUp(): void
    {
        $this->metadataService = $this->createMock(MetadataService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->request         = $this->createMock(IRequest::class);
        $this->actionAuth      = $this->createMock(ActionAuthService::class);

        $this->controller = new MetadataAdminController(
            request: $this->request,
            metadataService: $this->metadataService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            actionAuth: $this->actionAuth,
        );
    }

    private function makeAdmin(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with($uid)->willReturn(true);
    }

    private function makeNonAdmin(string $uid = 'alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with($uid)->willReturn(false);
    }

    private function makeField(int $id, string $key): MetadataField
    {
        $field = new MetadataField();
        $field->setId($id);
        $field->setFieldKey($key);
        $field->setLabel(ucfirst($key));
        $field->setType(MetadataField::TYPE_TEXT);
        return $field;
    }

    public function testListFieldsForbiddenForNonAdmin(): void
    {
        $this->makeNonAdmin();
        $response = $this->controller->listFields();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testListFieldsReturnsFields(): void
    {
        $this->makeAdmin();
        $this->metadataService->method('listFields')
            ->willReturn([$this->makeField(1, 'department')]);

        $response = $this->controller->listFields();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $payload = $response->getData();
        $this->assertSame(1, $payload['count']);
        $this->assertCount(1, $payload['fields']);
        $this->assertSame('department', $payload['fields'][0]['key']);
    }

    public function testCreateFieldSuccess(): void
    {
        $this->makeAdmin();
        $field = $this->makeField(7, 'department');
        $this->metadataService
            ->expects($this->once())
            ->method('createFieldDefinition')
            ->with('department', 'Department', MetadataField::TYPE_TEXT)
            ->willReturn($field);

        $response = $this->controller->createField(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
        );

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('department', $response->getData()['key']);
    }

    public function testCreateFieldValidationFailureReturns400(): void
    {
        $this->makeAdmin();
        $this->metadataService->method('createFieldDefinition')
            ->willThrowException(new InvalidMetadataFieldException('boom'));

        $response = $this->controller->createField(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(InvalidMetadataFieldException::ERROR_CODE, $response->getData()['error']);
    }

    public function testCreateFieldNonAdminForbidden(): void
    {
        $this->makeNonAdmin();
        $response = $this->controller->createField(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
        );
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testGetFieldNotFound(): void
    {
        $this->makeAdmin();
        $this->metadataService->method('getField')
            ->willThrowException(new DoesNotExistException(''));
        $response = $this->controller->getField(99);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testUpdateFieldRejectsKeyRename(): void
    {
        $this->makeAdmin();
        $this->metadataService->method('updateFieldDefinition')
            ->willThrowException(new InvalidMetadataFieldException('Field key cannot be renamed'));

        $response = $this->controller->updateField(
            id: 5,
            key: 'division',
        );
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testDeleteFieldHasValuesReturns409(): void
    {
        $this->makeAdmin();
        $this->metadataService->method('deleteFieldDefinition')
            ->willThrowException(new MetadataFieldHasValuesException(3));

        $response = $this->controller->deleteField(id: 5, cascade: false);
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(3, $response->getData()['valueCount']);
    }

    public function testDeleteFieldCascadeSucceeds(): void
    {
        $this->makeAdmin();
        $this->metadataService
            ->expects($this->once())
            ->method('deleteFieldDefinition')
            ->with(5, true)
            ->willReturn(true);

        $response = $this->controller->deleteField(id: 5, cascade: true);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testDeleteFieldNonAdmin(): void
    {
        $this->makeNonAdmin();
        $response = $this->controller->deleteField(id: 5, cascade: true);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }
}
