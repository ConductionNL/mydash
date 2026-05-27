<?php

/**
 * FilesWidgetService
 *
 * Implements the files-widget capability: inline Nextcloud Files browser
 * with permission-aware listing, upload, and delete (REQ-FLS-001..011).
 *
 * The service operates view-time: every request is evaluated against the
 * VIEWING user's permissions. Files and folders the viewer cannot read
 * are silently absent from the response (REQ-FLS-004). Upload and delete
 * are dual-gated by both placement config (`allowUpload` / `allowDelete`)
 * AND per-viewer permission on the underlying folder (REQ-FLS-007,
 * REQ-FLS-008).
 *
 * Folder resolution prefers `fileId` over `folderPath` because the file
 * id survives renames and moves (REQ-FLS-002 design D5). When the
 * configured folder is gone the service throws a typed
 * `FolderNotFoundException` so the controller can map it to HTTP 404
 * with `{error: "folder_not_found"}` instead of leaking a 500
 * (REQ-FLS-009).
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

use OCA\MyDash\Exception\FolderNotFoundException;
use OCA\MyDash\Exception\NoAccessException;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use Throwable;

/**
 * Permission-aware folder browser used by the files widget.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Filesystem + URL
 *                                                 generation are both
 *                                                 unavoidable for this
 *                                                 capability.
 */
class FilesWidgetService
{
    /**
     * Default page size when the caller does not specify one
     * (REQ-FLS-003 design D3).
     *
     * @var integer
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Hard ceiling on page size — even when the caller asks for more
     * we cap here so that a malicious or buggy client cannot force
     * the backend to read an unbounded directory.
     *
     * @var integer
     */
    public const MAX_LIMIT = 200;

    /**
     * Constructor.
     *
     * @param IRootFolder   $rootFolder   Filesystem root accessor.
     * @param IURLGenerator $urlGenerator URL builder for thumbnails and
     *                                    deep links into the Files app.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * List the contents of a folder for the given viewer.
     *
     * Resolves the configured folder (fileId preferred over path),
     * applies the optional MIME filter, sorts the result, and slices
     * out the requested page. Folders the viewer cannot read are
     * skipped silently.
     *
     * @param string      $userId         Viewing user's UID.
     * @param array       $config         Placement config —
     *                                    supports `fileId`,
     *                                    `folderPath`,
     *                                    `mimeTypeFilter`, `sortBy`,
     *                                    `sortDescending`.
     * @param string|null $currentSubPath Optional sub-path relative to
     *                                    the configured folder (set
     *                                    when the user navigates into
     *                                    a subfolder).
     * @param integer     $limit          Page size.
     * @param string      $cursor         Opaque cursor (offset string)
     *                                    supplied by the previous
     *                                    response.
     *
     * @return array{items: list<array<string,mixed>>, nextCursor: ?string}
     *
     * @throws FolderNotFoundException When the configured folder no
     *                                 longer exists.
     * @throws NoAccessException       When the viewer cannot read the
     *                                 configured folder.
     *
     * @spec openspec/specs/files-widget/spec.md
     */
    public function getContentsForPlacement(
        string $userId,
        array $config,
        ?string $currentSubPath=null,
        int $limit=self::DEFAULT_LIMIT,
        string $cursor=''
    ): array {
        $rootFolder = $this->resolveConfiguredFolder(userId: $userId, config: $config);

        $target = $this->descendInto(
            root: $rootFolder,
            subPath: ($currentSubPath ?? '/')
        );

        if ($target->isReadable() === false) {
            throw new NoAccessException();
        }

        $children = $target->getDirectoryListing();

        $mimeFilter = $this->normaliseMimeFilter(filter: ($config['mimeTypeFilter'] ?? []));

        $items = [];
        foreach ($children as $child) {
            if ($child->isReadable() === false) {
                continue;
            }

            if ($child instanceof File && $this->matchesMimeFilter(node: $child, filter: $mimeFilter) === false) {
                continue;
            }

            $items[] = $this->buildFileMetadata(node: $child);
        }

        $items = $this->sortItems(
            items: $items,
            sortBy: ($config['sortBy'] ?? 'name'),
            sortDescending: (bool) ($config['sortDescending'] ?? false)
        );

        return $this->paginate(items: $items, limit: $this->clampLimit(limit: $limit), cursor: $cursor);
    }//end getContentsForPlacement()

    /**
     * Upload one or more files into the configured folder (or a
     * sub-path of it), enforcing the dual gate of placement allow-flag
     * AND viewer write permission on the target folder.
     *
     * @param string                                                               $userId         Viewing user's UID.
     * @param array                                                                $config         Placement config.
     * @param string|null                                                          $currentSubPath Sub-path inside the
     *                                                                                             configured folder.
     * @param array<int, array{name:string, tmp_name:string, size:int, error:int}> $uploadedFiles  Normalised
     *                                                                                             $_FILES
     *                                                                                             entries.
     *
     * @return array{uploaded: list<array<string,mixed>>, errors: list<array<string,mixed>>}
     *
     * @throws FolderNotFoundException When the configured folder is gone.
     * @throws NoAccessException       When upload is not allowed for
     *                                 this placement / viewer combination.
     *
     * @spec openspec/specs/files-widget/spec.md
     */
    public function uploadFiles(
        string $userId,
        array $config,
        ?string $currentSubPath,
        array $uploadedFiles
    ): array {
        if ((bool) ($config['allowUpload'] ?? false) === false) {
            throw new NoAccessException();
        }

        $root   = $this->resolveConfiguredFolder(userId: $userId, config: $config);
        $target = $this->descendInto(root: $root, subPath: ($currentSubPath ?? '/'));

        if ($target->isUpdateable() === false) {
            throw new NoAccessException();
        }

        $uploaded = [];
        $errors   = [];
        foreach ($uploadedFiles as $entry) {
            $name  = (string) $entry['name'];
            $tmp   = (string) $entry['tmp_name'];
            $error = (int) $entry['error'];

            if ($name === '' || $error !== UPLOAD_ERR_OK || $tmp === '') {
                $errors[] = [
                    'name'  => $name,
                    'error' => 'upload_failed',
                ];
                continue;
            }

            $safeName = $this->resolveAvailableName(folder: $target, desired: $name);
            try {
                $contents   = (string) @file_get_contents(filename: $tmp);
                $file       = $target->newFile(path: $safeName, content: $contents);
                $uploaded[] = $this->buildFileMetadata(node: $file);
            } catch (Throwable $e) {
                $errors[] = [
                    'name'  => $name,
                    'error' => 'storage_failed',
                ];
            }
        }//end foreach

        return [
            'uploaded' => $uploaded,
            'errors'   => $errors,
        ];
    }//end uploadFiles()

    /**
     * Delete a single file inside the configured folder (or a sub-path
     * of it). Dual-gated: placement `allowDelete` + viewer delete
     * permission on the file.
     *
     * @param string  $userId Viewing user's UID.
     * @param array   $config Placement config.
     * @param integer $fileId The file ID to delete.
     *
     * @return array{status: string, fileId: int}
     *
     * @throws FolderNotFoundException When the file is not found.
     * @throws NoAccessException       When delete is not allowed.
     *
     * @spec openspec/specs/files-widget/spec.md
     */
    public function deleteFile(string $userId, array $config, int $fileId): array
    {
        if ((bool) ($config['allowDelete'] ?? false) === false) {
            throw new NoAccessException();
        }

        $root = $this->resolveConfiguredFolder(userId: $userId, config: $config);

        // Find the file inside the configured folder by id. The id
        // search is scoped to the user folder so we do not have to
        // worry about cross-storage leaks.
        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
            $matches    = $userFolder->getById(id: $fileId);
        } catch (Throwable $e) {
            throw new FolderNotFoundException();
        }

        if (count($matches) === 0) {
            throw new FolderNotFoundException();
        }

        $node = $matches[0];

        // Defence-in-depth: the resolved node must live inside the
        // configured root folder. Otherwise a viewer with both
        // placements could pass an arbitrary fileId from a different
        // folder and have it deleted via this endpoint.
        if ($this->isDescendantOf(parent: $root, candidate: $node) === false) {
            throw new NoAccessException();
        }

        if ($node->isDeletable() === false) {
            throw new NoAccessException();
        }

        try {
            $node->delete();
        } catch (Throwable $e) {
            throw new NoAccessException();
        }

        return [
            'status' => 'success',
            'fileId' => $fileId,
        ];
    }//end deleteFile()

    /**
     * Build the normalised JSON-friendly file metadata blob the
     * frontend renderer consumes (REQ-FLS-003).
     *
     * @param Node $node A file or folder node.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/files-widget/spec.md
     */
    public function buildFileMetadata(Node $node): array
    {
        $isFolder = $node instanceof Folder;

        $thumbnailUrl = null;
        if ($isFolder === false && $node instanceof File) {
            $mime = $node->getMimetype();
            if (str_starts_with(haystack: $mime, needle: 'image/') === true) {
                $thumbnailUrl = $this->urlGenerator->linkToRouteAbsolute(
                    routeName: 'core.Preview.getPreviewByFileId',
                    arguments: [
                        'fileId' => $node->getId(),
                        'x'      => 64,
                        'y'      => 64,
                    ]
                );
            }
        }

        $permissions = $node->getPermissions();
        $canEdit     = ($permissions & Constants::PERMISSION_UPDATE) === Constants::PERMISSION_UPDATE;
        $canDelete   = ($permissions & Constants::PERMISSION_DELETE) === Constants::PERMISSION_DELETE;

        return [
            'fileId'       => (int) $node->getId(),
            'name'         => $node->getName(),
            'path'         => $node->getInternalPath(),
            'mimeType'     => $node->getMimetype(),
            'size'         => (int) $node->getSize(),
            'modifiedAt'   => $this->formatTimestamp(timestamp: $node->getMTime()),
            'isFolder'     => $isFolder,
            'thumbnailUrl' => $thumbnailUrl,
            'canEdit'      => $canEdit,
            'canDelete'    => $canDelete,
        ];
    }//end buildFileMetadata()

    /**
     * Resolve the placement's configured folder for the given user.
     *
     * Prefers `fileId` (stable across renames) and falls back to
     * `folderPath` (REQ-FLS-002 / design D5).
     *
     * @param string $userId Viewing user's UID.
     * @param array  $config Placement config.
     *
     * @return Folder
     *
     * @throws FolderNotFoundException When neither identifier resolves.
     * @throws NoAccessException       When the resolved folder is
     *                                 unreadable.
     *
     * @spec openspec/specs/files-widget/spec.md
     */
    public function resolveConfiguredFolder(string $userId, array $config): Folder
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
        } catch (Throwable $e) {
            throw new FolderNotFoundException();
        }

        $node = null;

        $fileId = $this->extractFileId(config: $config);
        if ($fileId !== null) {
            $matches = $userFolder->getById(id: $fileId);
            if (count($matches) > 0 && $matches[0] instanceof Folder) {
                $node = $matches[0];
            }
        }

        if ($node === null) {
            $folderPath = (string) ($config['folderPath'] ?? '');
            if ($folderPath !== '') {
                $normalised = ('/'.trim(string: $folderPath, characters: '/'));
                try {
                    if ($normalised === '/') {
                        $candidate = $userFolder;
                    } else {
                        $candidate = $userFolder->get(path: $normalised);
                    }

                    if ($candidate instanceof Folder) {
                        $node = $candidate;
                    }
                } catch (NotFoundException $e) {
                    // Will fall through to the throw below.
                }
            }
        }

        if ($node === null) {
            throw new FolderNotFoundException();
        }

        if ($node->isReadable() === false) {
            throw new NoAccessException();
        }

        return $node;
    }//end resolveConfiguredFolder()

    /**
     * Descend into a sub-path relative to a root folder. Each
     * intermediate node must be a readable folder; otherwise a
     * `FolderNotFoundException` is thrown (we do NOT distinguish
     * between "missing" and "unreadable subfolder" — the API
     * deliberately treats both the same so unauthorised paths are
     * indistinguishable from invented ones, REQ-FLS-004).
     *
     * @param Folder $root    The configured root folder.
     * @param string $subPath The sub-path requested by the client.
     *
     * @return Folder
     *
     * @throws FolderNotFoundException When the sub-path does not
     *                                 resolve to a readable folder.
     */
    private function descendInto(Folder $root, string $subPath): Folder
    {
        $clean = ('/'.trim(string: $subPath, characters: '/'));
        if ($clean === '/') {
            return $root;
        }

        // Reject path traversal attempts.
        if (str_contains(haystack: $clean, needle: '..') === true) {
            throw new FolderNotFoundException();
        }

        try {
            $node = $root->get(path: ltrim(string: $clean, characters: '/'));
        } catch (NotFoundException $e) {
            throw new FolderNotFoundException();
        } catch (NotPermittedException $e) {
            throw new FolderNotFoundException();
        }

        if (($node instanceof Folder) === false) {
            throw new FolderNotFoundException();
        }

        if ($node->isReadable() === false) {
            throw new FolderNotFoundException();
        }

        return $node;
    }//end descendInto()

    /**
     * Coerce the placement's `fileId` config value into a positive
     * integer or `null`.
     *
     * @param array $config Placement config.
     *
     * @return integer|null
     */
    private function extractFileId(array $config): ?int
    {
        if (isset($config['fileId']) === false) {
            return null;
        }

        $value = $config['fileId'];
        if (is_int($value) === true && $value > 0) {
            return $value;
        }

        if (is_string($value) === true && ctype_digit($value) === true) {
            $coerced = (int) $value;
            if ($coerced > 0) {
                return $coerced;
            }
        }

        return null;
    }//end extractFileId()

    /**
     * Normalise the MIME filter array — drops non-strings and empty
     * entries.
     *
     * @param mixed $filter Raw filter value from the placement config.
     *
     * @return list<string>
     */
    private function normaliseMimeFilter(mixed $filter): array
    {
        if (is_array($filter) === false) {
            return [];
        }

        $normalised = [];
        foreach ($filter as $entry) {
            if (is_string($entry) === false) {
                continue;
            }

            $token = strtolower(string: trim(string: $entry));
            if ($token === '') {
                continue;
            }

            $normalised[] = $token;
        }

        return $normalised;
    }//end normaliseMimeFilter()

    /**
     * Test a node's MIME type against the configured filter list.
     *
     * @param Node         $node   The candidate node.
     * @param list<string> $filter Normalised filter list.
     *
     * @return boolean
     */
    private function matchesMimeFilter(Node $node, array $filter): bool
    {
        if (count($filter) === 0) {
            return true;
        }

        $mime = strtolower(string: (string) $node->getMimetype());
        foreach ($filter as $pattern) {
            if (str_ends_with(haystack: $pattern, needle: '/*') === true) {
                $prefix = substr(string: $pattern, offset: 0, length: -1);
                if (str_starts_with(haystack: $mime, needle: $prefix) === true) {
                    return true;
                }
            } else if ($pattern === $mime) {
                return true;
            }
        }

        return false;
    }//end matchesMimeFilter()

    /**
     * Sort an item list according to the placement config.
     *
     * @param list<array<string,mixed>> $items          Items to sort.
     * @param string                    $sortBy         Sort field.
     * @param boolean                   $sortDescending Direction.
     *
     * @return list<array<string,mixed>>
     */
    private function sortItems(array $items, string $sortBy, bool $sortDescending): array
    {
        $allowed = ['name', 'modified', 'size', 'type'];
        if (in_array(needle: $sortBy, haystack: $allowed, strict: true) === false) {
            $sortBy = 'name';
        }

        usort(
            array: $items,
            callback: function (array $a, array $b) use ($sortBy): int {
                if ($sortBy === 'modified') {
                    return strcmp(string1: (string) ($a['modifiedAt'] ?? ''), string2: (string) ($b['modifiedAt'] ?? ''));
                }

                if ($sortBy === 'size') {
                    return ((int) ($a['size'] ?? 0)) <=> ((int) ($b['size'] ?? 0));
                }

                if ($sortBy === 'type') {
                    return strcmp(string1: (string) ($a['mimeType'] ?? ''), string2: (string) ($b['mimeType'] ?? ''));
                }

                return strnatcasecmp(string1: (string) ($a['name'] ?? ''), string2: (string) ($b['name'] ?? ''));
            }
        );

        if ($sortDescending === true) {
            $items = array_reverse(array: $items);
        }

        return array_values(array: $items);
    }//end sortItems()

    /**
     * Slice the sorted item list into a single page using an opaque
     * offset cursor (REQ-FLS-003 design D3).
     *
     * @param list<array<string,mixed>> $items  Items to paginate.
     * @param integer                   $limit  Page size.
     * @param string                    $cursor Numeric offset (or empty).
     *
     * @return array{items: list<array<string,mixed>>, nextCursor: ?string}
     */
    private function paginate(array $items, int $limit, string $cursor): array
    {
        $offset = 0;
        if ($cursor !== '' && ctype_digit($cursor) === true) {
            $offset = (int) $cursor;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $page = array_slice(array: $items, offset: $offset, length: $limit);
        $next = ($offset + count($page));
        if ($next < count($items)) {
            $nextCursor = (string) $next;
        } else {
            $nextCursor = null;
        }

        return [
            'items'      => array_values(array: $page),
            'nextCursor' => $nextCursor,
        ];
    }//end paginate()

    /**
     * Clamp a caller-supplied limit into the supported range.
     *
     * @param integer $limit Requested limit.
     *
     * @return integer
     */
    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        return $limit;
    }//end clampLimit()

    /**
     * Render a Unix timestamp as ISO 8601 in UTC (REQ-FLS-003).
     *
     * @param integer $timestamp The mtime.
     *
     * @return string
     */
    private function formatTimestamp(int $timestamp): string
    {
        return gmdate(format: 'Y-m-d\\TH:i:s\\Z', timestamp: $timestamp);
    }//end formatTimestamp()

    /**
     * Find a free filename inside the target folder by appending
     * `(1)`, `(2)` etc. when the desired name is taken (REQ-FLS-007).
     *
     * @param Folder $folder  Target folder.
     * @param string $desired Desired leaf name.
     *
     * @return string
     */
    private function resolveAvailableName(Folder $folder, string $desired): string
    {
        $sanitised = str_replace(search: ['/', '\\', "\0"], replace: '_', subject: $desired);
        if ($sanitised === '' || $sanitised === '.' || $sanitised === '..') {
            $sanitised = 'upload';
        }

        if ($folder->nodeExists(path: $sanitised) === false) {
            return $sanitised;
        }

        $extPos = strrpos(haystack: $sanitised, needle: '.');
        if ($extPos === false || $extPos === 0) {
            $base = $sanitised;
            $ext  = '';
        } else {
            $base = substr(string: $sanitised, offset: 0, length: $extPos);
            $ext  = substr(string: $sanitised, offset: $extPos);
        }

        for ($i = 1; $i <= 999; $i++) {
            $candidate = $base.' ('.$i.')'.$ext;
            if ($folder->nodeExists(path: $candidate) === false) {
                return $candidate;
            }
        }

        return $base.' ('.uniqid().')'.$ext;
    }//end resolveAvailableName()

    /**
     * Walk parents of `$candidate` and return true when `$parent` is
     * one of them (or the same node).
     *
     * @param Folder $parent    Putative ancestor.
     * @param Node   $candidate Node to test.
     *
     * @return boolean
     */
    private function isDescendantOf(Folder $parent, Node $candidate): bool
    {
        $parentId = $parent->getId();
        if ($candidate->getId() === $parentId) {
            return true;
        }

        $cursor = $candidate;
        for ($depth = 0; $depth < 32; $depth++) {
            try {
                $next = $cursor->getParent();
            } catch (Throwable $e) {
                return false;
            }

            if ($next->getId() === $parentId) {
                return true;
            }

            // Reached the user-folder root without hitting `$parent`.
            $nextPath = $next->getPath();
            if ($nextPath === '/' || $nextPath === '') {
                return false;
            }

            $cursor = $next;
        }

        return false;
    }//end isDescendantOf()
}//end class
