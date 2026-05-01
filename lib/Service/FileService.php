<?php

/**
 * FileService
 *
 * Service for creating files in a user's Nextcloud Files space. Exposes a
 * single `createFile()` method that applies strict filename validation, a
 * configurable extension allow-list, directory traversal rejection, and
 * overwrite-on-exists semantics (REQ-LBN-004).
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

use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Exception\ForbiddenExtensionException;
use OCA\MyDash\Exception\InvalidDirectoryException;
use OCA\MyDash\Exception\InvalidFilenameException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;

/**
 * Creates files in user Nextcloud space with strict validation.
 */
class FileService
{

    /**
     * Default allowed file extensions.
     *
     * @var string[]
     */
    private const DEFAULT_ALLOWED_EXTENSIONS = ['txt', 'md', 'docx', 'xlsx', 'csv', 'odt'];

    /**
     * Admin setting key for the allow-list.
     *
     * @var string
     */
    private const SETTING_KEY = 'file_create_extensions';

    /**
     * Constructor.
     *
     * @param IRootFolder        $rootFolder    Nextcloud root folder.
     * @param IURLGenerator      $urlGenerator  URL generator.
     * @param AdminSettingMapper $settingMapper Admin setting mapper.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IURLGenerator $urlGenerator,
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Create (or overwrite) a file in the user's Files space.
     *
     * Validates filename and directory defensively, checks the extension
     * against the admin allow-list, resolves the user folder, creates any
     * missing subdirectory, and either creates or overwrites the file.
     *
     * @param string $userId   Nextcloud user ID.
     * @param string $filename Desired filename (basename only, no path).
     * @param string $dir      Target directory inside the user folder.
     * @param string $content  File content (may be empty for placeholder).
     *
     * @return array{fileId:int,url:string} Success payload with `fileId`
     *                                      and a Files-app open URL.
     *
     * @throws InvalidFilenameException    When `$filename` is empty, too long,
     *                                     or contains disallowed characters.
     * @throws InvalidDirectoryException   When `$dir` contains traversal
     *                                     sequences or null bytes.
     * @throws ForbiddenExtensionException When the file extension is not in
     *                                     the allow-list.
     */
    public function createFile(
        string $userId,
        string $filename,
        string $dir,
        string $content
    ): array {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->validateFilename($filename);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->validateDirectory($dir);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->validateExtension($filename);

        $userFolder   = $this->rootFolder->getUserFolder(userId: $userId);
        $targetFolder = $userFolder;

        // Create subdirectory if it is not root.
        $normalizedDir = trim(string: $dir, characters: '/');
        if ($normalizedDir !== '') {
            if ($userFolder->nodeExists(path: $normalizedDir) === false) {
                $userFolder->newFolder(path: $normalizedDir);
            }

            $resolved = $userFolder->get(path: $normalizedDir);
            if (($resolved instanceof Folder) === false) {
                throw new \RuntimeException(
                    'Expected '.$normalizedDir.' to be a folder, got file'
                );
            }

            $targetFolder = $resolved;
        }

        // Overwrite if the file already exists; create otherwise.
        if ($targetFolder->nodeExists(path: $filename) === true) {
            $existing = $targetFolder->get(path: $filename);
            if (($existing instanceof File) === false) {
                throw new \RuntimeException(
                    'Expected '.$filename.' to be a file, got folder'
                );
            }

            $file = $existing;
        } else {
            $file = $targetFolder->newFile(path: $filename);
        }

        $file->putContent(data: $content);

        $fileId = $file->getId();

        $url = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'files.view.index',
            arguments: ['openfile' => $fileId]
        );

        return [
            'fileId' => $fileId,
            'url'    => $url,
        ];
    }//end createFile()

    /**
     * Validate the filename against strict rules (REQ-LBN-004).
     *
     * Rejects:
     * - empty string
     * - length > 255 characters
     * - path traversal (`..`)
     * - forward slash `/`
     * - backslash `\`
     * - null byte
     * - any character outside `^[a-zA-Z0-9_\-. ]+$`
     *
     * @param string $filename The filename to validate.
     *
     * @return void
     *
     * @throws InvalidFilenameException When any rule is violated.
     */
    private function validateFilename(string $filename): void
    {
        if ($filename === '') {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (strlen(string: $filename) > 255) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (str_contains(haystack: $filename, needle: "\0") === true) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (str_contains(haystack: $filename, needle: '..') === true) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (str_contains(haystack: $filename, needle: '/') === true) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (str_contains(haystack: $filename, needle: '\\') === true) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }

        if (preg_match(pattern: '/^[a-zA-Z0-9_\-. ]+$/', subject: $filename) !== 1) {
            throw new InvalidFilenameException(message: 'Invalid filename');
        }
    }//end validateFilename()

    /**
     * Validate the target directory for path traversal (REQ-LBN-004).
     *
     * Rejects any dir containing `..` or a null byte.
     *
     * @param string $dir The directory path to validate.
     *
     * @return void
     *
     * @throws InvalidDirectoryException When the dir is unsafe.
     */
    private function validateDirectory(string $dir): void
    {
        if (str_contains(haystack: $dir, needle: "\0") === true) {
            throw new InvalidDirectoryException(message: 'Invalid directory');
        }

        if (str_contains(haystack: $dir, needle: '..') === true) {
            throw new InvalidDirectoryException(message: 'Invalid directory');
        }
    }//end validateDirectory()

    /**
     * Validate the file extension against the admin-configured allow-list.
     *
     * Reads the `file_create_extensions` admin setting (JSON array of
     * lowercase extension strings). Falls back to DEFAULT_ALLOWED_EXTENSIONS
     * when the setting is absent or unparseable.
     *
     * @param string $filename The filename whose extension to check.
     *
     * @return void
     *
     * @throws ForbiddenExtensionException When the extension is not allowed.
     */
    private function validateExtension(string $filename): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $ext = strtolower(string: pathinfo($filename, PATHINFO_EXTENSION));

        $allowed = $this->getAllowedExtensions();

        if (in_array(needle: $ext, haystack: $allowed, strict: true) === false) {
            throw new ForbiddenExtensionException(message: 'File type not allowed');
        }
    }//end validateExtension()

    /**
     * Return the admin-configured extension allow-list.
     *
     * Falls back to DEFAULT_ALLOWED_EXTENSIONS when the setting row is
     * absent, null, not a JSON array, or contains non-string elements.
     *
     * @return string[] Lowercase extension strings (without leading dot).
     */
    private function getAllowedExtensions(): array
    {
        $raw = $this->settingMapper->getValue(
            key: self::SETTING_KEY,
            default: null
        );

        if (is_array($raw) === false) {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        $clean = [];
        foreach ($raw as $item) {
            if (is_string($item) === true && $item !== '') {
                $clean[] = strtolower(string: $item);
            }
        }

        if (empty($clean) === true) {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        return $clean;
    }//end getAllowedExtensions()
}//end class
