<?php

/**
 * FileController Test
 *
 * Covers REQ-LBN-004 controller-side behaviour: typed exceptions are
 * mapped to the standardised `{status, error, message}` envelope with
 * the correct HTTP status, and unexpected Throwable paths are wrapped
 * into the StorageFailureException envelope so raw messages never
 * leak.
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

use OCA\MyDash\Controller\FileController;
use OCA\MyDash\Exception\FileTypeNotAllowedException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Service\FileService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FileControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;

    /** @var FileService&MockObject */
    private $fileService;

    /** @var IUserSession&MockObject */
    private $userSession;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private FileController $controller;

    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new FileController(
            request: $this->request,
            fileService: $this->fileService,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }

    public function testHappyPathReturnsSuccessEnvelope(): void
    {
        $this->fileService->method('createFile')
            ->with('alice', 'hello.txt', '/', 'hi')
            ->willReturn([
                'status' => 'success',
                'fileId' => 42,
                'url'    => 'https://nc/files?openfile=42',
            ]);

        $response = $this->controller->createFile(
            filename: 'hello.txt',
            dir: '/',
            content: 'hi'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('success', $data['status']);
        $this->assertSame(42, $data['fileId']);
    }

    public function testUnauthenticatedReturnsForbiddenEnvelope(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new FileController(
            request: $this->request,
            fileService: $this->fileService,
            userSession: $userSession,
            logger: $this->logger,
        );

        $response = $controller->createFile(filename: 'hello.txt');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame('forbidden', $data['error']);
    }

    public function testInvalidFilenameMapsToHttp400(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new InvalidFilenameException());

        $response = $this->controller->createFile(filename: '../../etc/passwd');

        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame('invalid_filename', $data['error']);
        $this->assertSame('Invalid filename', $data['message']);
    }

    public function testFileTypeNotAllowedMapsToHttp400(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new FileTypeNotAllowedException());

        $response = $this->controller->createFile(filename: 'evil.exe');

        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('file_type_not_allowed', $data['error']);
        $this->assertSame('File type not allowed', $data['message']);
    }

    public function testUnexpectedThrowableIsWrappedNotLeaked(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new RuntimeException('raw internal db error: server crash'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unexpected file create failure',
                $this->callback(static function ($context): bool {
                    return is_array($context) && isset($context['exception']);
                })
            );

        $response = $this->controller->createFile(filename: 'hello.txt');

        $data = $response->getData();
        // The curated message — never the raw exception text.
        $this->assertStringNotContainsString('raw internal', $data['message']);
        $this->assertStringNotContainsString('server crash', $data['message']);
        $this->assertSame('error', $data['status']);
    }
}
