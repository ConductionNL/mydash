<?php

/**
 * DashboardVersionService
 *
 * Service backing the `dashboard-versioning` capability
 * (REQ-VERS-001..009). Owns:
 *   - automatic snapshot capture on dashboard PUT (debounced 60 s);
 *   - explicit snapshot creation with optional note;
 *   - version listing, fetching, and restore;
 *   - per-dashboard retention enforcement (50 row limit);
 *   - permission gating via {@see PermissionService::canEditDashboardMetadata}
 *     and the owner-or-admin guard.
 *
 * The service is backend-agnostic at the API boundary
 * (REQ-VERS-008) but only the database backend is exercised here. The
 * groupfolder backend that delegates to `\OCP\IVersionManager` lives in
 * a sibling spec that is currently DEFERRED — until that backend lands,
 * every dashboard is treated as database-backed and the service falls
 * back to local snapshots. The contentBackend dispatch hook is wired
 * via {@see self::isGroupfolderBacked()} so wiring the strategy later
 * is a one-line change.
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

use DateTime;
use Exception;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardVersion;
use OCA\MyDash\Db\DashboardVersionMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for dashboard version snapshots, restore, and retention.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Strategy entry point
 *  for both DB and (future) groupfolder backends naturally pulls in the
 *  three mappers + cache + logger + group manager.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   The four CRUD-ish
 *  endpoints (list/fetch/create/restore) plus the cascade hook live
 *  here intentionally — splitting risks losing the single-point
 *  permission guard.
 */
class DashboardVersionService
{

    /**
     * Default per-dashboard retention limit (REQ-VERS-006).
     *
     * @var integer
     */
    public const RETENTION_LIMIT = DashboardVersionMapper::DEFAULT_RETENTION;

    /**
     * Debounce window for automatic snapshots, in seconds (REQ-VERS-001).
     *
     * @var integer
     */
    public const DEBOUNCE_SECONDS = 60;

    /**
     * Cache namespace used for the per-dashboard debounce key.
     *
     * @var string
     */
    public const CACHE_NAMESPACE = 'mydash_versioning';

    /**
     * Sentinel error message returned by the permission guard.
     *
     * @var string
     */
    public const ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN = 'Forbidden: owner or admin only';

    /**
     * Sentinel error message returned when GroupFolder versioning is
     * unavailable for a non-DB-backed dashboard. Callers MUST catch and
     * convert to the soft-fail response described in REQ-VERS-009.
     *
     * @var string
     */
    public const ERR_VERSIONING_UNAVAILABLE = 'Versioning backend unavailable';

    /**
     * The ICache used for per-dashboard debounce. Lazily resolved via
     * the cache factory; the factory may legally return a no-op cache
     * (NullCache) on dev installs without APCu/Redis — in that case the
     * debounce is skipped, mirroring the design D2 graceful fallback.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor
     *
     * @param DashboardVersionMapper $versionMapper   Version row mapper.
     * @param DashboardMapper        $dashboardMapper Dashboard row mapper.
     * @param WidgetPlacementMapper  $placementMapper Widget placement mapper
     *                                                (drives snapshot
     *                                                payload assembly).
     * @param IGroupManager          $groupManager    Group manager — used
     *                                                exclusively for the
     *                                                owner-or-admin
     *                                                permission guard
     *                                                (no group-membership
     *                                                lookups).
     * @param ICacheFactory          $cacheFactory    NC cache factory
     *                                                supplying the
     *                                                debounce backing
     *                                                store.
     * @param LoggerInterface        $logger          PSR logger.
     */
    public function __construct(
        private readonly DashboardVersionMapper $versionMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IGroupManager $groupManager,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Capture a snapshot of a dashboard's content (REQ-VERS-001 / -002).
     *
     * Behaviour:
     *  - Automatic snapshots (`$explicit = false`) are debounced — at
     *    most one per `DEBOUNCE_SECONDS` per dashboard. Suppressed
     *    snapshots return `null` without writing to the table.
     *  - Explicit snapshots (`$explicit = true`) ALWAYS persist and
     *    bypass the debounce.
     *  - The next versionNumber is allocated as `max(existing) + 1`,
     *    starting at 1 for the first snapshot. Pruning never renumbers
     *    survivors (REQ-VERS-006).
     *  - After a successful insert the retention limit is enforced
     *    inline so the table stays bounded.
     *
     * Snapshot payload composition is deliberately the caller's
     * responsibility — pass the JSON-encoded content directly. When
     * `$snapshotJson` is empty the service derives a default payload
     * from the dashboard + its widget placements via
     * {@see self::buildDefaultSnapshot()} so the API stays usable from
     * controllers that don't yet pass an explicit body.
     *
     * @param Dashboard   $dashboard    The dashboard being snapshotted.
     * @param string|null $snapshotJson Pre-built snapshot JSON, or null
     *                                  to auto-build from the live
     *                                  state.
     * @param string      $createdBy    The acting user ID.
     * @param string|null $note         Optional note for explicit
     *                                  snapshots; ignored / coerced to
     *                                  null on automatic captures.
     * @param boolean     $explicit     Whether to bypass the debounce.
     *
     * @return DashboardVersion|null The persisted version row, or null
     *                               when an automatic snapshot was
     *                               suppressed by the debounce window.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function captureSnapshot(
        Dashboard $dashboard,
        ?string $snapshotJson,
        string $createdBy,
        ?string $note=null,
        bool $explicit=false
    ): ?DashboardVersion {
        $uuid = (string) $dashboard->getUuid();
        if ($uuid === '') {
            // Defensive — without a UUID we have nothing to key the
            // snapshot to. Refuse silently rather than throw because
            // legacy rows may not yet have a UUID.
            return null;
        }

        if ($explicit === false && $this->isDebounced(uuid: $uuid) === true) {
            return null;
        }

        // Auto-build the payload when the caller hands us nothing —
        // this keeps the integration with `DashboardService` simple.
        if ($snapshotJson === null || $snapshotJson === '') {
            $snapshotJson = $this->buildDefaultSnapshot(dashboard: $dashboard);
        }

        $coercedNote = null;
        if ($explicit === true && $note !== null && $note !== '') {
            $coercedNote = $note;
        }

        $next = ($this->versionMapper->findMaxVersionNumber(
            dashboardUuid: $uuid
        ) + 1);

        $entity = new DashboardVersion();
        // Entity setters resolve via __call which uses $args[0]; named
        // args would break the magic forwarding (see project memory).
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setDashboardUuid($uuid);
        $entity->setVersionNumber($next);
        $entity->setSnapshotJson($snapshotJson);
        $entity->setCreatedBy($createdBy);
        $entity->setCreatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );
        $entity->setNote($coercedNote);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $persisted = $this->versionMapper->insert(entity: $entity);

        // REQ-VERS-006: prune inline so the table never exceeds the
        // retention limit by more than the single in-flight insert.
        $this->versionMapper->pruneOldVersions(
            dashboardUuid: $uuid,
            keepCount: self::RETENTION_LIMIT
        );

        // Refresh the debounce key on EVERY successful insert (explicit
        // or automatic) so a flurry of explicit POSTs followed by an
        // automatic PUT still yields at most one auto snapshot per
        // window.
        $this->markDebounced(uuid: $uuid);

        return $persisted;
    }//end captureSnapshot()

    /**
     * List the versions available for a dashboard, newest-first
     * (REQ-VERS-003).
     *
     * Returns metadata only; callers MUST hit
     * {@see self::fetchSnapshot()} for the body. The response shape
     * mirrors the soft-fail contract in REQ-VERS-009 — `modeSupported`
     * is true for database-backed dashboards (always available), false
     * when a groupfolder-backed dashboard's versioning store is missing.
     *
     * @param Dashboard $dashboard      The dashboard to list versions for.
     * @param string    $requestingUser The acting user ID.
     *
     * @return array{versions: array<int, array<string, mixed>>, modeSupported: bool}
     *
     * @throws Exception When the actor is neither owner nor admin.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function listVersions(
        Dashboard $dashboard,
        string $requestingUser
    ): array {
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $requestingUser
        );

        if ($this->isGroupfolderBacked(dashboard: $dashboard) === true) {
            // REQ-VERS-009: groupfolder backend not yet wired; return
            // the soft-fail envelope so the UI can hide the version
            // list cleanly.
            return [
                'versions'      => [],
                'modeSupported' => false,
            ];
        }

        $rows     = $this->versionMapper->findLatestByDashboard(
            dashboardUuid: (string) $dashboard->getUuid(),
            limit: self::RETENTION_LIMIT
        );
        $versions = [];
        foreach ($rows as $row) {
            $versions[] = $row->jsonSerialize();
        }

        return [
            'versions'      => $versions,
            'modeSupported' => true,
        ];
    }//end listVersions()

    /**
     * Fetch the full snapshot body for a single version (REQ-VERS-004).
     *
     * @param Dashboard $dashboard      The dashboard.
     * @param integer   $versionNumber  The version number to retrieve.
     * @param string    $requestingUser The acting user ID.
     *
     * @return DashboardVersion The version row including snapshot body.
     *
     * @throws Exception             When the actor is neither owner nor admin.
     * @throws DoesNotExistException When the version does not exist.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function fetchSnapshot(
        Dashboard $dashboard,
        int $versionNumber,
        string $requestingUser
    ): DashboardVersion {
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $requestingUser
        );

        if ($this->isGroupfolderBacked(dashboard: $dashboard) === true) {
            throw new DoesNotExistException(
                msg: 'Version not found'
            );
        }

        return $this->versionMapper->findByDashboardAndVersion(
            dashboardUuid: (string) $dashboard->getUuid(),
            versionNumber: $versionNumber
        );
    }//end fetchSnapshot()

    /**
     * Create an explicit snapshot via the POST /versions endpoint
     * (REQ-VERS-002). Bypasses the debounce window.
     *
     * @param Dashboard   $dashboard      The dashboard to snapshot.
     * @param string      $requestingUser The acting user ID.
     * @param string|null $note           Optional note; empty becomes NULL.
     *
     * @return DashboardVersion The newly persisted version row.
     *
     * @throws Exception When the actor is neither owner nor admin or
     *                   the groupfolder backend is selected (currently
     *                   unsupported).
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function createExplicitSnapshot(
        Dashboard $dashboard,
        string $requestingUser,
        ?string $note=null
    ): DashboardVersion {
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $requestingUser
        );

        if ($this->isGroupfolderBacked(dashboard: $dashboard) === true) {
            throw new Exception(
                message: self::ERR_VERSIONING_UNAVAILABLE
            );
        }

        $persisted = $this->captureSnapshot(
            dashboard: $dashboard,
            snapshotJson: null,
            createdBy: $requestingUser,
            note: $note,
            explicit: true
        );

        if ($persisted === null) {
            // Should not happen — explicit captures are guaranteed to
            // persist. Defensive programming for future strategy
            // implementations that may opt to throw.
            throw new Exception(
                message: self::ERR_VERSIONING_UNAVAILABLE
            );
        }

        return $persisted;
    }//end createExplicitSnapshot()

    /**
     * Restore a dashboard's content from a historical snapshot
     * (REQ-VERS-005). The current state is captured as a NEW snapshot
     * BEFORE the restore overwrites the body so the operation is
     * itself reversible.
     *
     * Restoring to the latest version is treated as a no-op (idempotent
     * round-trip) — no new snapshot is created and the existing
     * dashboard is returned untouched. This matches the
     * "restore to current is a no-op" scenario in REQ-VERS-005.
     *
     * @param Dashboard $dashboard     The dashboard.
     * @param integer   $versionNumber The target version number.
     * @param string    $restoringUser The acting user ID.
     *
     * @return array{snapshot: string, version: DashboardVersion}
     *   The restored snapshot body + the row that was applied.
     *
     * @throws Exception             When the actor is neither owner nor admin.
     * @throws DoesNotExistException When the version does not exist.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function restoreVersion(
        Dashboard $dashboard,
        int $versionNumber,
        string $restoringUser
    ): array {
        $this->assertOwnerOrAdmin(
            dashboard: $dashboard,
            actorUserId: $restoringUser
        );

        if ($this->isGroupfolderBacked(dashboard: $dashboard) === true) {
            throw new Exception(
                message: self::ERR_VERSIONING_UNAVAILABLE
            );
        }

        $uuid = (string) $dashboard->getUuid();

        $target = $this->versionMapper->findByDashboardAndVersion(
            dashboardUuid: $uuid,
            versionNumber: $versionNumber
        );

        $latest = $this->versionMapper->findMaxVersionNumber(
            dashboardUuid: $uuid
        );

        if ($latest === $versionNumber) {
            // REQ-VERS-005: restoring to the current version is a no-op.
            return [
                'snapshot' => (string) $target->getSnapshotJson(),
                'version'  => $target,
            ];
        }

        // Capture pre-restore state as a new snapshot so the restore
        // itself is reversible (REQ-VERS-005 design D3).
        $this->captureSnapshot(
            dashboard: $dashboard,
            snapshotJson: $this->buildDefaultSnapshot(dashboard: $dashboard),
            createdBy: $restoringUser,
            note: 'pre-restore',
            explicit: true
        );

        // C3 fix: decode the target snapshot and apply it to the DB so the
        // restore is a real state change, not a no-op.
        $snapshotJson = (string) $target->getSnapshotJson();
        $payload      = json_decode($snapshotJson, associative: true);
        if (is_array($payload) === true) {
            $this->applySnapshotPayload(
                dashboard: $dashboard,
                payload: $payload
            );
        }

        // Stamp the dashboard's updatedAt so callers that listen on
        // metadata see the restore (REQ-VERS-005 scenario "restore
        // updates the modified timestamp").
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );
        try {
            $this->dashboardMapper->update(entity: $dashboard);
        } catch (Throwable $t) {
            $this->logger->warning(
                message: 'mydash: failed to stamp updatedAt during restore',
                context: ['exception' => $t]
            );
        }

        return [
            'snapshot' => $snapshotJson,
            'version'  => $target,
        ];
    }//end restoreVersion()

    /**
     * Cascade-delete every snapshot row for a dashboard.
     *
     * Designed to be called from the dashboard delete path or, in the
     * future, the cascade-events VersionsListener stub. Idempotent.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return integer The number of rows deleted.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function deleteVersionsForDashboard(string $dashboardUuid): int
    {
        return $this->versionMapper->deleteByDashboardUuid(
            dashboardUuid: $dashboardUuid
        );
    }//end deleteVersionsForDashboard()

    /**
     * Whether the supplied dashboard is groupfolder-backed
     * (REQ-VERS-008). Currently always false because the groupfolder
     * storage backend is DEFERRED to a sibling spec; the hook exists
     * so wiring the strategy later is a one-line change.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return boolean Whether the dashboard's content lives in a groupfolder.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    public function isGroupfolderBacked(Dashboard $dashboard): bool
    {
        // The groupfolder-storage-backend change introduces a
        // `contentBackend` field; until it lands we look up the value
        // defensively via reflection-free magic getters and treat the
        // unknown / 'database' value as DB-backed.
        if (method_exists($dashboard, 'getContentBackend') === false) {
            return false;
        }

        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $value = $dashboard->getContentBackend();
        return $value === 'groupfolder';
    }//end isGroupfolderBacked()

    /**
     * Whether the per-dashboard debounce window is currently active.
     *
     * Soft-fails to "not debounced" when the cache factory hands us a
     * no-op cache (legal on dev installs without APCu / Redis) so the
     * snapshot is still captured.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return boolean Whether automatic snapshot capture should be skipped.
     */
    private function isDebounced(string $uuid): bool
    {
        $cache = $this->getCache();
        if ($cache === null) {
            return false;
        }

        try {
            return $cache->hasKey(key: $this->debounceKey(uuid: $uuid));
        } catch (Throwable $t) {
            $this->logger->debug(
                message: 'mydash: debounce check failed, allowing snapshot',
                context: ['exception' => $t]
            );
            return false;
        }
    }//end isDebounced()

    /**
     * Mark a dashboard as having just been snapshotted.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return void
     */
    private function markDebounced(string $uuid): void
    {
        $cache = $this->getCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(
                key: $this->debounceKey(uuid: $uuid),
                value: 1,
                ttl: self::DEBOUNCE_SECONDS
            );
        } catch (Throwable $t) {
            $this->logger->debug(
                message: 'mydash: failed to set debounce key',
                context: ['exception' => $t]
            );
        }
    }//end markDebounced()

    /**
     * Build the per-dashboard cache key used for the debounce window.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return string The cache key.
     */
    private function debounceKey(string $uuid): string
    {
        return 'mydash_ver_debounce_'.$uuid;
    }//end debounceKey()

    /**
     * Lazily resolve and memoise the underlying ICache.
     *
     * @return ICache|null The cache, or null when none is available.
     */
    private function getCache(): ?ICache
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $this->cache = $this->cacheFactory->createDistributed(
                prefix: self::CACHE_NAMESPACE
            );
        } catch (Throwable $t) {
            $this->logger->debug(
                message: 'mydash: cache factory unavailable',
                context: ['exception' => $t]
            );
            $this->cache = null;
        }

        return $this->cache;
    }//end getCache()

    /**
     * Build a default snapshot payload from a dashboard's live state.
     *
     * Combines the dashboard's `jsonSerialize()` output with the full
     * placements list so a future restore can rebuild the layout
     * deterministically. Always JSON-encodes — failure to encode falls
     * back to an empty object string so the row remains insertable.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The encoded snapshot body.
     */
    private function buildDefaultSnapshot(Dashboard $dashboard): string
    {
        $dashboardId = $dashboard->getId();

        $placements = [];
        if ($dashboardId !== null) {
            try {
                $rows = $this->placementMapper->findByDashboardId(
                    dashboardId: $dashboardId
                );
                foreach ($rows as $row) {
                    $placements[] = $row->jsonSerialize();
                }
            } catch (Throwable $t) {
                $this->logger->debug(
                    message: 'mydash: failed to load placements for snapshot',
                    context: ['exception' => $t]
                );
            }
        }

        $payload = [
            'dashboard'  => $dashboard->jsonSerialize(),
            'placements' => $placements,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '{}';
        }

        return $encoded;
    }//end buildDefaultSnapshot()

    /**
     * Apply a decoded snapshot payload to the dashboard's child tables.
     *
     * Replaces the existing widget placements with those stored in the
     * snapshot. Runs as an all-or-nothing block: any failure is logged
     * and re-thrown so the caller can surface a 500 rather than leaving
     * the dashboard in a partial state.
     *
     * REQ-VERS-005 (C3 fix): makes restoreVersion() an actual state
     * restore rather than a no-op that only returns the historical JSON.
     *
     * @param Dashboard            $dashboard The target dashboard.
     * @param array<string, mixed> $payload   Decoded snapshot body.
     *
     * @return void
     *
     * @throws Throwable When a mapper operation fails.
     *
     * @spec openspec/specs/dashboard-versioning/spec.md
     */
    private function applySnapshotPayload(Dashboard $dashboard, array $payload): void
    {
        $dashboardId = (int) $dashboard->getId();
        if ($dashboardId === 0) {
            return;
        }

        // Wipe current placements and re-insert the historical set.
        $rawPlacements = $payload['placements'] ?? [];
        if (is_array($rawPlacements) === false) {
            $rawPlacements = [];
        }

        try {
            $this->placementMapper->deleteByDashboardId(
                dashboardId: $dashboardId
            );

            foreach ($rawPlacements as $placementData) {
                if (is_array($placementData) === false) {
                    continue;
                }

                $entity = new \OCA\MyDash\Db\WidgetPlacement();
                // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                $entity->setDashboardId($dashboardId);

                foreach ($placementData as $field => $value) {
                    if ($field === 'id') {
                        // Do not re-use the old PK — let the DB assign a new one.
                        continue;
                    }

                    $setter = 'set'.ucfirst((string) $field);
                    if (method_exists($entity, $setter) === true) {
                        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
                        $entity->$setter($value);
                    }
                }

                $this->placementMapper->insert(entity: $entity);
            }//end foreach
        } catch (Throwable $t) {
            $this->logger->error(
                message: 'mydash: applySnapshotPayload failed for dashboard '.$dashboardId,
                context: ['exception' => $t]
            );
            throw $t;
        }//end try
    }//end applySnapshotPayload()

    /**
     * Owner-or-admin guard used by every public endpoint.
     *
     * @param Dashboard $dashboard   The dashboard being acted upon.
     * @param string    $actorUserId The acting user ID.
     *
     * @return void
     *
     * @throws Exception When the actor is neither owner nor admin.
     */
    private function assertOwnerOrAdmin(
        Dashboard $dashboard,
        string $actorUserId
    ): void {
        $ownerId = $dashboard->getUserId();
        if ($ownerId !== null && $ownerId === $actorUserId) {
            return;
        }

        try {
            if ($this->groupManager->isAdmin(userId: $actorUserId) === true) {
                return;
            }
        } catch (Throwable) {
            // Defensive — fall through to the denial path so a flaky
            // group manager never silently authorises a caller.
        }

        throw new Exception(
            message: self::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
        );
    }//end assertOwnerOrAdmin()
}//end class
