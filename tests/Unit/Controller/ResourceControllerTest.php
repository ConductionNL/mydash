<?php

/**
 * ResourceController Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\ResourceController;
use OCA\MyDash\Controller\ResourceUploadRequestParser;
use OCA\MyDash\Exception\FileTooLargeException;
use OCA\MyDash\Exception\MimeMismatchException;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test-only subclass that injects the request body bytes.
 */
class TestableResourceController extends ResourceController
{
    public string $bodyBytes = '';

    protected function readRequestBody(): string
    {
        return $this->bodyBytes;
    }
}

class ResourceControllerTest extends TestCase
{
    private TestableResourceController $controller;

    /** @var IRequest&MockObject */
    private $request;

    /** @var ResourceService&MockObject */
    private $service;

    /** @var IUserSession&MockObject */
    private $userSession;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->service      = $this->createMock(ResourceService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->controller = new TestableResourceController(
            request: $this->request,
            resourceService: $this->service,
            parser: new ResourceUploadRequestParser(),
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );
    }

    private function makeAdmin(string $uid='alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with($uid)->willReturn(true);
    }

    private function makeNonAdmin(string $uid='bob'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with($uid)->willReturn(false);
    }

    public function testUnauthenticatedReturns403(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('forbidden', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }

    public function testNonAdminReturns403(): void
    {
        $this->makeNonAdmin();
        $this->controller->bodyBytes = json_encode(['base64' => 'data:image/png;base64,AAA']);
        $this->service->expects($this->never())->method('upload');

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame('forbidden', $response->getData()['error']);
    }

    public function testMultipartContentTypeReturns415(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->with('Content-Type')
            ->willReturn('multipart/form-data; boundary=---X');
        $this->controller->bodyBytes = '---X';

        $response = $this->controller->upload();

        $this->assertSame(415, $response->getStatus());
        $this->assertSame('unsupported_media_type', $response->getData()['error']);
    }

    public function testEmptyBodyReturnsInvalidDataUrl(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = '';

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_data_url', $response->getData()['error']);
    }

    public function testMissingBase64FieldReturnsInvalidDataUrl(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = json_encode(['other' => 'value']);

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_data_url', $response->getData()['error']);
    }

    public function testInvalidJsonReturnsInvalidDataUrl(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = '{not json';

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        // JsonException is now caught and re-thrown as
        // InvalidDataUrlException by the parser, so we get the stable
        // 400 / invalid_data_url envelope.
        $this->assertSame('invalid_data_url', $response->getData()['error']);
    }

    public function testSuccessReturnsStandardEnvelope(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = json_encode([
            'base64' => 'data:image/png;base64,AAA',
        ]);

        $this->service->expects($this->once())->method('upload')
            ->with('data:image/png;base64,AAA')
            ->willReturn([
                'url'  => '/apps/mydash/resource/resource_abc.png',
                'name' => 'resource_abc.png',
                'size' => 1234,
            ]);

        $response = $this->controller->upload();
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $body['status']);
        $this->assertSame('/apps/mydash/resource/resource_abc.png', $body['url']);
        $this->assertSame('resource_abc.png', $body['name']);
        $this->assertSame(1234, $body['size']);
    }

    public function testFileTooLargeIsMappedToEnvelope(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = json_encode([
            'base64' => 'data:image/png;base64,AAA',
        ]);

        $this->service->method('upload')
            ->willThrowException(new FileTooLargeException());

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('file_too_large', $response->getData()['error']);
        $this->assertSame('Maximum size is 5MB', $response->getData()['message']);
    }

    public function testMimeMismatchIsMappedToEnvelope(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = json_encode([
            'base64' => 'data:image/png;base64,AAA',
        ]);

        $this->service->method('upload')
            ->willThrowException(new MimeMismatchException());

        $response = $this->controller->upload();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('mime_mismatch', $response->getData()['error']);
    }

    public function testErrorResponseNeverContainsRawExceptionString(): void
    {
        $this->makeAdmin();
        $this->request->method('getHeader')->willReturn('application/json');
        $this->controller->bodyBytes = json_encode([
            'base64' => 'data:image/png;base64,AAA',
        ]);

        // Throw a non-typed exception with a sensitive-looking message.
        $this->service->method('upload')
            ->willThrowException(new \RuntimeException('SECRET_TOKEN_XYZ leaked'));

        $response = $this->controller->upload();
        $body     = $response->getData();

        $this->assertSame('error', $body['status']);
        $this->assertStringNotContainsString('SECRET_TOKEN_XYZ', json_encode($body));
        $this->assertStringNotContainsString('Exception', $body['message']);
        $this->assertStringNotContainsString('Stack', $body['message']);
    }

    public function testErrorEnvelopeHasStableShape(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $body = $this->controller->upload()->getData();

        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('message', $body);
        $this->assertSame('error', $body['status']);
    }
}
