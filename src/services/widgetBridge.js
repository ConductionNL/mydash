/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-1
 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-2
 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-3
 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-4
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/legacy-widget-bridge/spec.md#req-lwb-005
 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/legacy-widget-bridge/spec.md#req-lwb-006
 */

/**
 * Bridge for legacy Nextcloud widgets that use the callback registration pattern
 * (window.OCA.Dashboard.register)
 */
class WidgetBridge {

	constructor() {
		this.widgetCallbacks = new Map()
		this.statusCallbacks = new Map()
		this.interceptRegistration()
	}

	/**
	 * Intercept the global OCA.Dashboard.register calls
	 * Legacy widgets call this to register their rendering callback
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-1
	 */
	interceptRegistration() {
		// Ensure OCA and OCA.Dashboard exist
		window.OCA = window.OCA || {}
		window.OCA.Dashboard = window.OCA.Dashboard || {}

		// Store original methods if they exist
		const originalRegister = window.OCA.Dashboard.register
		const originalRegisterStatus = window.OCA.Dashboard.registerStatus

		// Override register method
		window.OCA.Dashboard.register = (appId, callback) => {
			console.debug('MyDash: Widget registered via callback:', appId)
			this.widgetCallbacks.set(appId, callback)

			// Also call original if it exists (for compatibility)
			if (originalRegister) {
				originalRegister(appId, callback)
			}
		}

		// Override registerStatus method
		window.OCA.Dashboard.registerStatus = (appId, callback) => {
			console.debug('MyDash: Status widget registered:', appId)
			this.statusCallbacks.set(appId, callback)

			// Also call original if it exists
			if (originalRegisterStatus) {
				originalRegisterStatus(appId, callback)
			}
		}
	}

	/**
	 * Mount a legacy widget into a container element
	 *
	 * @param {string} widgetId - The widget ID (appId)
	 * @param {HTMLElement} container - The DOM element to mount into
	 * @param {object} widgetData - The widget metadata (optional)
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-2
	 */
	mountWidget(widgetId, container, widgetData = {}) {
		console.log('[WidgetBridge] mountWidget called for:', widgetId)
		console.log('[WidgetBridge] Available callbacks:', Array.from(this.widgetCallbacks.keys()))

		const callback = this.widgetCallbacks.get(widgetId)

		if (callback && typeof callback === 'function') {
			try {
				// Clear container first
				container.innerHTML = ''
				console.log('[WidgetBridge] Calling widget callback for:', widgetId)

				// Call the widget's callback with the container and widget data
				// Some widgets expect a second parameter with widget metadata
				callback(container, { widget: widgetData })
				console.log('[WidgetBridge] Mounted legacy widget:', widgetId)
				console.log('[WidgetBridge] Container after mount:', container.innerHTML.substring(0, 200))
			} catch (error) {
				console.error('[WidgetBridge] Error mounting legacy widget:', widgetId, error)
			}
		} else {
			console.warn('[WidgetBridge] No callback found for widget:', widgetId)
			console.log('[WidgetBridge] Callback type:', typeof callback)
		}
	}

	/**
	 * Mount a status widget into a container element
	 *
	 * @param {string} widgetId - The status widget ID
	 * @param {HTMLElement} container - The DOM element to mount into
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-3
	 */
	mountStatusWidget(widgetId, container) {
		const callback = this.statusCallbacks.get(widgetId)

		if (callback && typeof callback === 'function') {
			try {
				container.innerHTML = ''
				callback(container)
				console.debug('MyDash: Mounted status widget:', widgetId)
			} catch (error) {
				console.error('MyDash: Error mounting status widget:', widgetId, error)
			}
		}
	}

	/**
	 * Check if a widget has been registered via callback
	 *
	 * @param {string} widgetId - The widget ID
	 * @return {boolean}
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-4
	 */
	hasWidgetCallback(widgetId) {
		return this.widgetCallbacks.has(widgetId)
	}

	/**
	 * Get all registered widget IDs
	 *
	 * @return {string[]}
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/tasks.md#task-4
	 */
	getRegisteredWidgetIds() {
		return Array.from(this.widgetCallbacks.keys())
	}

	/**
	 * Poll until a callback is registered for the given widgetId or the poll is
	 * exhausted / aborted. Resolves `true` immediately (no setInterval) if the
	 * callback is already registered. Internally uses `hasWidgetCallback` as the
	 * single source of truth (REQ-LWB-006).
	 *
	 * @param {string} widgetId - The widget ID to watch
	 * @param {object} [options] - Optional configuration
	 * @param {number} [options.intervalMs=200] - Milliseconds between checks
	 * @param {number} [options.maxRetries=15] - Maximum number of interval ticks
	 * @param {AbortSignal} [options.signal] - AbortController signal for cancellation
	 * @return {Promise<boolean>} Resolves true if registered, false on timeout or abort
	 *
	 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/legacy-widget-bridge/spec.md#req-lwb-005
	 * @spec openspec/changes/nc-dashboard-widget-proxy/specs/legacy-widget-bridge/spec.md#req-lwb-006
	 */
	pollForCallback(widgetId, options = {}) {
		const intervalMs = options.intervalMs !== undefined ? options.intervalMs : 200
		const maxRetries = options.maxRetries !== undefined ? options.maxRetries : 15
		const signal = options.signal || null

		// Synchronous fast-path: already registered, resolve immediately (REQ-LWB-005)
		if (this.hasWidgetCallback(widgetId)) {
			return Promise.resolve(true)
		}

		return new Promise((resolve) => {
			let retries = 0
			let timerId = null

			const cleanup = () => {
				if (timerId !== null) {
					clearInterval(timerId)
					timerId = null
				}
			}

			const abort = () => {
				cleanup()
				resolve(false)
			}

			// Register abort handler before starting the interval
			if (signal) {
				if (signal.aborted) {
					resolve(false)
					return
				}
				signal.addEventListener('abort', abort, { once: true })
			}

			timerId = setInterval(() => {
				retries++

				if (this.hasWidgetCallback(widgetId)) {
					if (signal) {
						signal.removeEventListener('abort', abort)
					}
					cleanup()
					resolve(true)
					return
				}

				if (retries >= maxRetries) {
					if (signal) {
						signal.removeEventListener('abort', abort)
					}
					cleanup()
					resolve(false)
				}
			}, intervalMs)
		})
	}

}

// Export singleton instance
export const widgetBridge = new WidgetBridge()
