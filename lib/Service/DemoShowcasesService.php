<?php

/**
 * DemoShowcasesService
 *
 * Lists, installs, and uninstalls bundled demo "showcase" dashboards
 * shipped with MyDash. Each showcase is delivered as a
 * `mydash-export-v1.zip` archive under `data/demo-showcases/{id}/{id}.zip`
 * and is installed as a `group_shared` dashboard scoped to the
 * `default` sentinel group so every user sees it (REQ-DASH-012).
 *
 * Installation tracking is per-showcase via `IConfig::getAppValue` keys
 * shaped as `showcase_installed_<id>` whose value is the installed
 * dashboard UUID — the reference source app used a single boolean
 * which only supports one dataset; the per-showcase approach scales
 * naturally (REQ-DEMO-004).
 *
 * Implements the `demo-data-showcases` capability (REQ-DEMO-001..009).
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

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\ShowcaseNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Dashboard\IManager;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Manage bundled demo showcase dashboards.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *      Mirrors injected dependencies — list / install / uninstall
 *      cohesively belong on one service surface.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *      Bundled-asset extraction + ZIP parse + DB writes are kept in
 *      one service so callers see a single entry point.
 */
class DemoShowcasesService
{
    /**
     * Dashboard metadata key used for idempotency tracking.
     *
     * Stored as `showcase_installed_<id>` via IConfig; the value is the
     * installed dashboard UUID. Empty string means "not installed".
     *
     * @var string
     */
    public const CONFIG_PREFIX = 'showcase_installed_';

    /**
     * Bundled showcase IDs.
     *
     * The set is fixed at v1 (REQ-DEMO-001). The Dutch fictional
     * organisation names mirror the reference source dataset so
     * existing copy / screenshots remain reusable.
     *
     * @var array<int, string>
     */
    public const BUNDLED_IDS = [
        'de-bron',
        'de-linden',
        'gemeente-duin',
        'horizon-labs',
        'van-der-berg',
    ];

    /**
     * Optional data-directory override (test seam).
     *
     * @var string|null
     */
    private ?string $dataDirOverride = null;

    /**
     * Constructor.
     *
     * @param DashboardMapper       $dashboardMapper  Dashboard data mapper.
     * @param WidgetPlacementMapper $placementMapper  Widget placement mapper.
     * @param IDBConnection         $db               Database connection.
     * @param IConfig               $config           App / user config service.
     * @param IManager              $dashboardManager Nextcloud dashboard registry.
     * @param LoggerInterface       $logger           PSR-3 logger.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly IManager $dashboardManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Override the bundled-showcase data directory (test seam).
     *
     * Production code MUST NOT call this — the directory resolves
     * automatically against the app source tree. Tests pass an
     * explicit fixture directory so they can stage controlled ZIPs.
     *
     * @param string $path Absolute path to the test fixture directory.
     *
     * @return void
     */
    public function setDataDirForTesting(string $path): void
    {
        $this->dataDirOverride = $path;
    }//end setDataDirForTesting()

    /**
     * Resolve the on-disk path of the bundled showcase data directory.
     *
     * Returns `<app-root>/data/demo-showcases` unless overridden via
     * {@see DemoShowcasesService::setDataDirForTesting()}.
     *
     * @return string Absolute filesystem path.
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function getDataDir(): string
    {
        if ($this->dataDirOverride !== null) {
            return $this->dataDirOverride;
        }

        return dirname(path: __DIR__, levels: 2).'/data/demo-showcases';
    }//end getDataDir()

    /**
     * List every bundled showcase with installation status.
     *
     * Iterates the manifest set rather than scanning the directory so
     * the returned list is stable and predictable for the admin UI.
     *
     * @return array<int, array<string, mixed>> Showcase descriptors.
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function getAvailableShowcases(): array
    {
        $result = [];
        foreach (self::BUNDLED_IDS as $id) {
            $descriptor = $this->describeShowcase(showcaseId: $id);
            if ($descriptor === null) {
                continue;
            }

            $result[] = $descriptor;
        }

        return $result;
    }//end getAvailableShowcases()

    /**
     * Resolve a single descriptor with installation status.
     *
     * Returns `null` when the underlying ZIP is unreadable so the
     * caller can skip the entry without surfacing partial data
     * (REQ-DEMO-001 invalid-ZIP scenario).
     *
     * @param string $showcaseId The showcase ID.
     *
     * @return array<string, mixed>|null The descriptor, or `null` when
     *                                   the ZIP is missing/malformed.
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function describeShowcase(string $showcaseId): ?array
    {
        $zipPath = $this->getZipPath(showcaseId: $showcaseId);
        if (file_exists(filename: $zipPath) === false) {
            return null;
        }

        $manifest = $this->readManifest(zipPath: $zipPath);
        if ($manifest === null) {
            return null;
        }

        $installedUuid        = $this->getInstalledUuid(showcaseId: $showcaseId);
        $installedDashboardId = null;
        if ($installedUuid !== '') {
            $installedDashboardId = $installedUuid;
        }

        return [
            'id'                     => $showcaseId,
            'name'                   => (string) ($manifest['showcaseName'] ?? $showcaseId),
            'description'            => (string) ($manifest['showcaseDescription'] ?? ''),
            'language'               => (string) ($manifest['showcaseLanguage'] ?? 'nl'),
            'thumbnailUrl'           => '/apps/'.Application::APP_ID.'/img/showcases/'.$showcaseId.'.png',
            'isInstalled'            => $installedUuid !== '',
            'installedDashboardUuid' => $installedDashboardId,
        ];
    }//end describeShowcase()

    /**
     * Install a showcase as a `group_shared` dashboard.
     *
     * Idempotent: when the showcase is already installed and `$force`
     * is `false` the existing UUID is returned without further work.
     * When `$force` is `true` any existing install is uninstalled first
     * so the caller always receives a fresh dashboard UUID
     * (REQ-DEMO-004, REQ-DEMO-009 `--force` flag).
     *
     * @param string $showcaseId The showcase ID.
     * @param string $lang       Optional locale (accepted for forward
     *                           compatibility, always resolves to `nl`
     *                           in v1 — REQ-DEMO-007).
     * @param bool   $force      When true, reinstall even if the
     *                           showcase is already installed.
     *
     * @return array{installedDashboardUuid:string, skippedWidgets:array<int, string>, alreadyInstalled:bool}
     *
     * @throws ShowcaseNotFoundException When the showcase ID is unknown.
     * @throws RuntimeException          On ZIP / persistence failures.
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function installShowcase(
        string $showcaseId,
        string $lang='nl',
        bool $force=false
    ): array {
        $this->assertKnownShowcase(showcaseId: $showcaseId);

        $zipPath = $this->getZipPath(showcaseId: $showcaseId);
        if (file_exists(filename: $zipPath) === false) {
            throw new ShowcaseNotFoundException(
                message: 'Showcase ZIP missing for '.$showcaseId
            );
        }

        $existingUuid = $this->getInstalledUuid(showcaseId: $showcaseId);
        if ($existingUuid !== '' && $force === false) {
            return [
                'installedDashboardUuid' => $existingUuid,
                'skippedWidgets'         => [],
                'alreadyInstalled'       => true,
            ];
        }

        if ($existingUuid !== '' && $force === true) {
            $this->uninstallShowcase(showcaseId: $showcaseId);
        }

        $payload = $this->loadDashboardPayload(zipPath: $zipPath);
        $widgets = (array) ($payload['widgets'] ?? []);

        [$valid, $skipped] = $this->partitionWidgets(widgets: $widgets);

        $dashboard = $this->buildDashboardEntity(
            showcaseId: $showcaseId,
            payload: $payload,
            sourceLanguage: $lang
        );

        $this->db->beginTransaction();
        try {
            $persisted = $this->dashboardMapper->insert(entity: $dashboard);
            foreach ($valid as $widgetPayload) {
                $placement = $this->buildPlacement(
                    dashboardId: (int) $persisted->getId(),
                    payload: $widgetPayload
                );
                $this->placementMapper->insert(entity: $placement);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw new RuntimeException(
                message: 'Failed to persist showcase: '.$e->getMessage(),
                code: 0,
                previous: $e
            );
        }

        $installedUuid = (string) $persisted->getUuid();
        $this->markInstalled(
            showcaseId: $showcaseId,
            dashboardUuid: $installedUuid
        );

        $this->logger->info(
            message: 'Installed demo showcase',
            context: [
                'showcaseId'     => $showcaseId,
                'dashboardUuid'  => $installedUuid,
                'skippedWidgets' => $skipped,
                'sourceLanguage' => $lang,
            ]
        );

        return [
            'installedDashboardUuid' => $installedUuid,
            'skippedWidgets'         => $skipped,
            'alreadyInstalled'       => false,
        ];
    }//end installShowcase()

    /**
     * Uninstall a previously-installed showcase.
     *
     * Idempotent — silently no-ops when the showcase has no recorded
     * installation. Cascades to widget placements and clears the
     * tracking config key (REQ-DEMO-006).
     *
     * @param string $showcaseId The showcase ID.
     *
     * @return void
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function uninstallShowcase(string $showcaseId): void
    {
        $existingUuid = $this->getInstalledUuid(showcaseId: $showcaseId);
        if ($existingUuid === '') {
            return;
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $existingUuid);
            $dashId    = (int) $dashboard->getId();
            $this->placementMapper->deleteByDashboardId(dashboardId: $dashId);
            $this->dashboardMapper->delete(entity: $dashboard);
        } catch (DoesNotExistException) {
            // The dashboard was deleted out-of-band — clear the marker
            // and proceed.
            $this->logger->warning(
                message: 'Showcase dashboard already missing on uninstall',
                context: ['showcaseId' => $showcaseId, 'uuid' => $existingUuid]
            );
        }

        $this->clearInstalledMarker(showcaseId: $showcaseId);
    }//end uninstallShowcase()

    /**
     * Look up the recorded installed dashboard UUID for a showcase.
     *
     * @param string $showcaseId The showcase ID.
     *
     * @return string The UUID, or empty string when not installed.
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function getInstalledUuid(string $showcaseId): string
    {
        return (string) $this->config->getAppValue(
            Application::APP_ID,
            self::CONFIG_PREFIX.$showcaseId,
            ''
        );
    }//end getInstalledUuid()

    /**
     * Cross-reference a widget collection against the registered
     * Nextcloud dashboard widget registry, returning valid + skipped
     * partitions (REQ-DEMO-005).
     *
     * Tile placements (rows where `tileType` is non-null) are always
     * considered valid — tiles are owned by MyDash itself and do not
     * require a third-party widget registration.
     *
     * @param array<int, mixed> $widgets Widget payloads from the
     *                                   showcase JSON.
     *
     * @return array{0:array<int,array<string,mixed>>, 1:array<int,string>}
     */
    /** @spec openspec/specs/demo-data-showcases/spec.md */
    public function partitionWidgets(array $widgets): array
    {
        $registered = [];
        foreach ($this->dashboardManager->getWidgets() as $widget) {
            $registered[$widget->getId()] = true;
        }

        $valid   = [];
        $skipped = [];
        foreach ($widgets as $widget) {
            if (is_array($widget) === false) {
                continue;
            }

            $widgetId = (string) ($widget['widgetId'] ?? '');
            $tileType = (string) ($widget['tileType'] ?? '');

            // Tile placements bypass the widget registry — they render
            // through the MyDash tile renderer (REQ-DEMO-005).
            if ($tileType !== '') {
                $valid[] = $widget;
                continue;
            }

            if (isset($registered[$widgetId]) === true) {
                $valid[] = $widget;
                continue;
            }

            if ($widgetId !== '') {
                $skipped[] = $widgetId;
            }
        }//end foreach

        return [$valid, $skipped];
    }//end partitionWidgets()

    /**
     * Resolve the on-disk ZIP path for a showcase ID.
     *
     * @param string $showcaseId The showcase ID.
     *
     * @return string The filesystem path.
     */
    private function getZipPath(string $showcaseId): string
    {
        return $this->getDataDir().'/'.$showcaseId.'/'.$showcaseId.'.zip';
    }//end getZipPath()

    /**
     * Parse and return the manifest from a showcase ZIP archive.
     *
     * Returns `null` when the archive is unreadable or the manifest
     * is malformed; callers treat `null` as "skip this entry"
     * (REQ-DEMO-001 invalid-ZIP scenario).
     *
     * @param string $zipPath The ZIP path.
     *
     * @return array<string, mixed>|null The decoded manifest.
     */
    private function readManifest(string $zipPath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open(filename: $zipPath, flags: ZipArchive::RDONLY) !== true) {
            $this->logger->warning(
                message: 'Showcase ZIP could not be opened',
                context: ['path' => $zipPath]
            );
            return null;
        }

        try {
            $raw = $zip->getFromName(name: 'manifest.json');
            if ($raw === false) {
                return null;
            }

            $decoded = json_decode(json: $raw, associative: true);
            if (is_array($decoded) === false) {
                return null;
            }

            return $decoded;
        } finally {
            $zip->close();
        }
    }//end readManifest()

    /**
     * Load the dashboard payload from a showcase ZIP archive.
     *
     * Each showcase ZIP MUST contain exactly one `dashboards/{uuid}.json`
     * entry (the showcase IS the dashboard).
     *
     * @param string $zipPath The ZIP path.
     *
     * @return array<string, mixed> The decoded dashboard payload.
     *
     * @throws RuntimeException When the dashboard payload is missing or
     *                          malformed.
     */
    private function loadDashboardPayload(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open(filename: $zipPath, flags: ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(
                message: 'Could not open showcase archive: '.$zipPath
            );
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex(index: $i);
                if (str_starts_with(haystack: $name, needle: 'dashboards/') === false) {
                    continue;
                }

                if (str_ends_with(haystack: $name, needle: '.json') === false) {
                    continue;
                }

                $raw = $zip->getFromIndex(index: $i);
                if ($raw === false) {
                    continue;
                }

                $decoded = json_decode(json: $raw, associative: true);
                if (is_array($decoded) === true) {
                    return $decoded;
                }
            }
        } finally {
            $zip->close();
        }//end try

        throw new RuntimeException(
            message: 'Showcase archive contained no dashboard JSON entry.'
        );
    }//end loadDashboardPayload()

    /**
     * Hydrate a Dashboard entity for a freshly-installed showcase.
     *
     * Always creates a `group_shared` dashboard with the `default`
     * group sentinel so every user sees it (REQ-DASH-012, REQ-DEMO-003).
     * The dashboard is published immediately — showcases skip the
     * draft state.
     *
     * @param string               $showcaseId     The showcase ID (for
     *                                             slug + audit trail).
     * @param array<string, mixed> $payload        The decoded payload.
     * @param string               $sourceLanguage The requested locale.
     *
     * @return Dashboard The new entity (not yet persisted).
     */
    private function buildDashboardEntity(
        string $showcaseId,
        array $payload,
        string $sourceLanguage
    ): Dashboard {
        $dashboard = new Dashboard();

        $name        = (string) ($payload['name'] ?? $showcaseId);
        $description = null;
        if (isset($payload['description']) === true && $payload['description'] !== null) {
            $description = (string) $payload['description'];
        }

        $iconValue = (string) ($payload['icon'] ?? '');
        $icon      = null;
        if ($iconValue !== '') {
            $icon = $iconValue;
        }

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUuid($this->generateUuidV4());
        $dashboard->setName($name);
        $dashboard->setDescription($description);
        $dashboard->setIcon($icon);
        $dashboard->setType(Dashboard::TYPE_GROUP_SHARED);
        $dashboard->setUserId(null);
        $dashboard->setGroupId(Dashboard::DEFAULT_GROUP_ID);
        $dashboard->setGridColumns((int) ($payload['gridColumns'] ?? 12));
        $dashboard->setPermissionLevel(Dashboard::PERMISSION_VIEW_ONLY);
        $dashboard->setIsDefault(0);
        $dashboard->setIsActive(0);
        $dashboard->setSlug('showcase-'.$showcaseId);
        $dashboard->setSortOrder(0);
        $dashboard->setPublicationStatus(Dashboard::STATUS_PUBLISHED);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        // Surface the showcase + locale in the description tail when the
        // payload omits one — keeps every install discoverable in
        // post-install audits even though the entity has no dedicated
        // metadata column. The descriptive suffix uses an HTML comment
        // so it survives admin UI edits without rendering noise.
        if ($description === null) {
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $dashboard->setDescription(
                'MyDash demo showcase ('.$showcaseId.', '.$sourceLanguage.')'
            );
        }

        return $dashboard;
    }//end buildDashboardEntity()

    /**
     * Hydrate a WidgetPlacement entity from a payload.
     *
     * Mirrors {@see ImportService::buildPlacement} so the showcase
     * format and the export-import format stay byte-compatible.
     *
     * @param int                  $dashboardId The freshly-inserted
     *                                          dashboard ID.
     * @param array<string, mixed> $payload     The widget payload.
     *
     * @return WidgetPlacement The placement entity.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *      Field-by-field guards mirror the export-import format and
     *      are clearer than a map-driven setter.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildPlacement(
        int $dashboardId,
        array $payload
    ): WidgetPlacement {
        $placement = new WidgetPlacement();

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId((string) ($payload['widgetId'] ?? ''));
        $placement->setGridX((int) ($payload['gridX'] ?? 0));
        $placement->setGridY((int) ($payload['gridY'] ?? 0));
        $placement->setGridWidth((int) ($payload['gridWidth'] ?? 4));
        $placement->setGridHeight((int) ($payload['gridHeight'] ?? 4));
        $placement->setIsVisible((int) ($payload['isVisible'] ?? 1));
        $placement->setShowTitle((int) ($payload['showTitle'] ?? 1));
        $placement->setSortOrder((int) ($payload['sortOrder'] ?? 0));

        if (isset($payload['styleConfig']) === true && is_array($payload['styleConfig']) === true) {
            $placement->setStyleConfigArray(config: $payload['styleConfig']);
        }

        if (isset($payload['customTitle']) === true && $payload['customTitle'] !== null) {
            $placement->setCustomTitle((string) $payload['customTitle']);
        }

        // Tile fields — see WidgetPlacement::jsonSerialize().
        if (isset($payload['tileType']) === true && $payload['tileType'] !== null) {
            $placement->setTileType((string) $payload['tileType']);
            $placement->setTileTitle((string) ($payload['tileTitle'] ?? ''));
            if (isset($payload['tileIcon']) === true && $payload['tileIcon'] !== null) {
                $placement->setTileIcon((string) $payload['tileIcon']);
            }

            if (isset($payload['tileIconType']) === true && $payload['tileIconType'] !== null) {
                $placement->setTileIconType((string) $payload['tileIconType']);
            }

            if (isset($payload['tileBackgroundColor']) === true && $payload['tileBackgroundColor'] !== null) {
                $placement->setTileBackgroundColor((string) $payload['tileBackgroundColor']);
            }

            if (isset($payload['tileTextColor']) === true && $payload['tileTextColor'] !== null) {
                $placement->setTileTextColor((string) $payload['tileTextColor']);
            }

            if (isset($payload['tileLinkType']) === true && $payload['tileLinkType'] !== null) {
                $placement->setTileLinkType((string) $payload['tileLinkType']);
            }

            if (isset($payload['tileLinkValue']) === true && $payload['tileLinkValue'] !== null) {
                $placement->setTileLinkValue((string) $payload['tileLinkValue']);
            }
        }//end if

        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        return $placement;
    }//end buildPlacement()

    /**
     * Persist the per-showcase install marker.
     *
     * @param string $showcaseId    The showcase ID.
     * @param string $dashboardUuid The installed dashboard UUID.
     *
     * @return void
     */
    private function markInstalled(string $showcaseId, string $dashboardUuid): void
    {
        $this->config->setAppValue(
            Application::APP_ID,
            self::CONFIG_PREFIX.$showcaseId,
            $dashboardUuid
        );
    }//end markInstalled()

    /**
     * Clear the per-showcase install marker.
     *
     * @param string $showcaseId The showcase ID.
     *
     * @return void
     */
    private function clearInstalledMarker(string $showcaseId): void
    {
        $this->config->deleteAppValue(
            Application::APP_ID,
            self::CONFIG_PREFIX.$showcaseId
        );
    }//end clearInstalledMarker()

    /**
     * Reject unknown showcase IDs with a typed exception.
     *
     * @param string $showcaseId The candidate ID.
     *
     * @return void
     *
     * @throws ShowcaseNotFoundException When the ID is not bundled.
     */
    private function assertKnownShowcase(string $showcaseId): void
    {
        if (in_array(needle: $showcaseId, haystack: self::BUNDLED_IDS, strict: true) === false) {
            throw new ShowcaseNotFoundException(
                message: 'Unknown showcase ID: '.$showcaseId
            );
        }
    }//end assertKnownShowcase()

    /**
     * Generate a v4 UUID for the freshly-installed dashboard.
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
