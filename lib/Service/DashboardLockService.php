<?php

/**
 * DashboardLockService
 *
 * Service for managing dashboard editing locks. Implements the
 * acquire / heartbeat / release / query / force-release workflow
 * defined by REQ-LOCK-001..008. Aligned with the proven source
 * `PageLockService` design (see openspec/changes/dashboard-locking/
 * design.md decisions D1..D6):
 *
 *  - 15-minute TTL computed from `updatedAt` (no stored expiresAt).
 *  - Ownership is by `userId` only — re-entrant for the same user
 *    so a second tab does NOT block the first.
 *  - Heartbeat is `PUT` on the same lock URL (not a `/heartbeat`
 *    sub-resource).
 *  - Admin override is `force-release` (clears the lock; the admin
 *    then acquires normally if they want to edit).
 *  - Stale rows are cleaned inline at the start of `getLockState()`
 *    and `acquireLock()` — no background sweeper required for
 *    correctness.
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
use OCA\MyDash\Db\DashboardLock;
use OCA\MyDash\Db\DashboardLockMapper;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\LockConflictException;
use OCA\MyDash\Exception\LockForbiddenException;
use OCA\MyDash\Exception\LockNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service for acquiring, refreshing, releasing and querying dashboard
 * editing locks.
 */
class DashboardLockService
{
    /**
     * Constructor
     *
     * @param DashboardLockMapper $lockMapper         The lock mapper.
     * @param DashboardMapper     $dashboardMapper    Used to verify the
     *                                                dashboard exists before
     *                                                a lock row is written.
     * @param IUserManager        $userManager        Resolves display names for
     *                                                new locks.
     * @param IGroupManager       $groupManager       Admin check for force-release.
     * @param LoggerInterface     $logger             PSR logger — used for the
     *                                                force-release audit trail
     *                                                (REQ-LOCK-006, design D4).
     * @param PermissionService   $permissionService  Permission resolver — used to
     *                                                verify the caller has at least
     *                                                view access before granting or
     *                                                extending a lock (C3 fix:
     *                                                REQ-LOCK-001 + REQ-PERM-001).
     */
    public function __construct(
        private readonly DashboardLockMapper $lockMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
        private readonly PermissionService $permissionService,
    ) {
    }//end __construct()

    /**
     * Acquire (or refresh) the editing lock on a dashboard.
     *
     * Re-entrant for the same user — calling `acquireLock` while the
     * caller already owns the lock returns the existing row with its
     * heartbeat bumped, instead of throwing a conflict (REQ-LOCK-001
     * "Same user with two browser tabs").
     *
     * Stale locks (whose `updatedAt + 15 min` is in the past) are
     * deleted inline before the conflict check, so an expired lock
     * never blocks a fresh acquire.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The acquiring user's ID.
     *
     * @return DashboardLock The active lock owned by `$userId`.
     *
     * @throws DoesNotExistException  When the dashboard UUID does not
     *                                resolve to an existing dashboard —
     *                                propagated unchanged so the
     *                                controller can map it to HTTP 404.
     * @throws LockConflictException  When another user already holds an
     *                                active lock on the dashboard.
     * @throws LockForbiddenException When the caller has no view access
     *                                to the dashboard (C3 fix).
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function acquireLock(
        string $dashboardUuid,
        string $userId
    ): DashboardLock {
        // Reject locks against a non-existent dashboard up front. Without
        // this guard the unique constraint on the lock table is satisfied
        // by any UUID, so `POST /api/dashboards/{garbage}/lock` returns
        // 200 with a brand-new orphaned row — silent acceptance of bogus
        // resources.
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);

        // C3 fix (REQ-LOCK-001 + REQ-PERM-001): a caller without at least
        // view access MUST NOT be allowed to acquire an edit lock — the
        // lock acts as a denial-of-service vector against any discovered UUID.
        $dashboardId = (int) $dashboard->getId();
        if ($this->permissionService->canViewDashboard(
            userId: $userId,
            dashboardId: $dashboardId
        ) === false) {
            throw new LockForbiddenException(
                message: 'You do not have access to this dashboard'
            );
        }

        // Inline cleanup so stale rows don't block the new acquire.
        $this->lockMapper->deleteExpiredForDashboard(
            dashboardUuid: $dashboardUuid
        );

        try {
            $existing = $this->lockMapper->findByDashboardUuid(
                dashboardUuid: $dashboardUuid
            );
        } catch (DoesNotExistException) {
            $existing = null;
        }

        if ($existing !== null) {
            if ($existing->getUserId() === $userId) {
                // Re-entrant refresh — bump heartbeat and return.
                $existing->setUpdatedAt($this->now());
                return $this->lockMapper->update(entity: $existing);
            }

            // Held by someone else — surface the conflict.
            throw new LockConflictException(
                message: 'Lock held by another user',
                existingLock: $existing,
            );
        }

        $now  = $this->now();
        $lock = new DashboardLock();
        $lock->setDashboardUuid($dashboardUuid);
        $lock->setUserId($userId);
        $lock->setDisplayName($this->resolveDisplayName(userId: $userId));
        $lock->setCreatedAt($now);
        $lock->setUpdatedAt($now);

        return $this->lockMapper->insert(entity: $lock);
    }//end acquireLock()

    /**
     * Refresh the lock by bumping `updatedAt` (the "heartbeat").
     *
     * Owner-only — a non-owner caller MUST be rejected with
     * `LockForbiddenException`. A heartbeat against an expired or
     * missing lock raises `LockNotFoundException` so the client's UI
     * can fall back to the "lock lost" path.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The calling user's ID.
     *
     * @return DashboardLock The refreshed lock.
     *
     * @throws LockNotFoundException  When no active lock exists.
     * @throws LockForbiddenException When the caller is not the owner, or
     *                                when the caller has no view access
     *                                (C3 fix).
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function heartbeat(
        string $dashboardUuid,
        string $userId
    ): DashboardLock {
        // C3 fix: verify the caller has at least view access before
        // allowing a heartbeat — prevents indefinite lock extension by
        // an attacker who discovered the UUID.
        try {
            $dashboard   = $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);
            $dashboardId = (int) $dashboard->getId();
        } catch (DoesNotExistException) {
            throw new LockNotFoundException(message: 'Lock not found; call acquire first');
        }

        if ($this->permissionService->canViewDashboard(
            userId: $userId,
            dashboardId: $dashboardId
        ) === false) {
            throw new LockForbiddenException(
                message: 'You do not have access to this dashboard'
            );
        }

        $existing = $this->lockMapper->findActive(
            dashboardUuid: $dashboardUuid
        );

        if ($existing === null) {
            throw new LockNotFoundException(
                message: 'Lock not found; call acquire first'
            );
        }

        if ($existing->getUserId() !== $userId) {
            throw new LockForbiddenException(
                message: 'Only the lock owner can extend the lease'
            );
        }

        $existing->setUpdatedAt($this->now());
        return $this->lockMapper->update(entity: $existing);
    }//end heartbeat()

    /**
     * Release the editing lock on a dashboard.
     *
     * Owner-only by default. Admins MAY release any user's lock when
     * `$allowAdminOverride` is `true` — this is wired by the
     * controller for the standard `DELETE` verb so admins can clear
     * stuck sessions without going through the dedicated
     * `force-release` endpoint.
     *
     * Idempotent: releasing a non-existent lock is a no-op (returns
     * silently). REQ-LOCK-003 "Release non-existent lock".
     *
     * @param string $dashboardUuid      The dashboard UUID.
     * @param string $userId             The calling user's ID.
     * @param bool   $allowAdminOverride When true, an admin caller may
     *                                   release any user's lock.
     *
     * @return void
     *
     * @throws LockForbiddenException When the caller is neither the
     *                                owner nor an admin (when allowed).
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function releaseLock(
        string $dashboardUuid,
        string $userId,
        bool $allowAdminOverride=true
    ): void {
        $existing = $this->lockMapper->findActive(
            dashboardUuid: $dashboardUuid
        );

        if ($existing === null) {
            // Idempotent — nothing to release.
            return;
        }

        if ($existing->getUserId() !== $userId) {
            if ($allowAdminOverride === false
                || $this->groupManager->isAdmin(userId: $userId) === false
            ) {
                throw new LockForbiddenException(
                    message: 'Only the lock owner or an admin can release this lock'
                );
            }
        }

        $this->lockMapper->delete(entity: $existing);
    }//end releaseLock()

    /**
     * Get the current active lock state for a dashboard.
     *
     * Performs inline cleanup before the read so stale rows are never
     * leaked to clients. Returns `null` when no active lock exists.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardLock|null The active lock or null.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function getLockState(string $dashboardUuid): ?DashboardLock
    {
        // Inline cleanup so the next read is never polluted by a stale
        // row. Cheap on the indexed `(dashboard_uuid, updated_at)`
        // path even when the row is fresh (no-op delete).
        $this->lockMapper->deleteExpiredForDashboard(
            dashboardUuid: $dashboardUuid
        );

        return $this->lockMapper->findActive(
            dashboardUuid: $dashboardUuid
        );
    }//end getLockState()

    /**
     * Admin-only: release the lock for whoever holds it.
     *
     * After this call the dashboard is in an unlocked state — the
     * admin may then call `acquireLock()` if they want to take
     * ownership themselves (REQ-LOCK-006 design D4).
     *
     * Idempotent: if no lock exists the call still succeeds and is
     * still logged (so admins always see their action recorded).
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $adminUserId   The admin's user ID.
     *
     * @return void
     *
     * @throws LockForbiddenException When the caller is not an admin.
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function forceRelease(
        string $dashboardUuid,
        string $adminUserId
    ): void {
        if ($this->groupManager->isAdmin(userId: $adminUserId) === false) {
            throw new LockForbiddenException(
                message: 'Only an administrator may force-release a lock'
            );
        }

        $existing      = $this->lockMapper->findActive(
            dashboardUuid: $dashboardUuid
        );
        $previousOwner = null;
        if ($existing !== null) {
            $previousOwner = (string) $existing->getUserId();
            $this->lockMapper->delete(entity: $existing);
        }

        $this->logger->info(
            message: sprintf(
                'mydash.dashboard_lock.force_release admin=%s dashboard=%s previous_owner=%s',
                $adminUserId,
                $dashboardUuid,
                ($previousOwner ?? 'none'),
            )
        );
    }//end forceRelease()

    /**
     * Cascade-delete the lock when its dashboard is deleted (REQ-LOCK-008).
     *
     * Always succeeds — there is no permission check here because the
     * caller (DashboardService::delete()) has already authorised the
     * dashboard removal.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted (0 or 1).
      *

      * @spec openspec/specs/dashboard-locking/spec.md

      */
    public function cascadeDelete(string $dashboardUuid): int
    {
        return $this->lockMapper->deleteByDashboardUuid(
            dashboardUuid: $dashboardUuid
        );
    }//end cascadeDelete()

    /**
     * Resolve the display name for a user via IUserManager.
     *
     * Falls back to the user ID when the display name is empty or the
     * user is unknown to Nextcloud (defensive — IGroupManager could
     * disagree with IUserManager during session edge cases).
     *
     * @param string $userId The user ID.
     *
     * @return string The display name.
     */
    private function resolveDisplayName(string $userId): string
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return $userId;
        }

        $displayName = $user->getDisplayName();
        if ($displayName === '') {
            return $userId;
        }

        return $displayName;
    }//end resolveDisplayName()

    /**
     * Format the current time as `Y-m-d H:i:s` for `created_at` and
     * `updated_at` writes.
     *
     * @return string The formatted timestamp.
     */
    private function now(): string
    {
        return (new DateTime())->format(format: 'Y-m-d H:i:s');
    }//end now()
}//end class
