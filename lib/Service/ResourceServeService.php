<?php

/**
 * ResourceServeService
 *
 * Filesystem + formatting helpers for the read side of the
 * resource-uploads capability (REQ-RES-006..008). Lives next to
 * ResourceService so that ResourceServeController can stay under the
 * PHPMD CouplingBetweenObjects limit.
 *
 * Three responsibilities:
 *
 *  1. Resolve a leaf filename to an `ISimpleFile`, swallowing any
 *     "not found" failure into a null (callers turn it into HTTP 404).
 *  2. Load the `resources/` directory listing as a flat array of
 *     `ISimpleFile` entries — empty when the folder is absent.
 *  3. Map a filename extension to its `Content-Type` and format a
 *     Unix epoch as ISO-8601 UTC for the listing payload.
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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-side filesystem + formatting helpers for resource-uploads.
 */
class ResourceServeService
{
    /**
     * Map of file extension → Content-Type for the public serve route.
     *
     * Anything not in this map falls back to `application/octet-stream`
     * — see REQ-RES-006 for the canonical mapping.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPE_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    /**
     * Constructor.
     *
     * @param IAppData        $appData App-data accessor.
     * @param LoggerInterface $logger  PSR logger.
     */
    public function __construct(
        private readonly IAppData $appData,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a filename to an ISimpleFile, or null on miss.
     *
     * @param string $filename The leaf filename.
     *
     * @return ISimpleFile|null The file, or null if absent / unreadable.
     */
    public function findFile(string $filename): ?ISimpleFile
    {
        try {
            $folder = $this->appData->getFolder(name: ResourceService::FOLDER);
            return $folder->getFile(name: $filename);
        } catch (NotFoundException $e) {
            return null;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: 'Resource serve failed to open file',
                context: ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end findFile()

    /**
     * Load the resources directory listing as ISimpleFile entries.
     *
     * Returns an empty array when the folder does not yet exist —
     * matching REQ-RES-007's "never a 404" contract.
     *
     * @return array<int, ISimpleFile> The file entries.
     */
    public function listFiles(): array
    {
        try {
            $folder = $this->appData->getFolder(name: ResourceService::FOLDER);
        } catch (NotFoundException $e) {
            return [];
        } catch (Throwable $e) {
            $this->logger->warning(
                message: 'Resource list failed to open folder',
                context: ['exception' => $e->getMessage()]
            );
            return [];
        }

        $entries = [];
        foreach ($folder->getDirectoryListing() as $entry) {
            if (($entry instanceof ISimpleFile) === true) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }//end listFiles()

    /**
     * Pick the Content-Type for a filename via its extension.
     *
     * Falls back to `application/octet-stream` for unknown extensions.
     *
     * @param string $filename The leaf filename.
     *
     * @return string The MIME type to send.
     */
    public function contentTypeForFilename(string $filename): string
    {
        $position = strrpos(haystack: $filename, needle: '.');
        if ($position === false) {
            return 'application/octet-stream';
        }

        $extension = strtolower(string: substr(string: $filename, offset: ($position + 1)));
        return (self::CONTENT_TYPE_MAP[$extension] ?? 'application/octet-stream');
    }//end contentTypeForFilename()

    /**
     * Format a Unix epoch as an ISO-8601 UTC timestamp.
     *
     * @param int $epoch The Unix epoch (e.g. from ISimpleFile::getMTime()).
     *
     * @return string The ISO-8601 timestamp.
     */
    public function formatTimestamp(int $epoch): string
    {
        $dateTime = (new DateTimeImmutable(datetime: '@'.$epoch))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'));

        return $dateTime->format(format: DateTimeInterface::ATOM);
    }//end formatTimestamp()
}//end class
