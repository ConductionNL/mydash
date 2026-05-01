<?php

/**
 * ResourceServeService Test
 *
 * Covers the filesystem + formatting helpers consumed by
 * ResourceServeController (REQ-RES-006..008).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Service\ResourceServeService;
use OCA\MyDash\Service\ResourceService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResourceServeServiceTest extends TestCase
{
    private ResourceServeService $service;

    /** @var IAppData&MockObject */
    private $appData;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var ISimpleFolder&MockObject */
    private $folder;

    protected function setUp(): void
    {
        $this->appData = $this->createMock(IAppData::class);
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->folder  = $this->createMock(ISimpleFolder::class);

        $this->service = new ResourceServeService(
            appData: $this->appData,
            logger: $this->logger,
        );
    }

    public function testFindFileReturnsFileWhenPresent(): void
    {
        $file = $this->createMock(ISimpleFile::class);
        $this->appData->method('getFolder')
            ->with(ResourceService::FOLDER)->willReturn($this->folder);
        $this->folder->method('getFile')->with('resource_abc.png')->willReturn($file);

        $this->assertSame($file, $this->service->findFile(filename: 'resource_abc.png'));
    }

    public function testFindFileReturnsNullWhenFolderMissing(): void
    {
        $this->appData->method('getFolder')
            ->willThrowException(new NotFoundException());

        $this->assertNull($this->service->findFile(filename: 'whatever.png'));
    }

    public function testFindFileReturnsNullWhenFileMissing(): void
    {
        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getFile')
            ->willThrowException(new NotFoundException());

        $this->assertNull($this->service->findFile(filename: 'gone.png'));
    }

    public function testFindFileReturnsNullOnUnexpectedException(): void
    {
        $this->appData->method('getFolder')
            ->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->service->findFile(filename: 'whatever.png'));
    }

    public function testListFilesReturnsAllSimpleFiles(): void
    {
        $a = $this->createMock(ISimpleFile::class);
        $b = $this->createMock(ISimpleFile::class);
        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getDirectoryListing')->willReturn([$a, $b]);

        $this->assertSame([$a, $b], $this->service->listFiles());
    }

    public function testListFilesReturnsEmptyArrayWhenFolderMissing(): void
    {
        $this->appData->method('getFolder')
            ->willThrowException(new NotFoundException());

        $this->assertSame([], $this->service->listFiles());
    }

    public function testListFilesReturnsEmptyArrayOnUnexpectedException(): void
    {
        $this->appData->method('getFolder')
            ->willThrowException(new \RuntimeException('disk failure'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->service->listFiles());
    }

    public function testListFilesSkipsNonFileEntries(): void
    {
        $a       = $this->createMock(ISimpleFile::class);
        $bogus   = new \stdClass();
        $this->appData->method('getFolder')->willReturn($this->folder);
        $this->folder->method('getDirectoryListing')->willReturn([$a, $bogus]);

        $this->assertSame([$a], $this->service->listFiles());
    }

    /**
     * @dataProvider contentTypeProvider
     */
    public function testContentTypeForFilename(string $filename, string $expected): void
    {
        $this->assertSame($expected, $this->service->contentTypeForFilename(filename: $filename));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function contentTypeProvider(): array
    {
        return [
            'png lowercase'   => ['resource_a.png', 'image/png'],
            'jpg lowercase'   => ['resource_a.jpg', 'image/jpeg'],
            'jpeg lowercase'  => ['resource_a.jpeg', 'image/jpeg'],
            'gif lowercase'   => ['resource_a.gif', 'image/gif'],
            'svg lowercase'   => ['resource_a.svg', 'image/svg+xml'],
            'webp lowercase'  => ['resource_a.webp', 'image/webp'],
            'png uppercase'   => ['resource_a.PNG', 'image/png'],
            'svg uppercase'   => ['resource_a.SVG', 'image/svg+xml'],
            'unknown ext'     => ['resource_a.bin', 'application/octet-stream'],
            'no extension'    => ['noext', 'application/octet-stream'],
            'empty extension' => ['weird.', 'application/octet-stream'],
            'dotfile'         => ['.hidden', 'application/octet-stream'],
        ];
    }

    public function testFormatTimestampReturnsIso8601Utc(): void
    {
        // 2023-11-14T22:13:20+00:00
        $iso = $this->service->formatTimestamp(epoch: 1700000000);
        $this->assertSame('2023-11-14T22:13:20+00:00', $iso);
    }

    public function testFormatTimestampUsesUtcRegardlessOfPhpDefault(): void
    {
        $iso = $this->service->formatTimestamp(epoch: 0);
        $this->assertSame('1970-01-01T00:00:00+00:00', $iso);
    }
}
