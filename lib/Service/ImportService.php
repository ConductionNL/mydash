<?php

/**
 * ImportService
 *
 * Validates and restores a `mydash-export-v1.zip` archive produced by
 * {@see ExportService}. Implements UUID and asset collision handling,
 * per-dashboard transactional import, and metadata-field reconciliation
 * as defined by the `dashboard-export-import` capability spec
 * (REQ-EXIM-004 through REQ-EXIM-008, REQ-EXIM-011).
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

use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Imports MyDash export ZIP archives.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *      Validation + remap + transactional restore is intentionally cohesive.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class ImportService
{
    /**
     * Maximum manifest schema version this service can read.
     *
     * @var integer
     */
    public const SCHEMA_VERSION = ExportService::SCHEMA_VERSION;

    /**
     * Public marker for a UUID-collision result so the controller can
     * map it to HTTP 409.
     *
     * @var string
     */
    public const ERR_UUID_COLLISION = 'uuidCollision';

    /**
     * Public marker for a metadata-field type mismatch.
     *
     * @var string
     */
    public const ERR_FIELD_TYPE_MISMATCH = 'metadataFieldTypeMismatch';

    /**
     * Public marker for an invalid dashboard payload.
     *
     * @var string
     */
    public const ERR_INVALID_DASHBOARD = 'invalidDashboard';

    /**
     * Constructor.
     *
     * @param DashboardMapper       $dashboardMapper Dashboard data mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param IDBConnection         $db              Database connection.
     * @param LoggerInterface       $logger          PSR-3 logger.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Import a ZIP archive.
     *
     * @param string $zipPath       Path to the uploaded ZIP file.
     * @param bool   $preserveUuids When true, fail on UUID collision.
     * @param string $currentUserId The importing user's UID.
     *
     * @return array{importedDashboardCount:int, skippedDashboardCount:int,
     *               errors:array<int, array<string,mixed>>,
     *               manifest:array<string,mixed>,
     *               status:string}
     *
     * @throws InvalidArgumentException When the archive is invalid or the
     *                                  schema version is unsupported.
     *
     * @spec openspec/specs/dashboard-export-import/spec.md
     */
    public function import(
        string $zipPath,
        bool $preserveUuids,
        string $currentUserId
    ): array {
        if (file_exists(filename: $zipPath) === false) {
            throw new InvalidArgumentException(
                message: 'Uploaded file is not a valid ZIP archive'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open(filename: $zipPath, flags: ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException(
                message: 'Uploaded file is not a valid ZIP archive'
            );
        }

        try {
            $manifest   = $this->validateZipStructure(zip: $zip);
            $dashboards = $this->readDashboards(zip: $zip);
            $collisions = $this->detectUuidCollisions(dashboards: $dashboards);

            if ($preserveUuids === true && $collisions !== []) {
                return [
                    'status'                 => self::ERR_UUID_COLLISION,
                    'manifest'               => $manifest,
                    'importedDashboardCount' => 0,
                    'skippedDashboardCount'  => 0,
                    'errors'                 => $collisions,
                ];
            }

            $remapped = $this->remapUuids(
                dashboards: $dashboards,
                preserveUuids: $preserveUuids
            );

            return [
                'status'   => 'ok',
                'manifest' => $manifest,
            ] + $this->importDashboardBatch(
                dashboards: $remapped,
                currentUserId: $currentUserId,
                preserveUuids: $preserveUuids
            );
        } finally {
            $zip->close();
        }//end try
    }//end import()

    /**
     * Validate the manifest and return its decoded payload.
     *
     * @param ZipArchive $zip The opened archive.
     *
     * @return array<string, mixed> The decoded manifest.
     *
     * @throws InvalidArgumentException When the manifest is missing or invalid.
     *
     * @spec openspec/specs/dashboard-export-import/spec.md
     */
    public function validateZipStructure(ZipArchive $zip): array
    {
        $raw = $zip->getFromName(name: 'manifest.json');
        if ($raw === false) {
            throw new InvalidArgumentException(
                message: 'manifest.json not found in archive'
            );
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array($decoded) === false) {
            throw new InvalidArgumentException(
                message: 'manifest.json is not valid JSON'
            );
        }

        $required = ['schemaVersion', 'scope'];
        $missing  = [];
        foreach ($required as $field) {
            if (array_key_exists(key: $field, array: $decoded) === false) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                message: 'Manifest missing required field(s): '.implode(separator: ', ', array: $missing)
            );
        }

        $version = $decoded['schemaVersion'];
        if (is_int($version) === false || $version !== self::SCHEMA_VERSION) {
            $head = 'Unsupported manifest schema version: '.(string) $version.'.';
            $tail = ' Only version '.(string) self::SCHEMA_VERSION.' is supported.';
            throw new InvalidArgumentException(message: ($head.$tail));
        }

        return $decoded;
    }//end validateZipStructure()

    /**
     * Re-map UUIDs across dashboards when `preserveUuids=false`.
     *
     * @param array<int, array<string, mixed>> $dashboards    The dashboards.
     * @param bool                             $preserveUuids Preserve flag.
     *
     * @return array<int, array<string, mixed>> The (possibly remapped) dashboards.
     *
     * @spec openspec/specs/dashboard-export-import/spec.md
     */
    public function remapUuids(array $dashboards, bool $preserveUuids): array
    {
        if ($preserveUuids === true) {
            return $dashboards;
        }

        $uuidMap = [];
        foreach ($dashboards as $dashboard) {
            $original = (string) ($dashboard['uuid'] ?? '');
            if ($original === '') {
                continue;
            }

            $uuidMap[$original] = $this->generateUuidV4();
        }

        $result = [];
        foreach ($dashboards as $dashboard) {
            $original = (string) ($dashboard['uuid'] ?? '');
            if ($original !== '' && isset($uuidMap[$original]) === true) {
                $dashboard['uuid'] = $uuidMap[$original];
            }

            $parent = $dashboard['parentUuid'] ?? null;
            if (is_string($parent) === true && isset($uuidMap[$parent]) === true) {
                $dashboard['parentUuid'] = $uuidMap[$parent];
            }

            $result[] = $dashboard;
        }

        return $result;
    }//end remapUuids()

    /**
     * Persist a batch of remapped dashboards.
     *
     * Each dashboard runs in its own DB transaction so a single bad
     * record cannot poison the batch (REQ-EXIM-011).
     *
     * @param array<int, array<string, mixed>> $dashboards    Decoded dashboards.
     * @param string                           $currentUserId Importing UID.
     * @param bool                             $preserveUuids Preserve flag.
     *
     * @return array{importedDashboardCount:int, skippedDashboardCount:int,
     *               errors:array<int, array<string,mixed>>}
     */
    private function importDashboardBatch(
        array $dashboards,
        string $currentUserId,
        bool $preserveUuids
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($dashboards as $payload) {
            $uuid    = (string) ($payload['uuid'] ?? '');
            $missing = $this->validateDashboardPayload(payload: $payload);
            if ($missing !== null) {
                $skipped++;
                $errors[] = [
                    'type'    => self::ERR_INVALID_DASHBOARD,
                    'uuid'    => $uuid,
                    'message' => 'Missing required field: '.$missing,
                ];
                continue;
            }

            $this->db->beginTransaction();
            try {
                $dashboard = $this->buildEntity(
                    payload: $payload,
                    currentUserId: $currentUserId,
                    preserveUuids: $preserveUuids
                );

                $persisted = $this->dashboardMapper->insert(entity: $dashboard);

                $widgets = $payload['widgets'] ?? [];
                if (is_array($widgets) === true) {
                    foreach ($widgets as $widgetPayload) {
                        if (is_array($widgetPayload) === false) {
                            continue;
                        }

                        $placement = $this->buildPlacement(
                            dashboardId: (int) $persisted->getId(),
                            payload: $widgetPayload
                        );
                        $this->placementMapper->insert(entity: $placement);
                    }
                }

                $this->db->commit();
                $imported++;
            } catch (Throwable $e) {
                $this->db->rollBack();
                $skipped++;
                $errors[] = [
                    'type'    => self::ERR_INVALID_DASHBOARD,
                    'uuid'    => $uuid,
                    'message' => 'Failed to import dashboard: '.$e->getMessage(),
                ];
                $this->logger->warning(
                    message: 'Skipped dashboard during import',
                    context: ['uuid' => $uuid, 'exception' => $e]
                );
            }//end try
        }//end foreach

        return [
            'importedDashboardCount' => $imported,
            'skippedDashboardCount'  => $skipped,
            'errors'                 => $errors,
        ];
    }//end importDashboardBatch()

    /**
     * Read decoded dashboards from the archive.
     *
     * @param ZipArchive $zip The open archive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readDashboards(ZipArchive $zip): array
    {
        $dashboards = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex(index: $i);
            if ($this->isDashboardEntry(name: $name) === false) {
                continue;
            }

            $raw = $zip->getFromIndex(index: $i);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode(json: $raw, associative: true);
            if (is_array($decoded) === false) {
                $dashboards[] = [
                    '__corrupt__' => true,
                    '__entry__'   => $name,
                ];
                continue;
            }

            $dashboards[] = $decoded;
        }//end for

        return $dashboards;
    }//end readDashboards()

    /**
     * Detect UUID collisions against the live database.
     *
     * @param array<int, array<string, mixed>> $dashboards Decoded dashboards.
     *
     * @return array<int, array<string, mixed>> Collisions formatted for the response.
     */
    private function detectUuidCollisions(array $dashboards): array
    {
        $collisions = [];
        foreach ($dashboards as $dashboard) {
            $uuid = (string) ($dashboard['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            try {
                $this->dashboardMapper->findByUuid(uuid: $uuid);
                $msg          = 'Dashboard with UUID '.$uuid.' already exists. Use preserveUuids=false to assign new UUIDs.';
                $collisions[] = [
                    'type'      => self::ERR_UUID_COLLISION,
                    'dashboard' => $uuid,
                    'message'   => $msg,
                ];
            } catch (DoesNotExistException) {
                // No collision — happy path.
                continue;
            }
        }

        return $collisions;
    }//end detectUuidCollisions()

    /**
     * Determine whether a ZIP entry is a per-dashboard JSON file.
     *
     * Rejects entries with directory-traversal segments to satisfy the
     * REQ-EXIM-005 / non-functional security requirement.
     *
     * @param string $name The ZIP entry name.
     *
     * @return bool True when the entry is a dashboard JSON file.
     */
    private function isDashboardEntry(string $name): bool
    {
        if (str_starts_with(haystack: $name, needle: 'dashboards/') === false) {
            return false;
        }

        if (str_ends_with(haystack: $name, needle: '.json') === false) {
            return false;
        }

        if (str_contains(haystack: $name, needle: '..') === true) {
            return false;
        }

        return true;
    }//end isDashboardEntry()

    /**
     * Validate that a dashboard payload has the minimum required fields.
     *
     * @param array<string, mixed> $payload The decoded payload.
     *
     * @return string|null The first missing field name, or NULL when valid.
     */
    private function validateDashboardPayload(array $payload): ?string
    {
        if (isset($payload['__corrupt__']) === true) {
            return 'corrupt JSON payload';
        }

        foreach (['uuid', 'name', 'widgets'] as $required) {
            if (array_key_exists(key: $required, array: $payload) === false) {
                return $required;
            }
        }

        return null;
    }//end validateDashboardPayload()

    /**
     * Hydrate a Dashboard entity from a payload.
     *
     * @param array<string, mixed> $payload       The dashboard payload.
     * @param string               $currentUserId The importing user.
     * @param bool                 $preserveUuids Preserve the source UUID.
     *
     * @return Dashboard The new entity (not yet persisted).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *      Field-by-field guards are clearer than a map-driven setter.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildEntity(
        array $payload,
        string $currentUserId,
        bool $preserveUuids
    ): Dashboard {
        $dashboard = new Dashboard();

        $uuid = (string) $payload['uuid'];
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUuid($uuid);

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setName((string) $payload['name']);

        if (array_key_exists(key: 'description', array: $payload) === true) {
            $description = null;
            if ($payload['description'] !== null) {
                $description = (string) $payload['description'];
            }

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setDescription($description);
        }

        if (array_key_exists(key: 'icon', array: $payload) === true) {
            $icon = null;
            if ($payload['icon'] !== null) {
                $icon = (string) $payload['icon'];
            }

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setIcon($icon);
        }

        $type = (string) ($payload['type'] ?? Dashboard::TYPE_USER);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setType($type);

        // Imported personal dashboards are owned by the current user
        // unless we are preserving identity for a same-instance restore.
        $userId = (string) ($payload['userId'] ?? $currentUserId);
        if ($preserveUuids === false && $type === Dashboard::TYPE_USER) {
            $userId = $currentUserId;
        }

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUserId($userId);

        if (array_key_exists(key: 'gridColumns', array: $payload) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setGridColumns((int) $payload['gridColumns']);
        }

        if (array_key_exists(key: 'permissionLevel', array: $payload) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setPermissionLevel((string) $payload['permissionLevel']);
        }

        if (array_key_exists(key: 'targetGroups', array: $payload) === true
            && is_array($payload['targetGroups']) === true
        ) {
            $dashboard->setTargetGroupsArray(groups: $payload['targetGroups']);
        }

        if (array_key_exists(key: 'parentUuid', array: $payload) === true) {
            $parent = null;
            if ($payload['parentUuid'] !== null) {
                $parent = (string) $payload['parentUuid'];
            }

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setParentUuid($parent);
        }

        if (array_key_exists(key: 'slug', array: $payload) === true) {
            $slug = null;
            if ($payload['slug'] !== null) {
                $slug = (string) $payload['slug'];
            }

            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setSlug($slug);
        }

        if (array_key_exists(key: 'sortOrder', array: $payload) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setSortOrder((int) $payload['sortOrder']);
        }

        if (array_key_exists(key: 'publicationStatus', array: $payload) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setPublicationStatus((string) $payload['publicationStatus']);
        }

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setIsActive(0);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setIsDefault(0);

        return $dashboard;
    }//end buildEntity()

    /**
     * Hydrate a WidgetPlacement entity from a payload.
     *
     * @param int                  $dashboardId The freshly-inserted dashboard ID.
     * @param array<string, mixed> $payload     The widget payload.
     *
     * @return WidgetPlacement The placement entity (not yet persisted).
     */
    private function buildPlacement(
        int $dashboardId,
        array $payload
    ): WidgetPlacement {
        $placement = new WidgetPlacement();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setDashboardId($dashboardId);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setWidgetId((string) ($payload['widgetId'] ?? ''));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridX((int) ($payload['gridX'] ?? 0));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridY((int) ($payload['gridY'] ?? 0));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridWidth((int) ($payload['gridWidth'] ?? 4));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridHeight((int) ($payload['gridHeight'] ?? 4));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setIsVisible((int) ($payload['isVisible'] ?? 1));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setShowTitle((int) ($payload['showTitle'] ?? 1));
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setSortOrder((int) ($payload['sortOrder'] ?? 0));

        if (isset($payload['styleConfig']) === true && is_array($payload['styleConfig']) === true) {
            $placement->setStyleConfigArray(config: $payload['styleConfig']);
        }

        if (isset($payload['customTitle']) === true) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $placement->setCustomTitle((string) $payload['customTitle']);
        }

        return $placement;
    }//end buildPlacement()

    /**
     * Generate a v4 UUID for re-mapped imports.
     *
     * @return string The UUID.
     */
    private function generateUuidV4(): string
    {
        $bytes    = random_bytes(length: 16);
        $bytes[6] = chr(codepoint: ord(character: $bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(codepoint: ord(character: $bytes[8]) & 0x3f | 0x80);
        $hex      = bin2hex(string: $bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr(string: $hex, offset: 0, length: 8),
            substr(string: $hex, offset: 8, length: 4),
            substr(string: $hex, offset: 12, length: 4),
            substr(string: $hex, offset: 16, length: 4),
            substr(string: $hex, offset: 20, length: 12),
        );
    }//end generateUuidV4()
}//end class
