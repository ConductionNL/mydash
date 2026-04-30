<?php

/**
 * FileController Test
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
use OCA\MyDash\Exception\ForbiddenExtensionException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Service\FileService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for FileController::createFile.
 */
class FileControllerTest extends TestCase
{

    /** @var IRequest&MockObject */
    private $request;

    /** @var FileService&MockObject */
    private $fileService;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private FileController $controller;

    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->controller = new FileController(
            request: $this->request,
            fileService: $this->fileService,
            logger: $this->logger,
            userId: 'alice',
        );
    }

    // -------------------------------------------------------------------------
    // 400 envelope shape (PHPUnit task 6.2)
    // -------------------------------------------------------------------------

    public function testInvalidFilenameReturns400WithEnvelope(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new InvalidFilenameException());

        $response = $this->controller->createFile(filename: '../../evil', dir: '/');
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertSame('invalid_filename', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }

    public function testInvalidDirectoryReturns400WithEnvelope(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new InvalidDirectoryException());

        $response = $this->controller->createFile(filename: 'ok.txt', dir: '../secret');
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertSame('invalid_directory', $body['error']);
        $this->assertArrayHasKey('message', $body);
    }

    public function testForbiddenExtensionReturns400WithEnvelope(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new ForbiddenExtensionException());

        $response = $this->controller->createFile(filename: 'evil.exe', dir: '/');
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertSame('file_type_not_allowed', $body['error']);
    }

    // -------------------------------------------------------------------------
    // 200 happy path (PHPUnit task 6.2)
    // -------------------------------------------------------------------------

    public function testSuccessReturns200WithFileIdAndUrl(): void
    {
        $this->fileService->method('createFile')
            ->willReturn([
                'fileId' => 42,
                'url'    => 'https://nc/index.php/apps/files/?openfile=42',
            ]);

        $response = $this->controller->createFile(
            filename: 'report.docx',
            dir: '/',
            content: ''
        );

        $body = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $body['status']);
        $this->assertSame(42, $body['fileId']);
        $this->assertStringContainsString('openfile=42', $body['url']);
    }

    // -------------------------------------------------------------------------
    // Raw exception messages not leaked
    // -------------------------------------------------------------------------

    public function testUnexpectedExceptionReturns500WithoutRawMessage(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new RuntimeException(message: 'SECRET_DB_CREDS leaked'));

        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->createFile(filename: 'ok.txt', dir: '/');
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('error', $body['status']);
        $this->assertStringNotContainsString('SECRET_DB_CREDS', json_encode(value: $body));
    }

    // -------------------------------------------------------------------------
    // Unauthenticated
    // -------------------------------------------------------------------------

    public function testUnauthenticatedReturns401(): void
    {
        $controller = new FileController(
            request: $this->request,
            fileService: $this->fileService,
            logger: $this->logger,
            userId: null,
        );

        $response = $controller->createFile(filename: 'report.docx', dir: '/');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('error', $response->getData()['status']);
    }

    // -------------------------------------------------------------------------
    // Error envelope shape contract
    // -------------------------------------------------------------------------

    public function testErrorEnvelopeAlwaysHasThreeKeys(): void
    {
        $this->fileService->method('createFile')
            ->willThrowException(new InvalidFilenameException());

        $body = $this->controller->createFile(filename: '../../evil', dir: '/')->getData();

        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('message', $body);
    }
}//end class
