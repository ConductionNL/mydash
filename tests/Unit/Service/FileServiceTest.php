<?php

/**
 * FileService Test
 *
 * Covers REQ-LBN-004: filename validation rejects path traversal,
 * special chars, and oversized input; the admin-configured extension
 * allow-list is enforced; existing files are overwritten and their
 * fileId returned; and raw exception messages are NEVER leaked to the
 * caller (only typed exceptions surface).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Exception\FileTypeNotAllowedException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Exception\StorageFailureException;
use OCA\MyDash\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileServiceTest extends TestCase
{
    private FileService $service;

    /** @var IRootFolder&MockObject */
    private $rootFolder;

    /** @var IURLGenerator&MockObject */
    private $urlGenerator;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    /** @var Folder&MockObject */
    private $userFolder;

    protected function setUp(): void
    {
        $this->rootFolder    = $this->createMock(IRootFolder::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->userFolder    = $this->createMock(Folder::class);

        $this->settingMapper->method('getValue')->willReturn(null);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturnCallback(static function (string $route, array $args): string {
                return 'https://nc/files?openfile=' . ($args['openfile'] ?? '');
            });

        $this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

        $this->service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $this->settingMapper,
        );
    }

    /**
     * REQ-LBN-004 task 6.1: filename traversal sequence rejected.
     */
    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: '../../etc/passwd',
            dir: '/'
        );
    }

    public function testForwardSlashIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'foo/bar.txt',
            dir: '/'
        );
    }

    public function testBackslashIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'foo\\bar.txt',
            dir: '/'
        );
    }

    public function testNullByteIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: "evil\0.txt",
            dir: '/'
        );
    }

    public function testEmptyFilenameIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: '',
            dir: '/'
        );
    }

    public function testOversizedFilenameIsRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        // 256 chars (> 255 cap). Use only allowed chars so the size check
        // is what fires.
        $this->service->createFile(
            userId: 'alice',
            filename: str_repeat('a', 252) . '.txt',
            dir: '/'
        );
    }

    public function testSpecialCharactersAreRejected(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'evil*.txt',
            dir: '/'
        );
    }

    public function testDirectoryTraversalIsRejected(): void
    {
        $this->expectException(InvalidDirectoryException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'safe.txt',
            dir: '/../etc'
        );
    }

    public function testDirectoryNullByteIsRejected(): void
    {
        $this->expectException(InvalidDirectoryException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'safe.txt',
            dir: "/foo\0bar"
        );
    }

    /**
     * REQ-LBN-004 task 6.2: extension allow-list — disallowed extension
     * surfaces as FileTypeNotAllowedException (HTTP 400).
     */
    public function testDisallowedExtensionIsRejected(): void
    {
        $this->expectException(FileTypeNotAllowedException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'foo.exe',
            dir: '/'
        );
    }

    public function testNoExtensionIsRejected(): void
    {
        $this->expectException(FileTypeNotAllowedException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'README',
            dir: '/'
        );
    }

    /**
     * REQ-LBN-004 task 6.2: allowed extension passes through to storage.
     */
    public function testAllowedExtensionWritesNewFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);

        $this->userFolder->method('nodeExists')->with('hello.txt')->willReturn(false);
        $this->userFolder->method('newFile')->with('hello.txt', 'hi')->willReturn($file);

        $result = $this->service->createFile(
            userId: 'alice',
            filename: 'hello.txt',
            dir: '/',
            content: 'hi'
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(42, $result['fileId']);
        $this->assertSame('https://nc/files?openfile=42', $result['url']);
    }

    /**
     * REQ-LBN-004 task 6.3: existing file is overwritten and its fileId
     * matches the existing entry.
     */
    public function testExistingFileIsOverwritten(): void
    {
        $existing = $this->createMock(File::class);
        $existing->method('getId')->willReturn(99);
        $existing->expects($this->once())
            ->method('putContent')
            ->with('new contents');

        $this->userFolder->method('nodeExists')->with('report.docx')->willReturn(true);
        $this->userFolder->method('get')->with('report.docx')->willReturn($existing);
        $this->userFolder->expects($this->never())->method('newFile');

        $result = $this->service->createFile(
            userId: 'alice',
            filename: 'report.docx',
            dir: '/',
            content: 'new contents'
        );

        $this->assertSame(99, $result['fileId']);
    }

    /**
     * REQ-LBN-004 task 6.4: raw exception messages are NEVER leaked.
     * NotPermittedException-from-storage surfaces as the typed
     * StorageFailureException with a curated display message.
     */
    public function testStorageFailureIsWrappedNotLeaked(): void
    {
        $this->userFolder->method('nodeExists')->willReturn(false);
        $this->userFolder->method('newFile')
            ->willThrowException(new NotPermittedException('disk full at /var/lib/raw'));

        try {
            $this->service->createFile(
                userId: 'alice',
                filename: 'hello.txt',
                dir: '/',
                content: 'hi'
            );
            $this->fail('Expected StorageFailureException');
        } catch (StorageFailureException $e) {
            // Curated, never the underlying raw message.
            $this->assertStringNotContainsString('disk full', $e->getMessage());
            $this->assertStringNotContainsString('/var/lib/raw', $e->getMessage());
        }
    }

    public function testTargetSubdirectoryAutoCreated(): void
    {
        $sub  = $this->createMock(Folder::class);
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);

        $this->userFolder->method('get')
            ->with('/notes')
            ->willThrowException(new NotFoundException());
        $this->userFolder->method('newFolder')
            ->with('/notes')
            ->willReturn($sub);

        $sub->method('nodeExists')->with('hello.txt')->willReturn(false);
        $sub->method('newFile')->willReturn($file);

        $result = $this->service->createFile(
            userId: 'alice',
            filename: 'hello.txt',
            dir: '/notes',
            content: ''
        );

        $this->assertSame(7, $result['fileId']);
    }

    public function testCustomAllowListReplacesDefault(): void
    {
        $custom = $this->createMock(AdminSettingMapper::class);
        $custom->method('getValue')->willReturn(['md', 'csv']);

        $service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $custom,
        );

        // .txt is in the DEFAULT list but not in this custom list.
        $this->expectException(FileTypeNotAllowedException::class);
        $service->createFile(
            userId: 'alice',
            filename: 'note.txt',
            dir: '/'
        );
    }

    public function testGetAllowedExtensionsFallsBackOnEmptyStored(): void
    {
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->settingMapper->method('getValue')->willReturn([]);

        $service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $this->settingMapper,
        );

        $this->assertSame(
            FileService::DEFAULT_ALLOWED_EXTENSIONS,
            $service->getAllowedExtensions()
        );
    }

    public function testSetAllowedExtensionsNormalisesAndPersists(): void
    {
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->settingMapper->expects($this->once())
            ->method('setSetting')
            ->with(
                AdminSetting::KEY_LINK_CREATE_FILE_EXTENSIONS,
                ['txt', 'docx']
            );

        $service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $this->settingMapper,
        );

        $stored = $service->setAllowedExtensions(['TXT', '.docx', '..', 'bad/path']);
        $this->assertSame(['txt', 'docx'], $stored);
    }
}
