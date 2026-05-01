<?php

/**
 * ResourceService
 *
 * Implements the resource-uploads capability — admin-only base64
 * upload pipeline for branding assets (icons, widget images). The
 * service parses a `data:image/<type>;base64,...` data URL, enforces
 * a 5 MB hard cap on decoded bytes BEFORE invoking the image library,
 * cross-checks the declared raster type against the detected MIME,
 * and persists the bytes via `IAppData::getFolder('resources')` with
 * a high-entropy `resource_<uniqid>.<ext>` filename.
 *
 * For SVG uploads the bytes are first passed through `SvgSanitiser`
 * (REQ-RES-009..013); the sanitised bytes are what get persisted, and
 * the 5 MB size cap is measured AFTER sanitisation. A null sanitiser
 * return is surfaced as HTTP 400 `invalid_svg`.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Exception\FileTooLargeException;
use OCA\MyDash\Exception\InvalidDataUrlException;
use OCA\MyDash\Exception\InvalidImageFormatException;
use OCA\MyDash\Exception\InvalidSvgException;
use OCA\MyDash\Exception\StorageFailureException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Throwable;

/**
 * Admin-only base64 upload pipeline for branding assets.
 */
class ResourceService
{
    /**
     * Maximum decoded payload size (5 MB).
     *
     * @var int
     */
    public const MAX_BYTES = (5 * 1024 * 1024);

    /**
     * Name of the IAppData subfolder where resources are stored.
     *
     * @var string
     */
    public const FOLDER = 'resources';

    /**
     * Allowed declared image types (lowercase, no dots).
     *
     * @var array<int,string>
     */
    private const ALLOWED_TYPES = [
        'jpeg',
        'jpg',
        'png',
        'gif',
        'svg',
        'webp',
    ];

    /**
     * Constructor.
     *
     * @param IAppData           $appData       Nextcloud app-data
     *                                          interface for this app.
     * @param ImageMimeValidator $mimeValidator Raster MIME cross-checker.
     * @param SvgSanitiser       $svgSanitiser  DOM whitelist SVG sanitiser.
     */
    public function __construct(
        private readonly IAppData $appData,
        private readonly ImageMimeValidator $mimeValidator,
        private readonly SvgSanitiser $svgSanitiser,
    ) {
    }//end __construct()

    /**
     * Upload a base64 data URL and return the persisted resource info.
     *
     * Parses the data URL, enforces the 5 MB cap on decoded bytes
     * BEFORE invoking the image library, validates the declared type,
     * cross-checks the raster MIME, and persists to app data.
     *
     * @param string $base64DataUrl A `data:image/<type>;base64,<...>`
     *                              string.
     *
     * @return array{url: string, name: string, size: int} The created
     *                                                     resource.
     *
     * @throws InvalidDataUrlException     When the prefix is missing
     *                                     or unparseable.
     * @throws InvalidImageFormatException When the declared type is
     *                                     not in the allowed list.
     * @throws InvalidSvgException         When an SVG payload fails
     *                                     to parse or is fully stripped.
     * @throws FileTooLargeException       When decoded bytes (or the
     *                                     SANITISED bytes for SVG)
     *                                     exceed 5 MB.
     * @throws StorageFailureException     When writing to IAppData
     *                                     fails.
     */
    public function upload(string $base64DataUrl): array
    {
        $parsed       = $this->parseDataUrl(input: $base64DataUrl);
        $declaredType = $parsed['type'];
        $bytes        = $parsed['bytes'];

        // SVG branch: sanitise BEFORE the size check so the 5 MB cap
        // is measured against the persisted (sanitised) byte count
        // (REQ-RES-009). Sanitiser returns null on parse failure or
        // an empty document — surface as HTTP 400 invalid_svg.
        if ($declaredType === 'svg') {
            $sanitised = $this->svgSanitiser->sanitize(bytes: $bytes);
            if ($sanitised === null) {
                throw new InvalidSvgException();
            }

            $bytes = $sanitised;
        }

        // Enforce the 5 MB cap BEFORE invoking the image library.
        if (strlen(string: $bytes) > self::MAX_BYTES) {
            throw new FileTooLargeException();
        }

        // Cross-check raster MIME (SVG short-circuits inside validate).
        $this->mimeValidator->validate(
            declaredType: $declaredType,
            bytes: $bytes
        );

        $extension = $this->normaliseExtension(declaredType: $declaredType);
        $filename  = ('resource_'.uniqid(prefix: '', more_entropy: true).'.'.$extension);

        $this->persist(filename: $filename, bytes: $bytes);

        // Build the public URL directly — the serving endpoint is
        // delivered by the sibling `resource-serving` change. The spec
        // mandates the relative path form `/apps/mydash/resource/<name>`,
        // so we don't pass it through linkToRoute or getAbsoluteURL.
        $url = ('/apps/'.Application::APP_ID.'/resource/'.$filename);

        return [
            'url'  => $url,
            'name' => $filename,
            'size' => strlen(string: $bytes),
        ];
    }//end upload()

    /**
     * Parse a `data:image/<type>;base64,<payload>` string.
     *
     * The declared type is normalised to lowercase. Anything outside
     * the allowed list is rejected.
     *
     * @param string $input The raw input string.
     *
     * @return array{type: string, bytes: string} The normalised
     *                                            declared type and the
     *                                            decoded bytes.
     *
     * @throws InvalidDataUrlException     When the prefix is missing.
     * @throws InvalidImageFormatException When the declared type is
     *                                     not allowed.
     */
    private function parseDataUrl(string $input): array
    {
        $matches = [];
        // Match "data:image/<type>;base64,<payload>" — type is alpha
        // plus optional "+xml" suffix (handles `image/svg+xml`).
        if (preg_match(
            pattern: '#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#s',
            subject: $input,
            matches: $matches
        ) !== 1
        ) {
            throw new InvalidDataUrlException();
        }

        $declaredRaw = strtolower(string: $matches[1]);
        $payload     = $matches[2];

        $declaredType = $this->normaliseDeclaredType(raw: $declaredRaw);
        if (in_array(
            needle: $declaredType,
            haystack: self::ALLOWED_TYPES,
            strict: true
        ) === false
        ) {
            throw new InvalidImageFormatException();
        }

        $bytes = base64_decode(string: $payload, strict: true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidDataUrlException(
                message: 'Body must contain valid base64 data'
            );
        }

        return [
            'type'  => $declaredType,
            'bytes' => $bytes,
        ];
    }//end parseDataUrl()

    /**
     * Normalise a raw declared type string from the data URL prefix.
     *
     * `image/svg+xml` → `svg`; otherwise the lowercased subtype is
     * returned untouched (callers vet against ALLOWED_TYPES).
     *
     * @param string $raw The raw lowercased subtype.
     *
     * @return string The normalised declared type.
     */
    private function normaliseDeclaredType(string $raw): string
    {
        if ($raw === 'svg+xml') {
            return 'svg';
        }

        return $raw;
    }//end normaliseDeclaredType()

    /**
     * Map a normalised declared type to its file extension.
     *
     * Currently the extension equals the declared type for every
     * allowed value.
     *
     * @param string $declaredType The normalised lowercase type.
     *
     * @return string The file extension (without the dot).
     */
    private function normaliseExtension(string $declaredType): string
    {
        return $declaredType;
    }//end normaliseExtension()

    /**
     * Persist validated bytes via IAppData.
     *
     * Auto-creates the `resources/` folder on first use. Wraps any
     * IAppData failure into a typed StorageFailureException so that
     * raw underlying messages never leak to clients.
     *
     * @param string $filename The target filename inside the folder.
     * @param string $bytes    The validated payload bytes.
     *
     * @return void
     *
     * @throws StorageFailureException When the underlying storage
     *                                 layer rejects the write.
     */
    private function persist(string $filename, string $bytes): void
    {
        try {
            $folder = $this->getOrCreateFolder();
            $folder->newFile(name: $filename, content: $bytes);
        } catch (Throwable $e) {
            throw new StorageFailureException();
        }
    }//end persist()

    /**
     * Get the resources folder, creating it if it doesn't exist.
     *
     * @return \OCP\Files\SimpleFS\ISimpleFolder The resources folder.
     */
    private function getOrCreateFolder(): \OCP\Files\SimpleFS\ISimpleFolder
    {
        try {
            return $this->appData->getFolder(name: self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(name: self::FOLDER);
        }
    }//end getOrCreateFolder()
}//end class
