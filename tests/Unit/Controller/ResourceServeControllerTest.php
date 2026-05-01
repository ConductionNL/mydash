<?php

/**
 * ResourceServeController test.
 *
 * Covers REQ-RES-006 (public serve), REQ-RES-007 (list), and
 * REQ-RES-008 (size-bounded streaming) — the read-side endpoints
 * of the resource-uploads capability added by `resource-serving`.
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

use OCA\MyDash\Controller\ResourceServeController;
use OCA\MyDash\Service\ResourceServeService;
use OCA\MyDash\Service\ResourceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
// Note: listResources returns JSONResponse (not DataResponse) — see
// the controller docblock for the rationale.
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ResourceServeController.
 */
class ResourceServeControllerTest extends TestCase
{
    private ResourceServeController $controller;

    /** @var IRequest&MockObject */
    private $request;

    /** @var ResourceServeService&MockObject */
    private $serve;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->serve   = $this->createMock(ResourceServeService::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $this->controller = new ResourceServeController(
            request: $this->request,
            serve: $this->serve,
            logger: $this->logger,
        );
    }

    /**
     * Tiny 1x1 PNG bytes (red pixel).
     */
    private function tinyPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
            true
        );
    }

    /**
     * Build a mock ISimpleFile with the given attributes.
     */
    private function makeFile(string $name, int $size, int $mtime, string $content = 'AB'): ISimpleFile
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getName')->willReturn($name);
        $file->method('getSize')->willReturn($size);
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getContent')->willReturn($content);
        return $file;
    }

    /**
     * Read a Response's private $headers without going through
     * Response::getHeaders(), which calls into \OC::$server and is
     * unavailable in the unit-test sandbox.
     *
     * @return array<string, string>
     */
    private function rawHeaders(Response $response): array
    {
        $reflection = new ReflectionClass(Response::class);
        $property   = $reflection->getProperty('headers');
        $property->setAccessible(true);
        return $property->getValue($response);
    }

    public function testServePngReturnsBytesAndHeaders(): void
    {
        $bytes = $this->tinyPng();
        $file  = $this->makeFile(
            name: 'resource_abc.png',
            size: strlen($bytes),
            mtime: 1700000000,
            content: $bytes
        );

        $this->serve->method('findFile')->with('resource_abc.png')->willReturn($file);
        $this->serve->method('contentTypeForFilename')->with('resource_abc.png')
            ->willReturn('image/png');

        $response = $this->controller->getResource(filename: 'resource_abc.png');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $headers = $this->rawHeaders($response);
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $headers['Cache-Control']);
        $this->assertSame((string) strlen($bytes), $headers['Content-Length']);
    }

    public function testServeSvgUsesImageSvgXmlContentType(): void
    {
        $svg  = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $file = $this->makeFile(
            name: 'resource_xyz.svg',
            size: strlen($svg),
            mtime: 1700000001,
            content: $svg
        );

        $this->serve->method('findFile')->willReturn($file);
        $this->serve->method('contentTypeForFilename')->with('resource_xyz.svg')
            ->willReturn('image/svg+xml');

        $response = $this->controller->getResource(filename: 'resource_xyz.svg');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame('image/svg+xml', $this->rawHeaders($response)['Content-Type']);
    }

    public function testUnknownExtensionFallsBackToOctetStream(): void
    {
        $file = $this->makeFile(
            name: 'resource_unknown.bin',
            size: 4,
            mtime: 1700000002,
            content: 'data'
        );

        $this->serve->method('findFile')->willReturn($file);
        $this->serve->method('contentTypeForFilename')->with('resource_unknown.bin')
            ->willReturn('application/octet-stream');

        $response = $this->controller->getResource(filename: 'resource_unknown.bin');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame('application/octet-stream', $this->rawHeaders($response)['Content-Type']);
    }

    public function testMissingFileReturns404(): void
    {
        $this->serve->method('findFile')->willReturn(null);

        $response = $this->controller->getResource(filename: 'gone.png');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * @dataProvider traversalProvider
     */
    public function testEncodedPathTraversalReturns404(string $name): void
    {
        // findFile MUST NOT be touched — controller rejects before any
        // filesystem lookup happens.
        $this->serve->expects($this->never())->method('findFile');

        $response = $this->controller->getResource(filename: $name);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function traversalProvider(): array
    {
        return [
            'parent traversal'        => ['../../etc/passwd'],
            'leading dotdot'          => ['..'],
            'embedded dotdot'         => ['foo..bar'],
            'absolute path'           => ['/etc/passwd'],
            'backslash traversal'     => ['..\\etc\\passwd'],
            'empty filename'          => [''],
            'single dot'              => ['.'],
            'sneaky dotdot in middle' => ['resource_..png'],
        ];
    }

    public function testOversizeFileRefusedWithoutReadingBytes(): void
    {
        // 50 MB declared size — must be refused with 413 before bytes
        // are loaded into memory.
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getSize')->willReturn(50 * 1024 * 1024);
        // Crucially, getContent MUST NOT be invoked.
        $file->expects($this->never())->method('getContent');

        $this->serve->method('findFile')->willReturn($file);

        $response = $this->controller->getResource(filename: 'huge.bin');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('file_too_large', $body['error']);
    }

    public function testFileExactlyAtCapIsServed(): void
    {
        $bytes = str_repeat('A', ResourceService::MAX_BYTES);
        $file  = $this->makeFile(
            name: 'cap.png',
            size: ResourceService::MAX_BYTES,
            mtime: 1700000005,
            content: $bytes
        );

        $this->serve->method('findFile')->willReturn($file);
        $this->serve->method('contentTypeForFilename')->willReturn('image/png');

        $response = $this->controller->getResource(filename: 'cap.png');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testListReturnsResourcesSortedByModifiedDesc(): void
    {
        $oldest = $this->makeFile(name: 'a.png', size: 100, mtime: 1700000000);
        $newest = $this->makeFile(name: 'b.png', size: 200, mtime: 1700000200);
        $middle = $this->makeFile(name: 'c.png', size: 150, mtime: 1700000100);

        // Intentionally unordered to prove the controller sorts.
        $this->serve->method('listFiles')
            ->willReturn([$oldest, $newest, $middle]);
        $this->serve->method('formatTimestamp')
            ->willReturnCallback(static fn (int $epoch): string => 'ts_'.$epoch);

        $response = $this->controller->listResources();
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $body['status']);
        $this->assertCount(3, $body['resources']);
        $this->assertSame('b.png', $body['resources'][0]['name']);
        $this->assertSame('c.png', $body['resources'][1]['name']);
        $this->assertSame('a.png', $body['resources'][2]['name']);
    }

    public function testListEntryShape(): void
    {
        $file = $this->makeFile(name: 'resource_xyz.png', size: 12345, mtime: 1700000000);

        $this->serve->method('listFiles')->willReturn([$file]);
        $this->serve->method('formatTimestamp')
            ->willReturn('2026-04-30T14:11:09+00:00');

        $response = $this->controller->listResources();
        $entry    = $response->getData()['resources'][0];

        $this->assertSame('resource_xyz.png', $entry['name']);
        $this->assertSame('/apps/mydash/resource/resource_xyz.png', $entry['url']);
        $this->assertSame(12345, $entry['size']);
        $this->assertSame('2026-04-30T14:11:09+00:00', $entry['modifiedAt']);
        // Helper key MUST NOT leak.
        $this->assertArrayNotHasKey('_mtime', $entry);
    }

    public function testListWithNoFolderReturnsEmptyArray(): void
    {
        // Simulate "folder absent" by returning an empty listing.
        $this->serve->method('listFiles')->willReturn([]);

        $response = $this->controller->listResources();
        $body     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $body['status']);
        $this->assertSame([], $body['resources']);
    }
}
