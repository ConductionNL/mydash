<?php

/**
 * FilesWidgetService Test
 *
 * Covers REQ-FLS-003 (folder listing + pagination), REQ-FLS-004
 * (view-time ACL — unreadable nodes are filtered out, unreadable
 * folder triggers NoAccessException), REQ-FLS-007 (upload dual-gate
 * — placement allowUpload + viewer write permission), REQ-FLS-008
 * (delete dual-gate + descendant containment check), REQ-FLS-009
 * (FolderNotFoundException for missing folder), REQ-FLS-010 (MIME
 * filter applied server-side).
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

use OCA\MyDash\Exception\FolderNotFoundException;
use OCA\MyDash\Exception\NoAccessException;
use OCA\MyDash\Service\FilesWidgetService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilesWidgetServiceTest extends TestCase
{
    private FilesWidgetService $service;

    /** @var IRootFolder&MockObject */
    private $rootFolder;

    /** @var IURLGenerator&MockObject */
    private $urlGenerator;

    /** @var Folder&MockObject */
    private $userFolder;

    /** @var Folder&MockObject */
    private $configuredFolder;

    protected function setUp(): void
    {
        $this->rootFolder       = $this->createMock(IRootFolder::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);
        $this->userFolder       = $this->createMock(Folder::class);
        $this->configuredFolder = $this->createMock(Folder::class);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://nc/preview');

        $this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

        $this->service = new FilesWidgetService(
            rootFolder: $this->rootFolder,
            urlGenerator: $this->urlGenerator,
        );
    }

    /**
     * Build a File mock with the supplied metadata.
     */
    private function buildFile(
        int $id,
        string $name,
        string $mime='text/plain',
        int $size=100,
        int $mtime=1700000000,
        bool $readable=true,
        bool $updateable=false,
        bool $deletable=false
    ): MockObject {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getInternalPath')->willReturn($name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getMTime')->willReturn($mtime);
        $file->method('isReadable')->willReturn($readable);
        $file->method('isUpdateable')->willReturn($updateable);
        $file->method('isDeletable')->willReturn($deletable);

        $perms = 0;
        if ($readable === true) {
            $perms |= Constants::PERMISSION_READ;
        }
        if ($updateable === true) {
            $perms |= Constants::PERMISSION_UPDATE;
        }
        if ($deletable === true) {
            $perms |= Constants::PERMISSION_DELETE;
        }
        $file->method('getPermissions')->willReturn($perms);

        return $file;
    }

    /**
     * REQ-FLS-003: lists every readable child as a metadata blob and
     * returns nextCursor=null when the page covers everything.
     */
    public function testGetContentsForPlacementListsReadableChildren(): void
    {
        $a = $this->buildFile(id: 1, name: 'a.txt');
        $b = $this->buildFile(id: 2, name: 'b.txt');

        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('getDirectoryListing')->willReturn([$a, $b]);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $page = $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99]
        );

        $this->assertCount(2, $page['items']);
        $this->assertNull($page['nextCursor']);
        $names = array_map(static fn(array $item): string => $item['name'], $page['items']);
        $this->assertContains('a.txt', $names);
        $this->assertContains('b.txt', $names);
    }

    /**
     * REQ-FLS-004: silently omits unreadable children.
     */
    public function testUnreadableChildrenAreSilentlyOmitted(): void
    {
        $readable   = $this->buildFile(id: 1, name: 'visible.txt', readable: true);
        $unreadable = $this->buildFile(id: 2, name: 'hidden.txt', readable: false);

        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('getDirectoryListing')->willReturn([$readable, $unreadable]);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $page = $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99]
        );

        $this->assertCount(1, $page['items']);
        $this->assertSame('visible.txt', $page['items'][0]['name']);
    }

    /**
     * REQ-FLS-009: throws FolderNotFoundException when the configured
     * folder cannot be resolved.
     */
    public function testFolderNotFoundExceptionWhenFolderMissing(): void
    {
        $this->userFolder->method('getById')->willReturn([]);

        $this->expectException(FolderNotFoundException::class);
        $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99]
        );
    }

    /**
     * REQ-FLS-004: throws NoAccessException when the configured folder
     * is not readable by the viewer.
     */
    public function testNoAccessExceptionWhenFolderUnreadable(): void
    {
        $this->configuredFolder->method('isReadable')->willReturn(false);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $this->expectException(NoAccessException::class);
        $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99]
        );
    }

    /**
     * REQ-FLS-010: server-side MIME filter only returns matching files.
     */
    public function testMimeFilterIsApplied(): void
    {
        $image = $this->buildFile(id: 1, name: 'pic.png', mime: 'image/png');
        $pdf   = $this->buildFile(id: 2, name: 'doc.pdf', mime: 'application/pdf');
        $text  = $this->buildFile(id: 3, name: 'note.txt', mime: 'text/plain');

        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('getDirectoryListing')->willReturn([$image, $pdf, $text]);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $page = $this->service->getContentsForPlacement(
            userId: 'alice',
            config: [
                'fileId'         => 99,
                'mimeTypeFilter' => ['image/*'],
            ]
        );

        $this->assertCount(1, $page['items']);
        $this->assertSame('pic.png', $page['items'][0]['name']);
    }

    /**
     * REQ-FLS-003 / design D3: cursor-based pagination slices the
     * result and emits a nextCursor when more items remain.
     */
    public function testPaginationProducesNextCursorWhenMoreItemsRemain(): void
    {
        $files = [];
        for ($i = 1; $i <= 75; $i++) {
            $files[] = $this->buildFile(id: $i, name: sprintf('file-%03d.txt', $i));
        }

        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('getDirectoryListing')->willReturn($files);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $first = $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99]
        );
        $this->assertCount(50, $first['items']);
        $this->assertSame('50', $first['nextCursor']);

        $second = $this->service->getContentsForPlacement(
            userId: 'alice',
            config: ['fileId' => 99],
            currentSubPath: '/',
            limit: 50,
            cursor: '50'
        );
        $this->assertCount(25, $second['items']);
        $this->assertNull($second['nextCursor']);
    }

    /**
     * REQ-FLS-003: per-item canEdit / canDelete flags reflect the
     * underlying NC permission bits.
     */
    public function testBuildFileMetadataSurfacesPermissionFlags(): void
    {
        $file = $this->buildFile(
            id: 42,
            name: 'editable.txt',
            updateable: true,
            deletable: true
        );

        $metadata = $this->service->buildFileMetadata(node: $file);
        $this->assertSame(42, $metadata['fileId']);
        $this->assertTrue($metadata['canEdit']);
        $this->assertTrue($metadata['canDelete']);
        $this->assertFalse($metadata['isFolder']);
    }

    /**
     * REQ-FLS-007: upload throws NoAccessException when allowUpload
     * is false in the placement config.
     */
    public function testUploadThrowsNoAccessWhenAllowUploadIsFalse(): void
    {
        $this->expectException(NoAccessException::class);
        $this->service->uploadFiles(
            userId: 'alice',
            config: ['fileId' => 99, 'allowUpload' => false],
            currentSubPath: null,
            uploadedFiles: []
        );
    }

    /**
     * REQ-FLS-007: upload throws NoAccessException when allowUpload
     * is true but the viewer cannot write to the target folder.
     */
    public function testUploadThrowsNoAccessWhenFolderNotWriteable(): void
    {
        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('isUpdateable')->willReturn(false);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $this->expectException(NoAccessException::class);
        $this->service->uploadFiles(
            userId: 'alice',
            config: ['fileId' => 99, 'allowUpload' => true],
            currentSubPath: null,
            uploadedFiles: []
        );
    }

    /**
     * REQ-FLS-008: delete throws NoAccessException when the placement
     * does not allow delete actions.
     */
    public function testDeleteThrowsNoAccessWhenAllowDeleteIsFalse(): void
    {
        $this->expectException(NoAccessException::class);
        $this->service->deleteFile(
            userId: 'alice',
            config: ['fileId' => 99, 'allowDelete' => false],
            fileId: 1
        );
    }

    /**
     * M3: MAX_UPLOAD_BYTES constant is 50 MiB (50 * 1024 * 1024).
     */
    public function testMaxUploadBytesIs50Mib(): void
    {
        $this->assertSame(52428800, FilesWidgetService::MAX_UPLOAD_BYTES);
    }//end testMaxUploadBytesIs50Mib()

    /**
     * M3: upload entries with UPLOAD_ERR_* flags produce an upload_failed
     * error and do not attempt to write a file.
     */
    public function testUploadReturnsErrorForFailedUploadEntries(): void
    {
        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('isUpdateable')->willReturn(true);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $result = $this->service->uploadFiles(
            userId: 'alice',
            config: ['fileId' => 99, 'allowUpload' => true],
            currentSubPath: null,
            uploadedFiles: [
                [
                    'name'     => 'bad.txt',
                    'tmp_name' => '/tmp/doesnotexist',
                    'error'    => UPLOAD_ERR_PARTIAL,
                    'size'     => 100,
                ],
            ]
        );

        $this->assertCount(0, $result['uploaded']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('upload_failed', $result['errors'][0]['error']);
        $this->assertSame('bad.txt', $result['errors'][0]['name']);
    }//end testUploadReturnsErrorForFailedUploadEntries()

    /**
     * M3: when a file comes through with UPLOAD_ERR_OK but the path is
     * not a genuine PHP upload (is_uploaded_file returns false), the
     * entry must be rejected with upload_failed.
     *
     * In a unit-test environment every file path fails is_uploaded_file,
     * so this covers the security invariant.
     */
    public function testUploadRejectsNonUploadedFilePaths(): void
    {
        $this->configuredFolder->method('isReadable')->willReturn(true);
        $this->configuredFolder->method('isUpdateable')->willReturn(true);
        $this->userFolder->method('getById')->willReturn([$this->configuredFolder]);

        $result = $this->service->uploadFiles(
            userId: 'alice',
            config: ['fileId' => 99, 'allowUpload' => true],
            currentSubPath: null,
            uploadedFiles: [
                [
                    'name'     => 'crafted.txt',
                    'tmp_name' => '/etc/passwd',
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => 100,
                ],
            ]
        );

        $this->assertCount(0, $result['uploaded']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('upload_failed', $result['errors'][0]['error']);
    }//end testUploadRejectsNonUploadedFilePaths()
}
