<?php

/**
 * ResourceController Test
 *
 * Covers the read-side endpoints added by the `resource-serving`
 * change (REQ-RES-006..008). The upload-side `POST /api/resources`
 * is exercised via {@see \Unit\Service\ResourceServiceTest}.
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
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        $request       = $this->createMock(IRequest::class);
        $service       = $this->createMock(ResourceService::class);
        $parser        = $this->createMock(ResourceUploadRequestParser::class);
        $userSession   = $this->createMock(IUserSession::class);
        $groupManager  = $this->createMock(IGroupManager::class);
        $this->appData = $this->createMock(IAppData::class);
        $this->folder  = $this->createMock(ISimpleFolder::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

        $this->controller = new ResourceController(
            request: $request,
            resourceService: $service,
            parser: $parser,
            userSession: $userSession,
            groupManager: $groupManager,
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
}//end class
