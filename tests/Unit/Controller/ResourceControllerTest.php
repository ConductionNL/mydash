<?php

/**
 * ResourceController Test
 *
 * Covers both the read-side endpoints added by the `resource-serving`
 * change (REQ-RES-006..008) and the upload-side `POST /api/resources`
 * endpoint added by the `resource-uploads` change (REQ-RES-001 admin
 * guard, multipart rejection; REQ-RES-005 error envelope shape, stable
 * enum, no leakage of raw exception strings) plus the controller half
 * of every typed exception's HTTP-status mapping.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionObject;

class ResourceControllerTest extends TestCase
{

    private ResourceController $controller;

    /**
     * @var IAppData&MockObject
     */
    private $appData;

    /**
     * @var ISimpleFolder&MockObject
     */
    private $folder;

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
        $this->appData      = $this->createMock(IAppData::class);
        $this->folder       = $this->createMock(ISimpleFolder::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

        $this->controller = new ResourceController(
            request: $this->request,
            resourceService: $this->service,
            parser: $this->parser,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            appData: $this->appData,
            l10n: $l10n,
            logger: new NullLogger(),
        );
    }//end setUp()

    private function tinyPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
            true
        );
    }//end tinyPng()

    /**
     * Read a Response's `headers` property without invoking the
     * `getHeaders()` method, which requires the full Nextcloud
     * bootstrap (\OC::$server) to be available — not the case for
     * host-side PHPUnit runs.
     *
     * @return array<string, mixed>
     */
    private function rawHeaders(object $response): array
    {
        $reflection = new ReflectionObject($response);
        // `headers` is declared private on the base Response class;
        // walk the parent chain until we find it.
        while ($reflection !== false && $reflection->hasProperty('headers') === false) {
            $reflection = $reflection->getParentClass();
        }

        $property = $reflection->getProperty('headers');
        $property->setAccessible(true);
        return $property->getValue($response);
    }//end rawHeaders()

    /**
     * @return ISimpleFile&MockObject
     */
    private function fileMock(string $name, string $bytes, int $mtime=0)
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getName')->willReturn($name);
        $file->method('getSize')->willReturn(strlen($bytes));
        $file->method('getContent')->willReturn($bytes);
        $file->method('getMTime')->willReturn($mtime);
        return $file;
    }//end fileMock()

    /**
     * Build a controller subclass that lets us inject a fake raw body
     * without touching `php://input`. Used by the upload-side tests.
     */
    private function buildController(string $rawBody = ''): ResourceController
    {
        $appData = $this->appData;
        $l10n    = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

        return new class (
            $this->request,
            $this->service,
            $this->parser,
            $this->userSession,
            $this->groupManager,
            $appData,
            $l10n,
            $this->logger,
            $rawBody,
        ) extends ResourceController {
            public function __construct(
                IRequest $request,
                ResourceService $resourceService,
                ResourceUploadRequestParser $parser,
                IUserSession $userSession,
                IGroupManager $groupManager,
                IAppData $appData,
                IL10N $l10n,
                LoggerInterface $logger,
                private readonly string $fakeBody,
            ) {
                parent::__construct(
                    request: $request,
                    resourceService: $resourceService,
                    parser: $parser,
                    userSession: $userSession,
                    groupManager: $groupManager,
                    appData: $appData,
                    l10n: $l10n,
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

    public function testServePngReturnsBytesAndHeaders(): void
    {
        $bytes = $this->tinyPng();
        $file  = $this->fileMock(name: 'resource_abc.png', bytes: $bytes);

        $this->appData->method('getFolder')->with(ResourceService::FOLDER)
            ->willReturn($this->folder);
        $this->folder->method('getFile')->with('resource_abc.png')
            ->willReturn($file);

        $response = $this->controller->getResource(filename: 'resource_abc.png');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $headers = $this->rawHeaders($response);
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $headers['Cache-Control']);
    }//end testServePngReturnsBytesAndHeaders()

    public function testServeSvgUsesImageSvgXmlContentType(): void
    {
        $svg  = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $file = $this->fileMock(name: 'resource_xyz.svg', bytes: $svg);

        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')->willReturn($file);

        $response = $this->controller->getResource(filename: 'resource_xyz.svg');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $headers = $this->rawHeaders($response);
        $this->assertSame('image/svg+xml', $headers['Content-Type']);
    }//end testServeSvgUsesImageSvgXmlContentType()

    public function testUnknownExtensionFallsBackToOctetStream(): void
    {
        $file = $this->fileMock(name: 'unknown.bin', bytes: 'raw bytes');

        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')->willReturn($file);

        $response = $this->controller->getResource(filename: 'unknown.bin');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $headers = $this->rawHeaders($response);
        $this->assertSame('application/octet-stream', $headers['Content-Type']);
    }//end testUnknownExtensionFallsBackToOctetStream()

    public function testJpegAliasReturnsImageJpeg(): void
    {
        $file = $this->fileMock(name: 'pic.jpg', bytes: 'jpegbytes');

        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')->willReturn($file);

        $response = $this->controller->getResource(filename: 'pic.jpg');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame('image/jpeg', $this->rawHeaders($response)['Content-Type']);
    }//end testJpegAliasReturnsImageJpeg()

    public function testMissingFileReturns404(): void
    {
        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')->willThrowException(new NotFoundException());

        $response = $this->controller->getResource(filename: 'missing.png');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testMissingFileReturns404()

    public function testMissingFolderReturns404(): void
    {
        $this->appData->method('getFolder')->willThrowException(new NotFoundException());

        $response = $this->controller->getResource(filename: 'whatever.png');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testMissingFolderReturns404()

    public function testEncodedPathTraversalReturns404(): void
    {
        // The Symfony router decodes `..%2F..%2Fetc%2Fpasswd` into
        // `../../etc/passwd` before handing it to the controller, so
        // this is what the controller actually sees.
        $this->appData->expects($this->never())->method('getFolder');

        $response = $this->controller->getResource(filename: '../../etc/passwd');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testEncodedPathTraversalReturns404()

    public function testPlainDotDotSegmentReturns404(): void
    {
        $this->appData->expects($this->never())->method('getFolder');

        $response = $this->controller->getResource(filename: '..');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testPlainDotDotSegmentReturns404()

    public function testEmptyFilenameReturns404(): void
    {
        $this->appData->expects($this->never())->method('getFolder');

        $response = $this->controller->getResource(filename: '');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testEmptyFilenameReturns404()

    public function testOversizeFileRefusedWithoutMemoryExhaustion(): void
    {
        $oversize = $this->createMock(ISimpleFile::class);
        // Report a 50 MB size — but the bytes are NEVER read because
        // the size check short-circuits. We assert that.
        $oversize->method('getSize')->willReturn((50 * 1024 * 1024));
        $oversize->expects($this->never())->method('getContent');

        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')->willReturn($oversize);

        $response = $this->controller->getResource(filename: 'huge.bin');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
            $response->getStatus()
        );

        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('file_too_large', $body['error']);
    }//end testOversizeFileRefusedWithoutMemoryExhaustion()

    public function testListReturnsResourcesSortedByModifiedDesc(): void
    {
        $oldFile = $this->fileMock(name: 'resource_old.png', bytes: 'old', mtime: 1000);
        $newFile = $this->fileMock(name: 'resource_new.png', bytes: 'new', mtime: 2000);
        $midFile = $this->fileMock(name: 'resource_mid.png', bytes: 'mid', mtime: 1500);

        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getDirectoryListing')
            ->willReturn([$oldFile, $midFile, $newFile]);

        $response = $this->controller->listResources();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $body = $response->getData();
        $this->assertSame('success', $body['status']);
        $this->assertCount(3, $body['resources']);

        $this->assertSame('resource_new.png', $body['resources'][0]['name']);
        $this->assertSame('resource_mid.png', $body['resources'][1]['name']);
        $this->assertSame('resource_old.png', $body['resources'][2]['name']);

        // URL shape per REQ-RES-007.
        $this->assertSame(
            '/apps/mydash/resource/resource_new.png',
            $body['resources'][0]['url']
        );
        $this->assertArrayNotHasKey('mtime', $body['resources'][0]);
        $this->assertSame(3, $body['resources'][0]['size']);
        // ISO timestamp shape (Z suffix).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $body['resources'][0]['modifiedAt']
        );
    }//end testListReturnsResourcesSortedByModifiedDesc()

    public function testListWithNoFolderReturnsEmptyArray(): void
    {
        $this->appData->method('getFolder')
            ->willThrowException(new NotFoundException());

        $response = $this->controller->listResources();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['status' => 'success', 'resources' => []],
            $response->getData()
        );
    }//end testListWithNoFolderReturnsEmptyArray()

    public function testListWithEmptyFolderReturnsEmptyArray(): void
    {
        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getDirectoryListing')->willReturn([]);

        $response = $this->controller->listResources();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['status' => 'success', 'resources' => []],
            $response->getData()
        );
    }//end testListWithEmptyFolderReturnsEmptyArray()

    // ---------------------------------------------------------------
    // Upload-side tests (REQ-RES-001/005).
    // ---------------------------------------------------------------

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
}//end class
