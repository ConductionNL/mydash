<?php

/**
 * FileService
 *
 * Implements the link-button-widget capability's createFile flow
 * (REQ-LBN-004). Owns strict filename + directory validation, the
 * admin-configurable extension allow-list (default
 * `txt, md, docx, xlsx, csv, odt`), and overwrite-on-exists semantics.
 *
 * The service NEVER returns the underlying Nextcloud exception messages
 * to the caller — every internal failure is mapped to a typed
 * {@see \OCA\MyDash\Exception\ResourceException} subclass with a curated
 * display message (REQ-LBN-004).
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Exception\FileTypeNotAllowedException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCA\MyDash\Exception\StorageFailureException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use Throwable;

/**
 * Strictly-validated file-creation pipeline for the link-button-widget
 * `createFile` action.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Filesystem + URL
 *                                                 generation + admin
 *                                                 settings are all
 *                                                 unavoidable for this
 *                                                 capability.
 */
class FileService
{
    /**
     * Strict filename pattern (REQ-LBN-004 task 1.2).
     *
     * @var string
     */
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_\-. ]+$/';

    /**
     * Maximum filename length (REQ-LBN-004 task 1.2).
     *
     * @var integer
     */
    private const FILENAME_MAX_LENGTH = 255;

    /**
     * Default allow-list when the admin has not configured one
     * (REQ-LBN-004).
     *
     * @var array<int, string>
     */
    public const DEFAULT_ALLOWED_EXTENSIONS = [
        'txt',
        'md',
        'docx',
        'xlsx',
        'csv',
        'odt',
    ];

    /**
     * Constructor.
     *
     * @param IRootFolder        $rootFolder    Filesystem root accessor.
     * @param IURLGenerator      $urlGenerator  Files-app URL builder.
     * @param AdminSettingMapper $settingMapper Admin-setting store.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IURLGenerator $urlGenerator,
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Create (or overwrite) a file in the user's Files area.
     *
     * Performs every validation step in REQ-LBN-004 BEFORE touching
     * the filesystem so that an invalid request never hits storage.
     * The caller-facing payload is `{status, fileId, url}` — `url`
     * deep-links into the Files app at `?openfile=<fileId>`.
     *
     * @param string $userId   Owner of the target Files area.
     * @param string $filename Leaf filename — strictly validated.
     * @param string $dir      Target subdirectory inside the user's
     *                         folder (default `/`).
     * @param string $content  Bytes to write (default empty).
     *
     * @return array{status: string, fileId: int, url: string}
     *
     * @throws InvalidFilenameException     When the filename fails
     *                                      strict validation.
     * @throws InvalidDirectoryException    When the directory contains
     *                                      `..` or a null byte.
     * @throws FileTypeNotAllowedException  When the extension is not
     *                                      in the allow-list.
     * @throws StorageFailureException      When the underlying
     *                                      filesystem rejects the write.
     */
    public function createFile(
        string $userId,
        string $filename,
        string $dir='/',
        string $content=''
    ): array {
        $this->assertValidFilename(filename: $filename);
        $this->assertValidDirectory(dir: $dir);
        $this->assertAllowedExtension(filename: $filename);

        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
        } catch (Throwable $e) {
            throw new StorageFailureException(
                message: 'Failed to resolve user folder'
            );
        }

        $targetFolder = $this->resolveTargetFolder(
            userFolder: $userFolder,
            dir: $dir
        );

        $file = $this->writeFile(
            folder: $targetFolder,
            filename: $filename,
            content: $content
        );

        $url = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'files.view.index',
            arguments: ['openfile' => $file->getId()]
        );

        return [
            'status' => 'success',
            'fileId' => (int) $file->getId(),
            'url'    => $url,
        ];
    }//end createFile()

    /**
     * Returns the currently configured extension allow-list.
     *
     * Falls back to {@see self::DEFAULT_ALLOWED_EXTENSIONS} when the
     * admin has not customised the list. Stored values are normalised
     * to lowercase, dot-stripped, and de-duplicated; non-matching
     * tokens are silently dropped to keep the allow-list well-formed.
     *
     * @return array<int, string> Normalised allow-list.
     */
    public function getAllowedExtensions(): array
    {
        $stored = $this->settingMapper->getValue(
            key: AdminSetting::KEY_LINK_CREATE_FILE_EXTENSIONS,
            default: null
        );

        if (is_array($stored) === false || count($stored) === 0) {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        $normalised = [];
        foreach ($stored as $value) {
            if (is_string($value) === false) {
                continue;
            }

            $token = strtolower(string: ltrim(string: trim(string: $value), characters: '.'));
            if ($token === '') {
                continue;
            }

            if (preg_match(pattern: '/^[a-z0-9]+$/', subject: $token) !== 1) {
                continue;
            }

            $normalised[$token] = $token;
        }

        if (count($normalised) === 0) {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        return array_values(array: $normalised);
    }//end getAllowedExtensions()

    /**
     * Persist a new admin-configured allow-list.
     *
     * Accepts any array; identical normalisation rules as
     * {@see self::getAllowedExtensions()} are applied before storage.
     * Non-string entries are silently dropped. Empty input falls
     * back to the default allow-list (the admin cannot lock everyone
     * out by saving an empty array).
     *
     * @param array $extensions Extensions to allow (string entries
     *                          are kept; everything else is dropped).
     *
     * @return array<int, string> The stored, normalised allow-list.
     */
    public function setAllowedExtensions(array $extensions): array
    {
        $normalised = [];
        foreach ($extensions as $value) {
            if (is_string($value) === false) {
                continue;
            }

            $token = strtolower(string: ltrim(string: trim(string: $value), characters: '.'));
            if ($token === '') {
                continue;
            }

            if (preg_match(pattern: '/^[a-z0-9]+$/', subject: $token) !== 1) {
                continue;
            }

            $normalised[$token] = $token;
        }

        if (count($normalised) === 0) {
            $stored = self::DEFAULT_ALLOWED_EXTENSIONS;
        } else {
            $stored = array_values(array: $normalised);
        }

        $this->settingMapper->setSetting(
            key: AdminSetting::KEY_LINK_CREATE_FILE_EXTENSIONS,
            value: $stored
        );

        return $stored;
    }//end setAllowedExtensions()

    /**
     * Reject filenames that fail any of the REQ-LBN-004 task 1.2 checks.
     *
     * @param string $filename The candidate filename.
     *
     * @return void
     *
     * @throws InvalidFilenameException When the filename is invalid.
     */
    private function assertValidFilename(string $filename): void
    {
        if ($filename === '' || strlen(string: $filename) > self::FILENAME_MAX_LENGTH) {
            throw new InvalidFilenameException();
        }

        if (str_contains(haystack: $filename, needle: "\0") === true) {
            throw new InvalidFilenameException();
        }

        if (str_contains(haystack: $filename, needle: '..') === true) {
            throw new InvalidFilenameException();
        }

        if (str_contains(haystack: $filename, needle: '/') === true) {
            throw new InvalidFilenameException();
        }

        if (str_contains(haystack: $filename, needle: '\\') === true) {
            throw new InvalidFilenameException();
        }

        if (preg_match(pattern: self::FILENAME_PATTERN, subject: $filename) !== 1) {
            throw new InvalidFilenameException();
        }
    }//end assertValidFilename()

    /**
     * Reject directories that fail any of the REQ-LBN-004 task 1.3 checks.
     *
     * @param string $dir The candidate directory.
     *
     * @return void
     *
     * @throws InvalidDirectoryException When the directory is invalid.
     */
    private function assertValidDirectory(string $dir): void
    {
        if (str_contains(haystack: $dir, needle: "\0") === true) {
            throw new InvalidDirectoryException();
        }

        if (str_contains(haystack: $dir, needle: '..') === true) {
            throw new InvalidDirectoryException();
        }
    }//end assertValidDirectory()

    /**
     * Reject filenames whose extension is not in the allow-list
     * (REQ-LBN-004 task 1.4).
     *
     * @param string $filename The candidate filename (already
     *                         validated by {@see self::assertValidFilename}).
     *
     * @return void
     *
     * @throws FileTypeNotAllowedException When the extension is not allowed.
     */
    private function assertAllowedExtension(string $filename): void
    {
        $extension = strtolower(
            string: pathinfo(path: $filename, flags: PATHINFO_EXTENSION)
        );

        if ($extension === '') {
            throw new FileTypeNotAllowedException();
        }

        $allowed = $this->getAllowedExtensions();
        if (in_array(needle: $extension, haystack: $allowed, strict: true) === false) {
            throw new FileTypeNotAllowedException();
        }
    }//end assertAllowedExtension()

    /**
     * Resolve (or auto-create) the target subdirectory inside the
     * user's Files area (REQ-LBN-004 task 1.5).
     *
     * @param Folder $userFolder Owner's Files-area root.
     * @param string $dir        Target subdirectory.
     *
     * @return Folder The resolved (or freshly created) folder.
     *
     * @throws StorageFailureException When the folder cannot be
     *                                 resolved or created.
     */
    private function resolveTargetFolder(Folder $userFolder, string $dir): Folder
    {
        $normalised = ('/'.trim(string: $dir, characters: '/'));
        if ($normalised === '/') {
            return $userFolder;
        }

        try {
            $node = $userFolder->get(path: $normalised);
            if ($node instanceof Folder) {
                return $node;
            }

            // A file (not a folder) already occupies the path.
            throw new StorageFailureException(
                message: 'Failed to resolve target folder'
            );
        } catch (NotFoundException $e) {
            // Auto-create missing subdirectory chain.
            try {
                return $userFolder->newFolder(path: $normalised);
            } catch (Throwable $createError) {
                throw new StorageFailureException(
                    message: 'Failed to create target folder'
                );
            }
        } catch (StorageFailureException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StorageFailureException(
                message: 'Failed to resolve target folder'
            );
        }//end try
    }//end resolveTargetFolder()

    /**
     * Write the file with overwrite-on-exists semantics
     * (REQ-LBN-004 task 1.6).
     *
     * @param Folder $folder   Target folder.
     * @param string $filename Leaf filename.
     * @param string $content  Bytes to write.
     *
     * @return File The persisted file node.
     *
     * @throws StorageFailureException When the write fails.
     */
    private function writeFile(Folder $folder, string $filename, string $content): File
    {
        try {
            if ($folder->nodeExists(path: $filename) === true) {
                $existing = $folder->get(path: $filename);
                if ($existing instanceof File) {
                    $existing->putContent(data: $content);
                    return $existing;
                }

                // A folder with the same name already exists.
                throw new StorageFailureException(
                    message: 'Failed to write file'
                );
            }

            // Folder::newFile() returns a File node — the contract is
            // statically guaranteed by Nextcloud's Files API, so no
            // instanceof check is required (PHPStan would mark it as
            // an always-true comparison).
            return $folder->newFile(path: $filename, content: $content);
        } catch (StorageFailureException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StorageFailureException(
                message: 'Failed to write file'
            );
        }//end try
    }//end writeFile()
}//end class
