<?php

/**
 * ExportService
 *
 * Builds a versioned ZIP archive containing one or more dashboards,
 * their widget placements, referenced metadata field definitions, and
 * any associated assets (icons, widget uploads). The container format
 * is `mydash-export-v1.zip` — see `dashboard-export-import` capability
 * spec REQ-EXIM-001..003 / REQ-EXIM-009 / REQ-EXIM-011.
 *
 * The downstream `confluence-html-import` capability consumes the same
 * ZIP shape, so the manifest schema (`schemaVersion: 1`) is treated as
 * stable.
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
use DateTimeZone;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds versioned MyDash export ZIP archives.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *      Mirrors injected dependencies.
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 *      Best-effort temp-file cleanup tolerates concurrent removal.
 */
class ExportService
{
    /**
     * Manifest schema version emitted by this exporter (REQ-EXIM-001).
     *
     * Bump the constant alongside a documented migration path when the
     * archive format changes; importers refuse anything else.
     *
     * @var integer
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Asset directory inside the archive for dashboard icons.
     *
     * @var string
     */
    public const ASSET_ICONS_DIR = 'assets/icons';

    /**
     * Asset directory inside the archive for widget uploads.
     *
     * @var string
     */
    public const ASSET_WIDGETS_DIR = 'assets/widgets';

    /**
     * Constructor.
     *
     * @param DashboardMapper       $dashboardMapper Dashboard data mapper.
     * @param WidgetPlacementMapper $placementMapper Widget placement mapper.
     * @param IGroupManager         $groupManager    Nextcloud group manager.
     * @param LoggerInterface       $logger          PSR-3 logger.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Export a single dashboard as a streaming ZIP response.
     *
     * Caller MUST already have validated admin / owner permissions.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $currentUserId The current user ID (for manifest).
     *
     * @return StreamResponse The streaming ZIP response.
     *
     * @throws DoesNotExistException When the dashboard cannot be found.
     */
    public function exportDashboard(
        string $dashboardUuid,
        string $currentUserId
    ): StreamResponse {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);

        $tempPath = $this->buildArchive(
            dashboards: [$dashboard],
            scope: 'dashboard',
            currentUserId: $currentUserId
        );

        return $this->streamZipResponse(
            zipPath: $tempPath,
            filename: 'mydash-export-'.$dashboardUuid.'.zip'
        );
    }//end exportDashboard()

    /**
     * Export every dashboard in the instance as a streaming ZIP.
     *
     * Caller MUST already have validated Nextcloud-admin permission.
     *
     * @param string $currentUserId The current user ID (for manifest).
     *
     * @return StreamResponse The streaming ZIP response.
     */
    public function exportSite(string $currentUserId): StreamResponse
    {
        $dashboards = $this->collectAllDashboards();

        $tempPath = $this->buildArchive(
            dashboards: $dashboards,
            scope: 'site',
            currentUserId: $currentUserId
        );

        return $this->streamZipResponse(
            zipPath: $tempPath,
            filename: 'mydash-export-site-'.gmdate(format: 'Ymd-His').'.zip'
        );
    }//end exportSite()

    /**
     * Build a manifest array for the archive root (REQ-EXIM-001).
     *
     * @param string $scope          Either 'dashboard' or 'site'.
     * @param int    $dashboardCount Number of dashboards in the archive.
     * @param string $currentUserId  The exporting user's UID.
     *
     * @return array<string, mixed> The manifest data.
     */
    public function buildManifest(
        string $scope,
        int $dashboardCount,
        string $currentUserId
    ): array {
        $now = new DateTimeImmutable(
            datetime: 'now',
            timezone: new DateTimeZone(timezone: 'UTC')
        );

        return [
            'schemaVersion'  => self::SCHEMA_VERSION,
            'exportedAt'     => $now->format(format: 'Y-m-d\TH:i:s\Z'),
            'exportedBy'     => $currentUserId,
            'mydashVersion'  => Application::APP_ID.'/v1',
            'scope'          => $scope,
            'dashboardCount' => $dashboardCount,
            'includedAssets' => ['icons', 'widgetUploads', 'metadataFields'],
        ];
    }//end buildManifest()

    /**
     * Serialize a dashboard with its widget placements (REQ-EXIM-002).
     *
     * @param Dashboard $dashboard The dashboard entity.
     *
     * @return array<string, mixed> The serialized dashboard payload.
     */
    public function serializeDashboard(Dashboard $dashboard): array
    {
        $widgets = [];
        $dashId  = $dashboard->getId();
        if ($dashId !== null) {
            $placements = $this->placementMapper->findByDashboardId(
                dashboardId: $dashId
            );
            foreach ($placements as $placement) {
                $widgets[] = $this->serializePlacement(placement: $placement);
            }
        }

        $payload = $dashboard->jsonSerialize();
        // Strip database primary key — the importer assigns a fresh row.
        unset($payload['id']);
        $payload['widgets'] = $widgets;
        $payload['metadataFieldAssignments'] = [];

        return $payload;
    }//end serializeDashboard()

    /**
     * Build the temp ZIP file containing the manifest and dashboards.
     *
     * @param Dashboard[] $dashboards    The dashboards to include.
     * @param string      $scope         Scope label for the manifest.
     * @param string      $currentUserId The exporting user's UID.
     *
     * @return string Path to the temporary ZIP file.
     */
    private function buildArchive(
        array $dashboards,
        string $scope,
        string $currentUserId
    ): string {
        $tempPath = tempnam(directory: sys_get_temp_dir(), prefix: 'mydash-export-');
        if ($tempPath === false) {
            throw new RuntimeException(
                message: 'Could not allocate temporary file for export.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open(filename: $tempPath, flags: ZipArchive::OVERWRITE) !== true) {
            unlink(filename: $tempPath);
            throw new RuntimeException(message: 'Could not open ZIP archive for writing.');
        }

        $manifest = $this->buildManifest(
            scope: $scope,
            dashboardCount: count($dashboards),
            currentUserId: $currentUserId
        );

        $zip->addFromString(
            name: 'manifest.json',
            content: $this->encodeJson(payload: $manifest)
        );

        $assetsAdded = false;
        foreach ($dashboards as $dashboard) {
            $payload = $this->serializeDashboard(dashboard: $dashboard);
            $uuid    = (string) $dashboard->getUuid();
            if ($uuid === '') {
                continue;
            }

            $zip->addFromString(
                name: 'dashboards/'.$uuid.'.json',
                content: $this->encodeJson(payload: $payload)
            );

            $assetsAdded = $this->addPlaceholderAssets(
                zip: $zip,
                dashboardUuid: $uuid
            ) || $assetsAdded;
        }

        // The metadata-fields.json is always emitted (empty list when
        // no metadata-fields capability has been registered yet) so the
        // downstream `confluence-html-import` consumer never has to
        // handle a missing file.
        $zip->addFromString(
            name: 'metadata-fields.json',
            content: $this->encodeJson(payload: [])
        );

        if ($assetsAdded === false) {
            // Reserve the asset directories so the format always shows
            // them — keeps tooling parsers happy.
            $zip->addEmptyDir(dirname: self::ASSET_ICONS_DIR.'/');
            $zip->addEmptyDir(dirname: self::ASSET_WIDGETS_DIR.'/');
        }

        $zip->close();

        return $tempPath;
    }//end buildArchive()

    /**
     * Add icon / widget asset placeholders for a dashboard.
     *
     * The current implementation reserves the per-placement directories
     * so the archive layout is stable for downstream consumers; the
     * actual asset bytes are wired through once the
     * `dashboard-icons` and `resource-uploads` capabilities expose a
     * read-by-uuid API.
     *
     * @param ZipArchive $zip           The open archive.
     * @param string     $dashboardUuid The dashboard UUID.
     *
     * @return bool True when at least one entry was added.
     */
    private function addPlaceholderAssets(
        ZipArchive $zip,
        string $dashboardUuid
    ): bool {
        $zip->addEmptyDir(
            dirname: self::ASSET_WIDGETS_DIR.'/'.$dashboardUuid.'/'
        );
        return true;
    }//end addPlaceholderAssets()

    /**
     * Serialize a single widget placement.
     *
     * @param WidgetPlacement $placement The placement entity.
     *
     * @return array<string, mixed> The serialized payload.
     */
    private function serializePlacement(WidgetPlacement $placement): array
    {
        $payload = $placement->jsonSerialize();
        unset($payload['id'], $payload['dashboardId']);
        return $payload;
    }//end serializePlacement()

    /**
     * Collect every dashboard in the instance for a site export.
     *
     * @return Dashboard[] All dashboards (personal + admin templates +
     *                     group-shared).
     */
    private function collectAllDashboards(): array
    {
        $all       = [];
        $seenUuids = [];

        foreach ($this->dashboardMapper->findAdminTemplates() as $tpl) {
            $uuid = (string) $tpl->getUuid();
            if ($uuid === '' || isset($seenUuids[$uuid]) === true) {
                continue;
            }

            $seenUuids[$uuid] = true;
            $all[]            = $tpl;
        }

        foreach ($this->groupManager->search(search: '') as $group) {
            foreach ($this->dashboardMapper->findByGroup(groupId: $group->getGID()) as $shared) {
                $uuid = (string) $shared->getUuid();
                if ($uuid === '' || isset($seenUuids[$uuid]) === true) {
                    continue;
                }

                $seenUuids[$uuid] = true;
                $all[]            = $shared;
            }
        }

        // Walk every personal dashboard via the parent index — root-only
        // call returns the top of every user tree, then descendants pull
        // the rest. The empty-string lookup short-circuits via NULL.
        foreach ($this->dashboardMapper->findByParent(parentUuid: null) as $root) {
            $uuid = (string) $root->getUuid();
            if ($uuid === '' || isset($seenUuids[$uuid]) === true) {
                continue;
            }

            $seenUuids[$uuid] = true;
            $all[]            = $root;
            foreach ($this->dashboardMapper->findDescendants(ancestorUuid: $uuid) as $child) {
                $childUuid = (string) $child->getUuid();
                if ($childUuid === '' || isset($seenUuids[$childUuid]) === true) {
                    continue;
                }

                $seenUuids[$childUuid] = true;
                $all[] = $child;
            }
        }

        return $all;
    }//end collectAllDashboards()

    /**
     * Wrap a temp ZIP file in a streaming HTTP response.
     *
     * @param string $zipPath  Path to the on-disk ZIP.
     * @param string $filename The filename hint for the client.
     *
     * @return StreamResponse The streaming response.
     */
    private function streamZipResponse(
        string $zipPath,
        string $filename
    ): StreamResponse {
        $headers = [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $size = @filesize(filename: $zipPath);
        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        $response = new StreamResponse(
            filePath: $zipPath,
            status: Http::STATUS_OK,
            headers: $headers,
        );

        // Best-effort cleanup; Nextcloud streams the file before deletion
        // is observable on most filesystems.
        register_shutdown_function(
            callback: static function () use ($zipPath): void {
                if (file_exists(filename: $zipPath) === true) {
                    @unlink(filename: $zipPath);
                }
            }
        );

        return $response;
    }//end streamZipResponse()

    /**
     * Encode an array as pretty JSON for the archive.
     *
     * @param mixed $payload The data to encode.
     *
     * @return string The JSON string.
     */
    private function encodeJson(mixed $payload): string
    {
        $encoded = json_encode(
            value: $payload,
            flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($encoded === false) {
            $this->logger->error(
                message: 'Failed to encode export payload: '.json_last_error_msg()
            );
            return '{}';
        }

        return $encoded;
    }//end encodeJson()
}//end class
