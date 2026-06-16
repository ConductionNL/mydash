/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * useDashboardLock — Vue 2 composable that wraps the
 * acquire / heartbeat / release lifecycle defined by REQ-LOCK-001..008.
 *
 * Usage from the dashboard edit view:
 *
 *     const { acquire, release, lock, error, conflict } = useDashboardLock(uuid)
 *     onMounted(acquire)
 *     onBeforeUnmount(release)
 *
 * The composable starts a 60-second heartbeat timer the moment a lock
 * is acquired and stops it on release / unmount. The 60-second cadence
 * gives a 15× safety margin against the 15-minute server-side TTL —
 * matched to the source app PageLockService design (D6 in
 * `openspec/changes/dashboard-locking/design.md`).
 *
 * Conflicts (HTTP 409) are surfaced via the reactive `conflict` ref
 * containing the existing lock object; the caller is responsible for
 * showing a "{displayName} is editing" banner and switching the view
 * to read-only mode.
 *
 * Network errors during the heartbeat are silently retried on the next
 * tick — they do NOT trigger a forced release locally because that
 * would defeat the TTL safety margin. When the lock is genuinely lost
 * (HTTP 404 / 403 from heartbeat), the composable stops the timer and
 * surfaces the loss via the `lostLock` ref so the caller can warn the
 * user and / or attempt a re-acquire.
 */

import { ref, onBeforeUnmount } from 'vue'

import { api } from '../services/api.js'

/**
 * Heartbeat cadence in milliseconds (60 seconds).
 *
 * @type {number}
 */
const HEARTBEAT_INTERVAL_MS = 60 * 1000

/**
 * Build a lock-management composable scoped to a single dashboard UUID.
 *
 * @param {string} dashboardUuid The dashboard UUID.
 * @return {{
 *   lock: import('vue').Ref<object|null>,
 *   conflict: import('vue').Ref<object|null>,
 *   error: import('vue').Ref<Error|null>,
 *   lostLock: import('vue').Ref<boolean>,
 *   acquire: () => Promise<boolean>,
 *   release: () => Promise<void>,
 *   refresh: () => Promise<void>,
 * }} The composable handle.
 */
/** @spec openspec/specs/dashboard-locking/spec.md */
export function useDashboardLock(dashboardUuid) {
	const lock = ref(null)
	const conflict = ref(null)
	const error = ref(null)
	const lostLock = ref(false)

	let heartbeatTimer = null

	/**
	 * Start the heartbeat timer (idempotent — safe to call repeatedly).
	 *
	 * @return {void}
	 */
	function startHeartbeat() {
		if (heartbeatTimer !== null) {
			return
		}
		heartbeatTimer = window.setInterval(async () => {
			try {
				const response = await api.heartbeatDashboardLock(dashboardUuid)
				lock.value = response.data
				lostLock.value = false
			} catch (err) {
				const status = err && err.response && err.response.status
				if (status === 404 || status === 403) {
					stopHeartbeat()
					lostLock.value = true
					lock.value = null
				}
				// Network blips: leave the timer running — the next tick
				// re-tries automatically. The 15× TTL margin protects us.
			}
		}, HEARTBEAT_INTERVAL_MS)
	}

	/**
	 * Stop the heartbeat timer (idempotent).
	 *
	 * @return {void}
	 */
	function stopHeartbeat() {
		if (heartbeatTimer !== null) {
			window.clearInterval(heartbeatTimer)
			heartbeatTimer = null
		}
	}

	/**
	 * Attempt to acquire (or re-acquire) the lock.
	 *
	 * @return {Promise<boolean>} True when the lock is held by the caller.
	 */
	async function acquire() {
		conflict.value = null
		error.value = null
		try {
			const response = await api.acquireDashboardLock(dashboardUuid)
			lock.value = response.data
			lostLock.value = false
			startHeartbeat()
			return true
		} catch (err) {
			const status = err && err.response && err.response.status
			if (status === 409) {
				conflict.value = (err.response.data && err.response.data.lock) || null
			} else {
				error.value = err
			}
			return false
		}
	}

	/**
	 * Release the lock and stop the heartbeat. Best-effort — failures
	 * are swallowed because the server-side TTL guarantees recovery.
	 *
	 * @return {Promise<void>}
	 */
	async function release() {
		stopHeartbeat()
		try {
			await api.releaseDashboardLock(dashboardUuid)
		} catch {
			// Best effort — lock will auto-expire after 15 min.
		}
		lock.value = null
	}

	/**
	 * Re-fetch the current lock state without acquiring.
	 *
	 * @return {Promise<void>}
	 */
	async function refresh() {
		try {
			const response = await api.getDashboardLock(dashboardUuid)
			lock.value = response.data
		} catch (err) {
			const status = err && err.response && err.response.status
			if (status === 404) {
				lock.value = null
			} else {
				error.value = err
			}
		}
	}

	onBeforeUnmount(() => {
		stopHeartbeat()
	})

	return {
		lock,
		conflict,
		error,
		lostLock,
		acquire,
		release,
		refresh,
	}
}
