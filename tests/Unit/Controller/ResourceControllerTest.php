<?php

/**
 * ResourceController Test
 *
 * Verifies the standardised error envelope and admin-only guard for
 * `POST /api/resources`. These cover REQ-RES-001 (admin guard, multipart
 * rejection), REQ-RES-005 (error envelope shape, stable enum, no leakage
 * of raw exception strings) plus the controller half of every typed
 * exception's HTTP-status mapping.
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
use OCA\MyDash\Exception\CorruptImageException;
use OCA\MyDash\Exception\FileTooLargeException;
use OCA\MyDash\Exception\InvalidDataUrlException;
use OCA\MyDash\Exception\InvalidImageFormatException;
use OCA\MyDash\Exception\InvalidSvgException;
use OCA\MyDash\Exception\MimeMismatchException;
use OCA\MyDash\Exception\StorageFailureException;
use OCA\MyDash\Exception\UnsupportedMediaTypeException;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResourceControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var ResourceService&MockObject */
    private $service;

    /** @var ResourceUploadRequestParser&MockObject */
    private $parser;

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
        $this->parser       = $this->createMock(ResourceUploadRequestParser::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }

    /**
     * Build a controller subclass that lets us inject a fake raw body
     * without touching `php://input`.
     */
    private function buildController(string $rawBody = ''): ResourceController
    {
        return new class (
            $this->request,
            $this->service,
            $this->parser,
            $this->userSession,
            $this->groupManager,
            $this->logger,
            $rawBody,
        ) extends ResourceController {
            public function __construct(
                IRequest $request,
                ResourceService $resourceService,
                ResourceUploadRequestParser $parser,
                IUserSession $userSession,
                IGroupManager $groupManager,
                LoggerInterface $logger,
                private readonly string $fakeBody,
            ) {
                parent::__construct(
                    request: $request,
                    resourceService: $resourceService,
                    parser: $parser,
                    userSession: $userSession,
                    groupManager: $groupManager,
                    logger: $logger,
                );
            }

            protected function readRequestBody(): string
            {
                return $this->fakeBody;
            }
        };
    }

    private function adminUser(string $uid = 'admin'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testNonAdminReceives403WithForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn($this->adminUser('alice'));
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
        $this->parser->expects($this->never())->method('extractBase64');
        $this->service->expects($this->never())->method('upload');

        $controller = $this->buildController();
        $response   = $controller->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('forbidden', $body['error']);
        $this->assertIsString($body['message']);
        $this->assertStringNotContainsString('Exception', $body['message']);
    }

    public function testUnauthenticatedReceives403(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->upload();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame('forbidden', $response->getData()['error']);
    }

    public function testSuccessReturnsEnvelope(): void
    {
        $this->userSession->method('getUser')->willReturn($this->adminUser());
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
        $this->parser->method('extractBase64')->willReturn('data:image/png;base64,xxx');
        $this->service->method('upload')->willReturn([
            'url'  => '/apps/mydash/resource/resource_abc.png',
            'name' => 'resource_abc.png',
            'size' => 1234,
        ]);

        $response = $this->buildController('{"base64":"data:image/png;base64,xxx"}')->upload();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('success', $body['status']);
        $this->assertSame('/apps/mydash/resource/resource_abc.png', $body['url']);
        $this->assertSame('resource_abc.png', $body['name']);
        $this->assertSame(1234, $body['size']);
    }

    /**
     * @return array<string, array{0: \Throwable, 1: int, 2: string}>
     */
    public static function exceptionMatrix(): array
    {
        return [
            'unsupported_media_type' => [new UnsupportedMediaTypeException(), 415, 'unsupported_media_type'],
            'invalid_data_url'       => [new InvalidDataUrlException(), 400, 'invalid_data_url'],
            'invalid_image_format'   => [new InvalidImageFormatException(), 400, 'invalid_image_format'],
            'file_too_large'         => [new FileTooLargeException(), 400, 'file_too_large'],
            'mime_mismatch'          => [new MimeMismatchException(), 400, 'mime_mismatch'],
            'corrupt_image'          => [new CorruptImageException(), 400, 'corrupt_image'],
            'invalid_svg'            => [new InvalidSvgException(), 400, 'invalid_svg'],
            'storage_failure'        => [new StorageFailureException(), 500, 'storage_failure'],
        ];
    }

    /**
     * @dataProvider exceptionMatrix
     */
    public function testEachExceptionMapsToCorrectEnvelope(
        \Throwable $exception,
        int $expectedStatus,
        string $expectedCode
    ): void {
        $this->userSession->method('getUser')->willReturn($this->adminUser());
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->parser->method('extractBase64')->willReturn('data:image/png;base64,xxx');
        $this->service->method('upload')->willThrowException($exception);

        $response = $this->buildController('{"base64":"x"}')->upload();
        $body     = $response->getData();

        $this->assertSame($expectedStatus, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertSame($expectedCode, $body['error']);
        $this->assertIsString($body['message']);
        // Defence — the display message MUST NOT be the raw underlying class name
        // and MUST NOT leak any "Exception" substring (REQ-RES-005).
        $this->assertStringNotContainsString('Exception', $body['message']);
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function testUnexpectedThrowableIsMaskedAsStorageFailure(): void
    {
        $this->userSession->method('getUser')->willReturn($this->adminUser());
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->parser->method('extractBase64')->willReturn('data:image/png;base64,xxx');
        $this->service->method('upload')->willThrowException(
            new \RuntimeException('SECRET_INTERNAL_PATH /var/lib/secret')
        );

        $response = $this->buildController('{"base64":"x"}')->upload();
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertSame('storage_failure', $body['error']);
        $this->assertStringNotContainsString('SECRET_INTERNAL_PATH', $body['message']);
        $this->assertStringNotContainsString('/var/lib/secret', $body['message']);
    }

    public function testParserExceptionIsNotShortCircuitedByAdminGuard(): void
    {
        // The admin guard runs BEFORE the parser — confirm a parser
        // exception still goes through the typed-error envelope.
        $this->userSession->method('getUser')->willReturn($this->adminUser());
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->parser->method('extractBase64')->willThrowException(
            new UnsupportedMediaTypeException()
        );
        $this->service->expects($this->never())->method('upload');

        $response = $this->buildController('--multipart')->upload();
        $body     = $response->getData();

        $this->assertSame(415, $response->getStatus());
        $this->assertSame('unsupported_media_type', $body['error']);
    }
}
