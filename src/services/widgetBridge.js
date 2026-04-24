/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-1
 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-2
 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-3
 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-4
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
	 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-1
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
	 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-2
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
	 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-3
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
	 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-4
	 */
	hasWidgetCallback(widgetId) {
		return this.widgetCallbacks.has(widgetId)
	}

	/**
	 * Get all registered widget IDs
	 *
	 * @return {string[]}
	 *
	 * @spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-4
	 */
	getRegisteredWidgetIds() {
		return Array.from(this.widgetCallbacks.keys())
	}

}

// Export singleton instance
export const widgetBridge = new WidgetBridge()
