<?php

/**
 * FileService Test
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

use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Exception\ForbiddenExtensionException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileService::createFile validation.
 */
class FileServiceTest extends TestCase
{

    /** @var IRootFolder&MockObject */
    private $rootFolder;

    /** @var IURLGenerator&MockObject */
    private $urlGenerator;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    private FileService $service;

    protected function setUp(): void
    {
        $this->rootFolder    = $this->createMock(IRootFolder::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);

        // Default: allow-list falls back to DEFAULT_ALLOWED_EXTENSIONS.
        $this->settingMapper->method('getValue')->willReturn(null);

        $this->service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $this->settingMapper,
        );
    }

    // -------------------------------------------------------------------------
    // Filename validation (REQ-LBN-004 + PHPUnit task 6.1)
    // -------------------------------------------------------------------------

    public function testEmptyFilenameThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: '',
            dir: '/',
            content: ''
        );
    }

    public function testFilenameExceeding255CharsThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: str_repeat(string: 'a', times: 252) . '.txt',
            dir: '/',
            content: ''
        );
    }

    public function testPathTraversalDoubleDotThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: '../../etc/passwd',
            dir: '/',
            content: ''
        );
    }

    public function testPathTraversalForwardSlashThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'sub/etc/passwd',
            dir: '/',
            content: ''
        );
    }

    public function testPathTraversalBackslashThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'sub\\evil.txt',
            dir: '/',
            content: ''
        );
    }

    public function testNullByteInFilenameThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: "good\0bad.txt",
            dir: '/',
            content: ''
        );
    }

    public function testSpecialCharsInFilenameThrows(): void
    {
        $this->expectException(InvalidFilenameException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'bad<script>.txt',
            dir: '/',
            content: ''
        );
    }

    // -------------------------------------------------------------------------
    // Directory validation
    // -------------------------------------------------------------------------

    public function testDirTraversalDoubleDotThrows(): void
    {
        $this->expectException(InvalidDirectoryException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'valid.txt',
            dir: '../secret',
            content: ''
        );
    }

    public function testNullByteInDirThrows(): void
    {
        $this->expectException(InvalidDirectoryException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'valid.txt',
            dir: "sub\0dir",
            content: ''
        );
    }

    // -------------------------------------------------------------------------
    // Extension allow-list (REQ-LBN-004 + PHPUnit task 6.2)
    // -------------------------------------------------------------------------

    public function testDisallowedExtensionThrows(): void
    {
        $this->expectException(ForbiddenExtensionException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'evil.exe',
            dir: '/',
            content: ''
        );
    }

    public function testDisallowedExtensionPhpThrows(): void
    {
        $this->expectException(ForbiddenExtensionException::class);
        $this->service->createFile(
            userId: 'alice',
            filename: 'shell.php',
            dir: '/',
            content: ''
        );
    }

    public function testCustomAllowListRespected(): void
    {
        // Override settingMapper to return a custom allow-list.
        $mapper = $this->createMock(AdminSettingMapper::class);
        $mapper->method('getValue')->willReturn(['txt', 'md', 'docx']);

        $service = new FileService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
            settingMapper: $mapper,
        );

        $this->expectException(ForbiddenExtensionException::class);
        $service->createFile(
            userId: 'alice',
            filename: 'data.csv',   // csv not in custom list
            dir: '/',
            content: ''
        );
    }

    // -------------------------------------------------------------------------
    // Happy path — new file
    // -------------------------------------------------------------------------

    public function testAllowedExtensionCreatesFile(): void
    {
        $fileMock   = $this->createMock(File::class);
        $folderMock = $this->createMock(Folder::class);

        $fileMock->method('getId')->willReturn(42);
        $folderMock->method('nodeExists')->willReturn(false);
        $folderMock->method('newFile')->willReturn($fileMock);

        $this->rootFolder->method('getUserFolder')->willReturn($folderMock);
        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://nc/index.php/apps/files/?openfile=42');

        $result = $this->service->createFile(
            userId: 'alice',
            filename: 'report.docx',
            dir: '/',
            content: ''
        );

        $this->assertSame(42, $result['fileId']);
        $this->assertStringContainsString('openfile=42', $result['url']);
    }

    // -------------------------------------------------------------------------
    // Overwrite semantics (PHPUnit task 6.3)
    // -------------------------------------------------------------------------

    public function testExistingFileIsOverwritten(): void
    {
        $existingFile = $this->createMock(File::class);
        $existingFile->method('getId')->willReturn(7);
        $existingFile->expects($this->once())->method('putContent')->with('');

        $folderMock = $this->createMock(Folder::class);
        $folderMock->method('nodeExists')->willReturn(true);
        $folderMock->method('get')->willReturn($existingFile);
        // newFile must NOT be called.
        $folderMock->expects($this->never())->method('newFile');

        $this->rootFolder->method('getUserFolder')->willReturn($folderMock);
        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://nc/index.php/apps/files/?openfile=7');

        $result = $this->service->createFile(
            userId: 'alice',
            filename: 'report.docx',
            dir: '/',
            content: ''
        );

        $this->assertSame(7, $result['fileId']);
    }

    // -------------------------------------------------------------------------
    // Exception messages not leaked (PHPUnit task 6.4)
    // -------------------------------------------------------------------------

    public function testInvalidFilenameMessageIsSafe(): void
    {
        try {
            $this->service->createFile(
                userId: 'alice',
                filename: '../../etc/passwd',
                dir: '/',
                content: ''
            );
            $this->fail('Expected InvalidFilenameException');
        } catch (InvalidFilenameException $e) {
            // The display message must not contain raw path or internal details.
            $this->assertStringNotContainsString('/etc/passwd', $e->getDisplayMessage());
            $this->assertNotEmpty($e->getDisplayMessage());
        }
    }

    public function testForbiddenExtensionMessageIsSafe(): void
    {
        try {
            $this->service->createFile(
                userId: 'alice',
                filename: 'shell.php',
                dir: '/',
                content: ''
            );
            $this->fail('Expected ForbiddenExtensionException');
        } catch (ForbiddenExtensionException $e) {
            $this->assertStringNotContainsString('php', $e->getDisplayMessage());
            $this->assertNotEmpty($e->getDisplayMessage());
        }
    }
}//end class
