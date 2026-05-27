<?php

/**
 * BulkOperationService
 *
 * Orchestrates batched admin operations on dashboards: bulk delete,
 * bulk move (re-parent), bulk publication-status update, and bulk
 * search-reindex. Implements REQ-BULK-001..011 from the
 * `dashboard-bulk-operations` capability.
 *
 * Per REQ-BULK-005 the permission pre-check is all-or-nothing
 * (a single unauthorised UUID rejects the entire batch with HTTP 403)
 * while DB-level mutations run per dashboard with continue-on-error
 * semantics — partial success is reported in `errors`. Per REQ-BULK-006
 * the maximum batch size defaults to 500 and is admin-tunable via the
 * `bulk_operation_max_per_request` app config key.
 *
 * Audit events fire exactly once per bulk operation through
 * {@see ActivityPublisher::publishGlobal()} (REQ-BULK-009): one summary
 * Activity row per request, regardless of dashboard count or dry-run
 * mode.
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
use InvalidArgumentException;
use OCA\MyDash\Activity\ActivityPublisher;
use OCA\MyDash\Activity\Extension;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\DashboardHasChildrenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates dashboard bulk operations (REQ-BULK-001..011).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The four bulk endpoints
 *  share the same permission, audit, and idempotency scaffolding; splitting
 *  the service would duplicate the all-or-nothing guard logic.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Branching mirrors the
 *  per-operation idempotency rules pinned in REQ-BULK-007.
 */
class BulkOperationService
{
    /**
     * App config key controlling the per-request cap (REQ-BULK-006).
     *
     * @var string
     */
    public const CONFIG_KEY_MAX_PER_REQUEST = 'bulk_operation_max_per_request';

    /**
     * Default value when no admin-tuned override is present (REQ-BULK-006).
     *
     * @var int
     */
    public const DEFAULT_MAX_PER_REQUEST = 500;

    /**
     * Operation labels used in the audit payload + error envelopes
     * (REQ-BULK-009).
     */
    public const OP_DELETE  = 'delete';
    public const OP_MOVE    = 'move';
    public const OP_STATUS  = 'status';
    public const OP_REINDEX = 'reindex';

    /**
     * Stable error reasons returned in the per-uuid `errors` array.
     */
    public const REASON_ALREADY_DELETED      = 'already_deleted';
    public const REASON_PARENT_ALREADY_MATCH = 'parent_already_matches';
    public const REASON_STATUS_ALREADY_MATCH = 'status_already_matches';
    public const REASON_CYCLE_DETECTED       = 'cycle_detected';
    public const REASON_TRANSACTION_FAILED   = 'transaction_failed';
    public const REASON_REINDEX_FAILED       = 'reindex_failed';
    public const REASON_NOT_FOUND            = 'not_found';
    public const REASON_INVALID_PARENT       = 'invalid_parent';

    /**
     * Constructor.
     *
     * @param DashboardMapper       $dashboardMapper   The dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper   The placement mapper
     *                                                 (used by the per-uuid
     *                                                 hard delete cascade).
     * @param PermissionService     $permissionService Permission resolver
     *                                                 used for the per-uuid
     *                                                 admin pre-check
     *                                                 (REQ-BULK-011).
     * @param DashboardTreeService  $treeService       Cycle / cascade
     *                                                 service delegated by
     *                                                 bulk-move + bulk-delete
     *                                                 (REQ-BULK-001/002).
     * @param ActivityPublisher     $activityPublisher Single-event audit
     *                                                 emitter
     *                                                 (REQ-BULK-009).
     * @param IGroupManager         $groupManager      Used for the "is the
     *                                                 caller an NC admin"
     *                                                 short-circuit on the
     *                                                 permission pre-check.
     * @param IAppConfig            $appConfig         App config for
     *                                                 reading the
     *                                                 per-request cap.
     * @param LoggerInterface       $logger            Diagnostic logger.
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly PermissionService $permissionService,
        private readonly DashboardTreeService $treeService,
        private readonly ActivityPublisher $activityPublisher,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Hard-delete multiple dashboards (REQ-BULK-001).
     *
     * Returns `[deletedCount, skippedCount, errors]` (or the `wouldX`
     * variants when `$dryRun` is true). Cascade to child dashboards is
     * opt-in: when `$cascade` is false and any dashboard has children,
     * that dashboard is rejected with `dashboard_has_children` and a
     * `childCount` field in the per-uuid error.
     *
     * @param string[] $dashboardUuids The dashboard UUIDs to delete.
     * @param string   $userId         The acting NC user ID.
     * @param bool     $dryRun         When true, preview only.
     * @param bool     $cascade        When true, recurse into children.
     *
     * @return array<string, mixed> The result envelope.
     *
     * @throws InvalidArgumentException When the request exceeds the cap.
     * @throws PermissionDeniedException When any UUID is unauthorised.
     *
     * @spec openspec/specs/dashboard-bulk-operations/spec.md
     */
    public function bulkDelete(
        array $dashboardUuids,
        string $userId,
        bool $dryRun=false,
        bool $cascade=false
    ): array {
        $start = microtime(as_float: true);
        $this->assertPermissions(uuids: $dashboardUuids, userId: $userId);
        $this->assertWithinCap(uuids: $dashboardUuids);

        $deleted = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($dashboardUuids as $uuid) {
            try {
                $dashboard = $this->dashboardMapper->findByUuid(uuid: (string) $uuid);
            } catch (DoesNotExistException) {
                // REQ-BULK-007: hard delete on a missing row is silent.
                $skipped++;
                $errors[] = [
                    'uuid'   => (string) $uuid,
                    'reason' => self::REASON_ALREADY_DELETED,
                ];
                continue;
            }

            $childCount = 0;
            $rowUuid    = (string) $dashboard->getUuid();
            if ($rowUuid !== '') {
                $childCount = $this->dashboardMapper->countChildrenByParent(
                    parentUuid: $rowUuid
                );
            }

            if ($childCount > 0 && $cascade === false) {
                $errors[] = [
                    'uuid'       => $rowUuid,
                    'reason'     => DashboardHasChildrenException::ERROR_CODE,
                    'childCount' => $childCount,
                ];
                $skipped++;
                continue;
            }

            if ($dryRun === true) {
                $increment = 1;
                if ($cascade === true && $childCount > 0) {
                    $increment = ($childCount + 1);
                }

                $deleted += $increment;
                continue;
            }

            try {
                if ($cascade === true && $childCount > 0) {
                    $deleted += $this->treeService->deleteSubtree(
                        dashboard: $dashboard
                    );
                } else {
                    $this->placementMapper->deleteByDashboardId(
                        dashboardId: (int) $dashboard->getId()
                    );
                    $this->dashboardMapper->delete(entity: $dashboard);
                    $deleted++;
                }
            } catch (Throwable $t) {
                $this->logger->error(
                    message: 'BulkOperationService::bulkDelete failed for uuid',
                    context: ['uuid' => $rowUuid, 'exception' => $t]
                );
                $errors[] = [
                    'uuid'   => $rowUuid,
                    'reason' => self::REASON_TRANSACTION_FAILED,
                    'detail' => $t->getMessage(),
                ];
            }//end try
        }//end foreach

        $payload = [
            $this->countKey(dryRun: $dryRun, real: 'deletedCount', preview: 'wouldDeleteCount') => $deleted,
            $this->countKey(dryRun: $dryRun, real: 'skippedCount', preview: 'wouldSkipCount')   => $skipped,
            'errors'                                                                            => $errors,
            'dryRun'                                                                            => $dryRun,
        ];

        $this->emitAuditEvent(
            operation: self::OP_DELETE,
            dashboardCount: count($dashboardUuids),
            userId: $userId,
            durationMs: $this->elapsedMs(start: $start),
            dryRun: $dryRun
        );

        return $payload;
    }//end bulkDelete()

    /**
     * Re-parent multiple dashboards in the dashboard hierarchy
     * (REQ-BULK-002).
     *
     * Cycle detection is delegated to {@see DashboardTreeService} so the
     * single source of truth for tree invariants stays in one place.
     *
     * @param string[]    $dashboardUuids The dashboards to re-parent.
     * @param string|null $parentUuid     The new parent UUID
     *                                    (NULL ⇒ root).
     * @param string      $userId         The acting NC user ID.
     * @param bool        $dryRun         When true, preview only.
     *
     * @return array<string, mixed> The result envelope.
     *
     * @throws InvalidArgumentException  When the request exceeds the cap
     *                                   or the parent does not exist.
     * @throws PermissionDeniedException When any UUID is unauthorised.
     *
     * @spec openspec/specs/dashboard-bulk-operations/spec.md
     */
    public function bulkMove(
        array $dashboardUuids,
        ?string $parentUuid,
        string $userId,
        bool $dryRun=false
    ): array {
        $start = microtime(as_float: true);
        $this->assertPermissions(uuids: $dashboardUuids, userId: $userId);
        $this->assertWithinCap(uuids: $dashboardUuids);

        if ($parentUuid !== null && $parentUuid !== '') {
            try {
                $this->dashboardMapper->findByUuid(uuid: $parentUuid);
            } catch (DoesNotExistException) {
                throw new InvalidArgumentException(
                    message: DashboardTreeService::ERR_PARENT_NOT_FOUND
                );
            }
        }

        $moved   = 0;
        $skipped = 0;
        $errors  = [];

        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');

        foreach ($dashboardUuids as $uuid) {
            $uuidString = (string) $uuid;
            try {
                $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuidString);
            } catch (DoesNotExistException) {
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_NOT_FOUND,
                ];
                continue;
            }

            $current = $dashboard->getParentUuid();
            if (($current ?? '') === ($parentUuid ?? '')) {
                // REQ-BULK-007 idempotency: already at target parent.
                $skipped++;
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_PARENT_ALREADY_MATCH,
                ];
                continue;
            }

            try {
                $this->treeService->validateParent(
                    movingUuid: $uuidString,
                    newParentUuid: $parentUuid
                );
            } catch (InvalidArgumentException $e) {
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_CYCLE_DETECTED,
                    'detail' => $e->getMessage(),
                ];
                continue;
            }

            if ($dryRun === true) {
                $moved++;
                continue;
            }

            try {
                $dashboard->setParentUuid($parentUuid);
                $dashboard->setUpdatedAt($now);
                $this->dashboardMapper->update(entity: $dashboard);
                $moved++;
            } catch (Throwable $t) {
                $this->logger->error(
                    message: 'BulkOperationService::bulkMove failed for uuid',
                    context: ['uuid' => $uuidString, 'exception' => $t]
                );
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_TRANSACTION_FAILED,
                    'detail' => $t->getMessage(),
                ];
            }
        }//end foreach

        $payload = [
            $this->countKey(dryRun: $dryRun, real: 'movedCount', preview: 'wouldMoveCount')   => $moved,
            $this->countKey(dryRun: $dryRun, real: 'skippedCount', preview: 'wouldSkipCount') => $skipped,
            'errors'                                                                          => $errors,
            'dryRun'                                                                          => $dryRun,
        ];

        $this->emitAuditEvent(
            operation: self::OP_MOVE,
            dashboardCount: count($dashboardUuids),
            userId: $userId,
            durationMs: $this->elapsedMs(start: $start),
            dryRun: $dryRun
        );

        return $payload;
    }//end bulkMove()

    /**
     * Update the publication status of multiple dashboards (REQ-BULK-003).
     *
     * Status enum is the canonical {@see Dashboard::STATUS_DRAFT} /
     * {@see Dashboard::STATUS_PUBLISHED} / {@see Dashboard::STATUS_SCHEDULED}
     * triple. `publishAt` is required when the target status is
     * `scheduled` and is rejected when in the past.
     *
     * @param string[]    $dashboardUuids    The dashboards to mutate.
     * @param string      $publicationStatus The target status enum value.
     * @param string|null $publishAt         Future ISO-8601 timestamp
     *                                       (required for `scheduled`).
     * @param string      $userId            The acting NC user ID.
     * @param bool        $dryRun            When true, preview only.
     *
     * @return array<string, mixed> The result envelope.
     *
     * @throws InvalidArgumentException  When the status is not in the
     *                                   enum, when `publishAt` is missing
     *                                   for `scheduled`, when the cap is
     *                                   exceeded, or when `publishAt` is
     *                                   in the past.
     * @throws PermissionDeniedException When any UUID is unauthorised.
     *
     * @spec openspec/specs/dashboard-bulk-operations/spec.md
     */
    public function bulkStatus(
        array $dashboardUuids,
        string $publicationStatus,
        ?string $publishAt,
        string $userId,
        bool $dryRun=false
    ): array {
        $start = microtime(as_float: true);
        $this->assertPermissions(uuids: $dashboardUuids, userId: $userId);
        $this->assertWithinCap(uuids: $dashboardUuids);

        $allowed = [
            Dashboard::STATUS_DRAFT,
            Dashboard::STATUS_PUBLISHED,
            Dashboard::STATUS_SCHEDULED,
        ];

        if (in_array(needle: $publicationStatus, haystack: $allowed, strict: true) === false) {
            throw new InvalidArgumentException(
                message: 'publicationStatus must be one of: draft, published, scheduled'
            );
        }

        $resolvedPublishAt = null;
        if ($publicationStatus === Dashboard::STATUS_SCHEDULED) {
            if ($publishAt === null || trim($publishAt) === '') {
                throw new InvalidArgumentException(
                    message: 'publishAt is required when publicationStatus is "scheduled"'
                );
            }

            $resolvedPublishAt = $this->parseFutureDate(publishAt: $publishAt);
        }

        $updated = 0;
        $skipped = 0;
        $errors  = [];
        $now     = (new DateTime())->format(format: 'Y-m-d H:i:s');

        foreach ($dashboardUuids as $uuid) {
            $uuidString = (string) $uuid;
            try {
                $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuidString);
            } catch (DoesNotExistException) {
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_NOT_FOUND,
                ];
                continue;
            }

            // REQ-BULK-007 idempotency: already at target status.
            if ($dashboard->getPublicationStatus() === $publicationStatus
                && ($publicationStatus !== Dashboard::STATUS_SCHEDULED
                || $dashboard->getPublishAt() === $resolvedPublishAt)
            ) {
                $skipped++;
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_STATUS_ALREADY_MATCH,
                ];
                continue;
            }

            if ($dryRun === true) {
                $updated++;
                continue;
            }

            try {
                $this->applyStatusChange(
                    dashboard: $dashboard,
                    publicationStatus: $publicationStatus,
                    resolvedPublishAt: $resolvedPublishAt,
                    now: $now
                );
                $updated++;
            } catch (Throwable $t) {
                $this->logger->error(
                    message: 'BulkOperationService::bulkStatus failed for uuid',
                    context: ['uuid' => $uuidString, 'exception' => $t]
                );
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_TRANSACTION_FAILED,
                    'detail' => $t->getMessage(),
                ];
            }
        }//end foreach

        $payload = [
            $this->countKey(dryRun: $dryRun, real: 'updatedCount', preview: 'wouldUpdateCount') => $updated,
            $this->countKey(dryRun: $dryRun, real: 'skippedCount', preview: 'wouldSkipCount')   => $skipped,
            'errors'                                                                            => $errors,
            'dryRun'                                                                            => $dryRun,
        ];

        $this->emitAuditEvent(
            operation: self::OP_STATUS,
            dashboardCount: count($dashboardUuids),
            userId: $userId,
            durationMs: $this->elapsedMs(start: $start),
            dryRun: $dryRun,
            extra: ['publicationStatus' => $publicationStatus]
        );

        return $payload;
    }//end bulkStatus()

    /**
     * Re-index multiple dashboards for unified search (REQ-BULK-004).
     *
     * The unified-search integration is provided by a sibling capability;
     * this service touches the dashboard's `updated_at` to mark the row
     * dirty so any downstream search-indexer pipeline (cron / queue) will
     * pick it up on its next pass. Per-uuid failures are reported in
     * `errors` and the batch continues.
     *
     * @param string[] $dashboardUuids The dashboards to mark for reindex.
     * @param string   $userId         The acting NC user ID.
     * @param bool     $dryRun         When true, preview only.
     *
     * @return array<string, mixed> The result envelope.
     *
     * @throws InvalidArgumentException  When the request exceeds the cap.
     * @throws PermissionDeniedException When any UUID is unauthorised.
     *
     * @spec openspec/specs/dashboard-bulk-operations/spec.md
     */
    public function bulkReindex(
        array $dashboardUuids,
        string $userId,
        bool $dryRun=false
    ): array {
        $start = microtime(as_float: true);
        $this->assertPermissions(uuids: $dashboardUuids, userId: $userId);
        $this->assertWithinCap(uuids: $dashboardUuids);

        $reindexed = 0;
        $errors    = [];
        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');

        foreach ($dashboardUuids as $uuid) {
            $uuidString = (string) $uuid;
            try {
                $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuidString);
            } catch (DoesNotExistException) {
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_NOT_FOUND,
                ];
                continue;
            }

            if ($dryRun === true) {
                $reindexed++;
                continue;
            }

            try {
                // Mark the dashboard dirty so the unified-search provider
                // re-ingests it on the next pass. The provider lives in
                // a sibling capability; touching `updatedAt` is the
                // documented contract surface for "rebuild this row".
                $dashboard->setUpdatedAt($now);
                $this->dashboardMapper->update(entity: $dashboard);
                $reindexed++;
            } catch (Throwable $t) {
                $this->logger->error(
                    message: 'BulkOperationService::bulkReindex failed for uuid',
                    context: ['uuid' => $uuidString, 'exception' => $t]
                );
                $errors[] = [
                    'uuid'   => $uuidString,
                    'reason' => self::REASON_REINDEX_FAILED,
                    'detail' => $t->getMessage(),
                ];
            }
        }//end foreach

        $payload = [
            $this->countKey(dryRun: $dryRun, real: 'reindexedCount', preview: 'wouldReindexCount') => $reindexed,
            'errors'                                                                               => $errors,
            'dryRun'                                                                               => $dryRun,
        ];

        $this->emitAuditEvent(
            operation: self::OP_REINDEX,
            dashboardCount: count($dashboardUuids),
            userId: $userId,
            durationMs: $this->elapsedMs(start: $start),
            dryRun: $dryRun
        );

        return $payload;
    }//end bulkReindex()

    /**
     * Resolve the per-request size cap from app config, falling back to
     * the {@see self::DEFAULT_MAX_PER_REQUEST} when the configured value
     * is missing, zero, or negative (REQ-BULK-006 fallback scenario).
     *
     * @return int The effective cap.
     *
     * @spec openspec/specs/dashboard-bulk-operations/spec.md
     */
    public function getEffectiveCap(): int
    {
        $value = $this->appConfig->getValueInt(
            Application::APP_ID,
            self::CONFIG_KEY_MAX_PER_REQUEST,
            self::DEFAULT_MAX_PER_REQUEST
        );

        if ($value <= 0) {
            $this->logger->warning(
                message: 'Invalid bulk operation cap; falling back to default',
                context: ['raw' => (string) $value]
            );

            return self::DEFAULT_MAX_PER_REQUEST;
        }

        return $value;
    }//end getEffectiveCap()

    /**
     * REQ-BULK-011 — all-or-nothing permission pre-check.
     *
     * The permission pre-check has higher priority than the size cap
     * (REQ-BULK-011 scenario "Permission check happens before size
     * validation"). Non-admins are rejected wholesale; for admins, every
     * UUID is verified individually so a single unauthorised dashboard
     * fails the batch.
     *
     * @param string[] $uuids  The dashboard UUIDs in the batch.
     * @param string   $userId The acting NC user ID.
     *
     * @return void
     *
     * @throws PermissionDeniedException When the caller is not an NC
     *                                   admin or any UUID is unauthorised.
     */
    private function assertPermissions(array $uuids, string $userId): void
    {
        if ($this->groupManager->isAdmin(userId: $userId) === false) {
            throw new PermissionDeniedException(
                message: 'Administrator privileges required.',
                deniedUuids: []
            );
        }

        $denied = [];
        foreach ($uuids as $uuid) {
            $uuidString = (string) $uuid;
            try {
                $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuidString);
            } catch (DoesNotExistException) {
                // Missing rows are not a permission failure — they are
                // routed to the per-uuid `not_found`/`already_deleted`
                // handler downstream.
                continue;
            }

            $level = $this->permissionService->resolveAccessLevel(
                userId: $userId,
                dashboard: $dashboard
            );
            if ($level === null) {
                $denied[] = $uuidString;
            }
        }

        if ($denied !== []) {
            throw new PermissionDeniedException(
                message: 'Insufficient permissions on one or more dashboards.',
                deniedUuids: $denied
            );
        }
    }//end assertPermissions()

    /**
     * REQ-BULK-006 — enforce the per-request size cap.
     *
     * @param string[] $uuids The dashboard UUIDs.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the batch exceeds the cap.
     */
    private function assertWithinCap(array $uuids): void
    {
        $cap = $this->getEffectiveCap();
        if (count($uuids) > $cap) {
            throw new InvalidArgumentException(
                message: 'Request contains '.count($uuids).' dashboards; maximum is '.$cap.' (configured by admin)'
            );
        }
    }//end assertWithinCap()

    /**
     * Emit a single audit Activity event summarising the bulk operation
     * (REQ-BULK-009).
     *
     * Audit events are fan-out via {@see ActivityPublisher::publishGlobal()}
     * with a synthetic placeholder UUID — the dashboard count and
     * operation type are the diagnostically interesting fields. Any
     * Activity failure is swallowed by the publisher so an audit-log
     * problem never rolls back the bulk operation.
     *
     * @param string               $operation      The operation label.
     * @param int                  $dashboardCount The batch size.
     * @param string               $userId         The acting NC user ID.
     * @param int                  $durationMs     Operation duration.
     * @param bool                 $dryRun         True when this was a dry-run.
     * @param array<string, mixed> $extra          Additional payload fields.
     *
     * @return void
     */
    private function emitAuditEvent(
        string $operation,
        int $dashboardCount,
        string $userId,
        int $durationMs,
        bool $dryRun,
        array $extra=[]
    ): void {
        try {
            $this->activityPublisher->publish(
                type: Extension::EVENT_UPDATED,
                actorUserId: $userId,
                recipientUserId: $userId,
                dashboardUuid: 'bulk-'.$operation,
                dashboardName: 'Bulk '.$operation.' ('.$dashboardCount.' dashboards)',
                dashboardLink: '',
                extraParams: array_merge(
                    [
                        'bulkOperation'  => $operation,
                        'dashboardCount' => $dashboardCount,
                        'durationMs'     => $durationMs,
                        'dryRun'         => $dryRun,
                    ],
                    $extra
                )
            );
        } catch (Throwable $t) {
            // Defensive: ActivityPublisher already swallows; this is a
            // belt-and-braces guard.
            $this->logger->warning(
                message: 'BulkOperationService audit event failed',
                context: ['operation' => $operation, 'exception' => $t]
            );
        }//end try
    }//end emitAuditEvent()

    /**
     * Compute the elapsed time in milliseconds from a microtime(true)
     * start marker.
     *
     * @param float $start The start marker (`microtime(true)`).
     *
     * @return int The elapsed milliseconds (>=0).
     */
    private function elapsedMs(float $start): int
    {
        return (int) max(0, ((microtime(as_float: true) - $start) * 1000));
    }//end elapsedMs()

    /**
     * Parse and validate a future ISO-8601 timestamp for the `scheduled`
     * status. Mirrors {@see DashboardService::parseFuturePublishAt()} so
     * the bulk path enforces the same contract as the per-dashboard
     * publish/schedule path.
     *
     * @param string $publishAt The ISO-8601 timestamp.
     *
     * @return string The normalised `Y-m-d H:i:s` string.
     *
     * @throws InvalidArgumentException When the timestamp is empty,
     *                                  unparseable, or in the past.
     */
    private function parseFutureDate(string $publishAt): string
    {
        $trimmed = trim($publishAt);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                message: DashboardService::ERR_SCHEDULE_PAST_DATE
            );
        }

        try {
            $parsed = new DateTime($trimmed);
        } catch (\Exception) {
            throw new InvalidArgumentException(
                message: DashboardService::ERR_SCHEDULE_PAST_DATE
            );
        }

        if ($parsed <= new DateTime()) {
            throw new InvalidArgumentException(
                message: DashboardService::ERR_SCHEDULE_PAST_DATE
            );
        }

        return $parsed->format(format: 'Y-m-d H:i:s');
    }//end parseFutureDate()

    /**
     * Apply the per-dashboard publication-status mutation.
     *
     * Encapsulates the per-status field bookkeeping:
     * - PUBLISHED: stamps `publishedAt` on first publish, clears `publishAt`.
     * - DRAFT: clears `publishAt`.
     * - SCHEDULED: stores the parsed `publishAt`.
     *
     * @param Dashboard   $dashboard         The dashboard to mutate.
     * @param string      $publicationStatus The target status.
     * @param string|null $resolvedPublishAt The parsed publishAt (for
     *                                       STATUS_SCHEDULED).
     * @param string      $now               Current timestamp.
     *
     * @return void
     */
    private function applyStatusChange(
        Dashboard $dashboard,
        string $publicationStatus,
        ?string $resolvedPublishAt,
        string $now
    ): void {
        $dashboard->setPublicationStatus($publicationStatus);

        if ($publicationStatus === Dashboard::STATUS_PUBLISHED) {
            if ($dashboard->getPublishedAt() === null) {
                $dashboard->setPublishedAt($now);
            }

            $dashboard->setPublishAt(null);
        } else if ($publicationStatus === Dashboard::STATUS_DRAFT) {
            $dashboard->setPublishAt(null);
        } else {
            // STATUS_SCHEDULED.
            $dashboard->setPublishAt($resolvedPublishAt);
        }

        $dashboard->setUpdatedAt($now);
        $this->dashboardMapper->update(entity: $dashboard);
    }//end applyStatusChange()

    /**
     * Resolve the response counter key for the current run mode.
     *
     * The bulk endpoints expose two parallel counter naming schemes:
     * `deletedCount`/`movedCount`/etc. for real runs, `wouldDeleteCount`
     * /`wouldMoveCount`/etc. for dry runs. This helper centralises the
     * chooser so the per-operation `$payload` arrays stay free of inline
     * ternaries (the project's PHPCS rules disallow them).
     *
     * @param bool   $dryRun  True when the operation is a dry-run preview.
     * @param string $real    The counter name for real runs.
     * @param string $preview The counter name for dry-run previews.
     *
     * @return string The chosen key.
     */
    private function countKey(bool $dryRun, string $real, string $preview): string
    {
        if ($dryRun === true) {
            return $preview;
        }

        return $real;
    }//end countKey()
}//end class
