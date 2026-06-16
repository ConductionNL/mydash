<?php

/**
 * OrgNavigationService
 *
 * Implements the `navigation-editor-org` capability — admin-curated
 * organisation-wide navigation tree with per-language JSON storage
 * (REQ-ONAV-001..012).
 *
 * The tree is persisted as one JSON file per language under MyDash's
 * IAppData folder (`org-navigation/{lang}.json`). The file is a
 * wholesale-replace; per-node CRUD is intentionally NOT exposed (the
 * admin editor builds the full tree client-side and PUTs the result).
 *
 * Group filtering uses Nextcloud's native group memberships via the
 * single-source-of-truth resolver
 * {@see AdminTemplateService::getUserGroupIdsFor()} (REQ-TMPL-013).
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

use InvalidArgumentException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Throwable;

/**
 * Org-wide navigation tree service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   IAppData + group
 *                                                 resolver are both
 *                                                 unavoidable here.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Validation +
 *                                                 filtering + persistence
 *                                                 share one bounded class
 *                                                 by design.
 */
class OrgNavigationService
{
    /**
     * Name of the IAppData subfolder where org-nav JSON files live.
     *
     * @var string
     */
    public const FOLDER = 'org-navigation';

    /**
     * Maximum file size accepted on read or write (5 MB; REQ-ONAV-001).
     *
     * @var int
     */
    public const MAX_FILE_BYTES = (5 * 1024 * 1024);

    /**
     * Maximum tree depth (root counts as level 1; REQ-ONAV-001/003).
     *
     * @var int
     */
    public const MAX_DEPTH = 3;

    /**
     * Default language code used when the caller does not specify
     * `?lang=` (REQ-ONAV-001).
     *
     * @var string
     */
    public const DEFAULT_LANGUAGE = 'nl';

    /**
     * Languages supported in v1 of the capability (REQ-ONAV-001).
     *
     * @var array<int, string>
     */
    public const SUPPORTED_LANGUAGES = ['nl', 'en'];

    /**
     * URL schemes rejected outright by the validator
     * (REQ-ONAV-003, REQ-ONAV-011).
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_URL_SCHEMES = ['javascript:', 'data:', 'vbscript:'];

    /**
     * Constructor.
     *
     * @param IAppData             $appData         Nextcloud app-data
     *                                              accessor for the
     *                                              MyDash app.
     * @param AdminTemplateService $templateService Routing resolver — the
     *                                              only allowed wrapper
     *                                              around
     *                                              `IGroupManager::getUserGroupIds`
     *                                              (REQ-TMPL-013).
     */
    public function __construct(
        private readonly IAppData $appData,
        private readonly AdminTemplateService $templateService,
    ) {
    }//end __construct()

    /**
     * Read the persisted tree for the given language.
     *
     * Returns `[]` when the file does not yet exist; a corrupted JSON
     * payload also resolves to `[]` so a hand-edited file never bricks
     * the rail (REQ-ONAV-008 empty-state handling).
     *
     * @param string $language Language code (validated against the
     *                         supported set).
     *
     * @return array<int, array<string, mixed>> The persisted tree.
     *
     * @spec openspec/specs/navigation-editor-org/spec.md
     */
    public function getTree(string $language=self::DEFAULT_LANGUAGE): array
    {
        $lang = $this->normaliseLanguage(language: $language);

        try {
            $folder = $this->appData->getFolder(name: self::FOLDER);
        } catch (NotFoundException) {
            return [];
        }

        try {
            $file = $folder->getFile(name: $this->fileNameFor(language: $lang));
        } catch (NotFoundException) {
            return [];
        }

        return $this->decodeFile(file: $file);
    }//end getTree()

    /**
     * Persist a validated tree for the given language.
     *
     * Wholesale replacement (REQ-ONAV-003). Validates BEFORE touching
     * storage so a malformed payload never half-writes.
     *
     * @param array  $tree     The tree to persist.
     * @param string $language Language code.
     *
     * @return void
     *
     * @throws InvalidArgumentException When validation fails (the
     *                                  controller maps to HTTP 400).
     *
     * @spec openspec/specs/navigation-editor-org/spec.md
     */
    public function setTree(array $tree, string $language=self::DEFAULT_LANGUAGE): void
    {
        $lang = $this->normaliseLanguage(language: $language);

        $this->validateTree(tree: $tree);

        $folder = $this->getOrCreateFolder();
        $name   = $this->fileNameFor(language: $lang);

        $payload = json_encode(value: $tree, flags: (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($payload === false) {
            throw new InvalidArgumentException(message: 'Failed to encode tree to JSON');
        }

        if (strlen(string: $payload) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(
                message: 'Tree exceeds maximum file size of 5 MB'
            );
        }

        try {
            $file = $folder->getFile(name: $name);
            $file->putContent(data: $payload);
        } catch (NotFoundException) {
            $folder->newFile(name: $name, content: $payload);
        }
    }//end setTree()

    /**
     * Filter a tree down to nodes the given user is permitted to see.
     *
     * Visibility rule per REQ-ONAV-002:
     *   - `groupVisibility === null`            → visible to everyone
     *   - `groupVisibility === [g1, g2, ...]`   → visible iff user is in
     *                                            at least one listed group
     *   - hidden parent cascades to children    → children dropped wholesale
     *
     * @param array<int, array<string, mixed>> $tree   The tree to filter.
     * @param string                           $userId The viewing user.
     *
     * @return array<int, array<string, mixed>> The filtered tree.
     *
     * @spec openspec/specs/navigation-editor-org/spec.md
     */
    public function filterTreeByUserGroups(array $tree, string $userId): array
    {
        if ($tree === []) {
            return [];
        }

        $userGroups = $this->templateService->getUserGroupIdsFor(userId: $userId);

        return $this->filterRecursive(tree: $tree, userGroups: $userGroups);
    }//end filterTreeByUserGroups()

    /**
     * Validate a tree wholesale (REQ-ONAV-003).
     *
     * @param array $tree The tree to validate.
     *
     * @return void
     *
     * @throws InvalidArgumentException With a stable message on the
     *                                  first violation discovered.
     *
     * @spec openspec/specs/navigation-editor-org/spec.md
     */
    public function validateTree(array $tree): void
    {
        $seenIds = [];
        $this->validateRecursive(nodes: $tree, level: 1, seenIds: $seenIds);
    }//end validateTree()

    /**
     * Reject URLs that use a disallowed scheme.
     *
     * Returns the original URL when accepted; otherwise throws so the
     * caller can surface a single curated error message
     * (REQ-ONAV-003, REQ-ONAV-011).
     *
     * @param string $url The URL to test.
     *
     * @return string The accepted URL (unchanged).
     *
     * @throws InvalidArgumentException When the URL uses
     *                                  `javascript:`, `data:`, or
     *                                  `vbscript:` (any case).
     *
     * @spec openspec/specs/navigation-editor-org/spec.md
     */
    public function sanitiseUrl(string $url): string
    {
        $lower = strtolower(string: ltrim(string: $url));
        foreach (self::FORBIDDEN_URL_SCHEMES as $scheme) {
            if (str_starts_with(haystack: $lower, needle: $scheme) === true) {
                throw new InvalidArgumentException(
                    message: 'URL scheme is not allowed'
                );
            }
        }

        return $url;
    }//end sanitiseUrl()

    /**
     * Normalise a caller-supplied language code.
     *
     * Falls back to {@see self::DEFAULT_LANGUAGE} when the input is
     * empty or unsupported. Defensive — the controller already
     * validates, but keeping the fallback in the service means a
     * direct service consumer (e.g. CLI tooling) cannot crash here.
     *
     * @param string $language Caller language code.
     *
     * @return string Normalised language code (always one of
     *                {@see self::SUPPORTED_LANGUAGES}).
     */
    private function normaliseLanguage(string $language): string
    {
        $lower = strtolower(string: trim(string: $language));
        if (in_array(needle: $lower, haystack: self::SUPPORTED_LANGUAGES, strict: true) === false) {
            return self::DEFAULT_LANGUAGE;
        }

        return $lower;
    }//end normaliseLanguage()

    /**
     * Build the file name for a given language code.
     *
     * @param string $language Language code (already normalised).
     *
     * @return string The file name (no path).
     */
    private function fileNameFor(string $language): string
    {
        return ($language.'.json');
    }//end fileNameFor()

    /**
     * Read and decode the JSON file body, returning `[]` on any failure.
     *
     * @param ISimpleFile $file The file to read.
     *
     * @return array<int, array<string, mixed>> The decoded tree, or
     *                                          `[]` when the file is
     *                                          empty or corrupt.
     */
    private function decodeFile(ISimpleFile $file): array
    {
        try {
            $size = $file->getSize();
        } catch (Throwable) {
            return [];
        }

        if ($size > self::MAX_FILE_BYTES) {
            return [];
        }

        try {
            $contents = $file->getContent();
        } catch (Throwable) {
            return [];
        }

        if ($contents === '') {
            return [];
        }

        $decoded = json_decode(json: $contents, associative: true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end decodeFile()

    /**
     * Get the org-navigation folder, creating it if it does not exist.
     *
     * @return ISimpleFolder The folder.
     */
    private function getOrCreateFolder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder(name: self::FOLDER);
        } catch (NotFoundException) {
            return $this->appData->newFolder(name: self::FOLDER);
        }
    }//end getOrCreateFolder()

    /**
     * Recursive validator (REQ-ONAV-003).
     *
     * @param array               $nodes   The current level's nodes.
     * @param int                 $level   The depth of `$nodes` (root = 1).
     * @param array<string, true> $seenIds Map of UUIDs already used (by
     *                                     reference so siblings,
     *                                     children and grandchildren
     *                                     share the same uniqueness
     *                                     namespace).
     *
     * @return void
     *
     * @throws InvalidArgumentException On the first violation.
     */
    private function validateRecursive(array $nodes, int $level, array &$seenIds): void
    {
        if ($level > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                message: 'Tree depth cannot exceed 3 levels'
            );
        }

        foreach ($nodes as $node) {
            if (is_array($node) === false) {
                throw new InvalidArgumentException(
                    message: 'Each node must be an object'
                );
            }

            $this->validateNodeShape(node: $node, seenIds: $seenIds);

            $children = ($node['children'] ?? []);
            if (is_array($children) === false) {
                throw new InvalidArgumentException(
                    message: 'Node children must be an array'
                );
            }

            if ($children !== []) {
                $this->validateRecursive(
                    nodes: $children,
                    level: ($level + 1),
                    seenIds: $seenIds
                );
            }
        }//end foreach
    }//end validateRecursive()

    /**
     * Validate a single node's shape and per-field rules.
     *
     * @param array               $node    The node.
     * @param array<string, true> $seenIds Map of UUIDs already used
     *                                     (by reference; updated on
     *                                     success).
     *
     * @return void
     *
     * @throws InvalidArgumentException On the first violation.
     */
    private function validateNodeShape(array $node, array &$seenIds): void
    {
        $this->assertId(node: $node, seenIds: $seenIds);
        $this->assertLabel(node: $node);
        $this->assertUrl(node: $node);
        $this->assertGroupVisibility(node: $node);
        $this->assertOptionalScalars(node: $node);
    }//end validateNodeShape()

    /**
     * Validate the node id and stamp it into the seen-ids map.
     *
     * @param array               $node    The node.
     * @param array<string, true> $seenIds Map of UUIDs already used
     *                                     (by reference; updated on success).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the id is missing,
     *                                  not a UUID, or duplicated.
     */
    private function assertId(array $node, array &$seenIds): void
    {
        $id = ($node['id'] ?? null);
        if (is_string($id) === false || $this->isUuid(value: $id) === false) {
            throw new InvalidArgumentException(
                message: 'Node id must be a valid UUID'
            );
        }

        if (isset($seenIds[$id]) === true) {
            throw new InvalidArgumentException(
                message: 'duplicate node id: '.$id
            );
        }

        $seenIds[$id] = true;

    }//end assertId()

    /**
     * Validate the node label.
     *
     * @param array $node The node.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the label is missing or empty.
     */
    private function assertLabel(array $node): void
    {
        $label = ($node['label'] ?? null);
        if (is_string($label) === false || trim(string: $label) === '') {
            throw new InvalidArgumentException(
                message: 'label is required'
            );
        }

    }//end assertLabel()

    /**
     * Validate the optional URL field.
     *
     * @param array $node The node.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the URL has an invalid type
     *                                  or scheme.
     */
    private function assertUrl(array $node): void
    {
        $url = ($node['url'] ?? null);
        if ($url === null) {
            return;
        }

        if (is_string($url) === false) {
            throw new InvalidArgumentException(
                message: 'url must be a string when set'
            );
        }

        // Rejects javascript:, data:, vbscript:.
        $this->sanitiseUrl(url: $url);

    }//end assertUrl()

    /**
     * Validate the optional `groupVisibility` array.
     *
     * @param array $node The node.
     *
     * @return void
     *
     * @throws InvalidArgumentException On any shape violation.
     */
    private function assertGroupVisibility(array $node): void
    {
        $visibility = ($node['groupVisibility'] ?? null);
        if ($visibility === null) {
            return;
        }

        if (is_array($visibility) === false || $visibility === []) {
            throw new InvalidArgumentException(
                message: 'groupVisibility must be null or a non-empty array of strings'
            );
        }

        foreach ($visibility as $groupId) {
            if (is_string($groupId) === false || $groupId === '') {
                throw new InvalidArgumentException(
                    message: 'groupVisibility entries must be non-empty strings'
                );
            }
        }

    }//end assertGroupVisibility()

    /**
     * Validate the remaining optional scalar fields.
     *
     * @param array $node The node.
     *
     * @return void
     *
     * @throws InvalidArgumentException When a field carries the
     *                                  wrong type.
     */
    private function assertOptionalScalars(array $node): void
    {
        $newTab = ($node['openInNewTab'] ?? null);
        if ($newTab !== null && is_bool($newTab) === false) {
            throw new InvalidArgumentException(
                message: 'openInNewTab must be a boolean when set'
            );
        }

        $icon = ($node['icon'] ?? null);
        if ($icon !== null && is_string($icon) === false) {
            throw new InvalidArgumentException(
                message: 'icon must be a string when set'
            );
        }

    }//end assertOptionalScalars()

    /**
     * Recursive group-visibility filter (REQ-ONAV-002).
     *
     * @param array<int, array<string, mixed>> $tree       The current
     *                                                     subtree.
     * @param string[]                         $userGroups The viewing
     *                                                     user's groups.
     *
     * @return array<int, array<string, mixed>> The filtered subtree.
     */
    private function filterRecursive(array $tree, array $userGroups): array
    {
        $userGroupIndex = array_flip(array: $userGroups);
        $result         = [];

        foreach ($tree as $node) {
            if ($this->isVisible(node: $node, userGroupIndex: $userGroupIndex) === false) {
                // Hidden parent cascades — children dropped wholesale.
                continue;
            }

            $children = ($node['children'] ?? []);
            if (is_array($children) === true && $children !== []) {
                $node['children'] = $this->filterRecursive(
                    tree: $children,
                    userGroups: $userGroups
                );
            }

            $result[] = $node;
        }

        return $result;
    }//end filterRecursive()

    /**
     * True when the user is permitted to see the given node.
     *
     * @param array              $node           The node to test.
     * @param array<string, int> $userGroupIndex Flipped user groups
     *                                           (group id → array
     *                                           position) for O(1)
     *                                           membership lookup.
     *
     * @return bool True when visible.
     */
    private function isVisible(array $node, array $userGroupIndex): bool
    {
        $visibility = ($node['groupVisibility'] ?? null);
        if ($visibility === null) {
            return true;
        }

        if (is_array($visibility) === false || $visibility === []) {
            // Treat malformed visibility as "visible to all" so a
            // legacy/migrated record never disappears silently.
            return true;
        }

        foreach ($visibility as $groupId) {
            if (is_string($groupId) === false) {
                continue;
            }

            if (isset($userGroupIndex[$groupId]) === true) {
                return true;
            }
        }

        return false;
    }//end isVisible()

    /**
     * UUID v1..v5 detector — matches the canonical 8-4-4-4-12 hex form.
     *
     * @param string $value The candidate value.
     *
     * @return bool True when the input is a valid UUID.
     */
    private function isUuid(string $value): bool
    {
        return preg_match(
            pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            subject: $value
        ) === 1;
    }//end isUuid()
}//end class
