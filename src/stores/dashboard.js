/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import {
	placeNewWidget,
	DEFAULT_W,
	DEFAULT_H,
} from '../composables/useGridManager.js'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		// `dashboards` carries every dashboard visible to the user
		// (REQ-DASH-013). Each row carries a `source` field set by the
		// `/api/dashboards/visible` endpoint: `'user' | 'group' | 'default'`.
		// The frontend uses the source to route subsequent edit calls
		// to the correct backend endpoint (personal vs group-scoped).
		dashboards: [],
		activeDashboard: null,
		widgetPlacements: [],
		permissionLevel: 'full',
		loading: false,
		saving: false,
	}),

	getters: {
		activeDashboardId: (state) => state.activeDashboard?.id,

		getPlacementById: (state) => (id) => {
			return state.widgetPlacements.find(p => p.id === id)
		},

		compulsoryPlacements: (state) => {
			return state.widgetPlacements.filter(p => p.isCompulsory)
		},

		// Personal (`source === 'user'`) dashboards. Backed by the
		// `/api/dashboards/visible` payload (REQ-DASH-013).
		userDashboards: (state) => {
			return state.dashboards.filter(d => d.source === 'user')
		},

		// Group-matching shared dashboards (`source === 'group'`)
		// — REQ-DASH-014.
		groupSharedDashboards: (state) => {
			return state.dashboards.filter(d => d.source === 'group')
		},

		// Default-group shared dashboards (`source === 'default'`)
		// — REQ-DASH-012.
		defaultGroupDashboards: (state) => {
			return state.dashboards.filter(d => d.source === 'default')
		},
	},

	actions: {
		async loadDashboards() {
			this.loading = true
			try {
				// REQ-DASH-013: prefer the `/visible` endpoint so the store
				// receives the source-tagged union of personal + group +
				// default-group dashboards. Older clients that only know
				// `/api/dashboards` keep working server-side, but the
				// listing UI uses the unioned source of truth.
				let response
				try {
					response = await api.getVisibleDashboards()
				} catch (visibleError) {
					console.warn('Falling back to /api/dashboards (visible endpoint failed):', visibleError)
					response = await api.getDashboards()
					// Tag legacy payloads as user-scope so getters still work.
					response.data = (response.data || []).map(d => ({
						...d,
						source: d.source || 'user',
					}))
				}
				this.dashboards = response.data || []

				// Load the active dashboard
				const activeResponse = await api.getActiveDashboard()
				if (activeResponse.data) {
					this.activeDashboard = {
						...activeResponse.data.dashboard,
						// getActive only returns the user's own dashboards.
						isOwner: true,
						sharedBy: null,
					}
					this.widgetPlacements = activeResponse.data.placements || []
					this.permissionLevel = activeResponse.data.permissionLevel || 'full'
				}
			} catch (error) {
				console.error('Failed to load dashboards:', error)
			} finally {
				this.loading = false
			}
		},

		async switchDashboard(dashboardId) {
			this.loading = true
			try {
				const target = this.dashboards.find(d => d.id === dashboardId)
				const isOwned = target?.isOwner !== false

				if (isOwned) {
					// Persist the active flag for owned dashboards.
					await api.activateDashboard(dashboardId)
				}

				// Always load full dashboard data via the by-id endpoint;
				// it returns placements + the user's effective permission
				// level for both owned and shared dashboards.
				const response = await api.getDashboardById(dashboardId)
				this.activeDashboard = {
					...response.data.dashboard,
					isOwner: response.data.isOwner,
					sharedBy: response.data.sharedBy,
				}
				this.widgetPlacements = response.data.placements || []
				this.permissionLevel = response.data.permissionLevel || 'full'
			} catch (error) {
				console.error('Failed to switch dashboard:', error)
			} finally {
				this.loading = false
			}
		},

		async createDashboard(payload = 'My Dashboard') {
			// Accept either a plain name string or an object with
			// name/description/icon (legacy callers may pass a string).
			const data = typeof payload === 'string'
				? { name: payload }
				: {
					name: payload.name || 'My Dashboard',
					description: payload.description,
					// Optional registry key from the `dashboard-icons`
					// capability — null/undefined skips the field server-side.
					icon: payload.icon ?? null,
				}
			this.loading = true
			try {
				const response = await api.createDashboard(data)
				this.dashboards.push(response.data.dashboard)
				this.activeDashboard = response.data.dashboard
				this.widgetPlacements = []
			} catch (error) {
				console.error('Failed to create dashboard:', error)
			} finally {
				this.loading = false
			}
		},

		async updatePlacements(placements) {
			console.log('[DashboardStore] updatePlacements called, count:', placements.length)

			// Update local state immediately for responsiveness
			this.widgetPlacements = placements

			// Debounced save to backend
			this.saving = true
			try {
				const placementsData = placements.map(p => ({
					id: p.id,
					gridX: p.gridX,
					gridY: p.gridY,
					gridWidth: p.gridWidth,
					gridHeight: p.gridHeight,
				}))
				console.log('[DashboardStore] Sending placements to API:', JSON.stringify(placementsData, null, 2))

				await api.updateDashboard(this.activeDashboard.id, {
					placements: placementsData,
				})
				console.log('[DashboardStore] Successfully saved placements')
			} catch (error) {
				console.error('Failed to save placements:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Add a widget to the active dashboard. Routes through
		 * `placeNewWidget` (REQ-GRID-014) so the placement algorithm
		 * (REQ-GRID-006: try autoPosition, fall back to top-left + push
		 * down) is the single source of truth for "where does this go?".
		 *
		 * Position-only callers (e.g. legacy code that passed a fully
		 * computed `{x, y, w, h}`) MAY still supply a `position` object;
		 * if it includes both `x` AND `y` we honour the explicit choice
		 * and skip the auto-placement path. Otherwise we delegate to
		 * `placeNewWidget` and apply any push-down side effects via the
		 * existing batch-update path (REQ-WDG-008, debounce 300 ms).
		 *
		 * @param {string|object} widgetId widget identifier OR a `{type, content}` payload from AddWidgetModal
		 * @param {object|null} [position] explicit `{x, y, w, h}` (skips auto-placement) or partial spec to seed the helper
		 */
		async addWidgetToDashboard(widgetId, position = null) {
			try {
				const placement = (position && Number.isFinite(position.x) && Number.isFinite(position.y))
					? {
						x: position.x,
						y: position.y,
						w: position.w ?? DEFAULT_W,
						h: position.h ?? DEFAULT_H,
						pushed: [],
					}
					: placeNewWidget(
						{ w: position?.w, h: position?.h },
						this.widgetPlacements,
						{ gridColumns: this.activeDashboard?.gridColumns },
					)

				const response = await api.addWidget(this.activeDashboard.id, {
					widgetId,
					gridX: placement.x,
					gridY: placement.y,
					gridWidth: placement.w,
					gridHeight: placement.h,
				})
				this.widgetPlacements.push(response.data)

				if (placement.pushed.length > 0) {
					await this.applyPushedPlacements(placement.pushed)
				}
			} catch (error) {
				console.error('Failed to add widget:', error)
			}
		},

		/**
		 * Add a tile to the active dashboard. Tiles default to a smaller
		 * 2×2 footprint than regular widgets but still funnel through
		 * `placeNewWidget` so the auto-placement + fallback algorithm is
		 * applied consistently (REQ-GRID-006 / REQ-GRID-014).
		 *
		 * @param {object} tileData tile payload (title/icon/colours/link)
		 * @param {object|null} [position] explicit `{x, y, w, h}` (skips auto-placement) or partial spec to seed the helper
		 */
		async addTileToDashboard(tileData, position = null) {
			try {
				const placement = (position && Number.isFinite(position.x) && Number.isFinite(position.y))
					? {
						x: position.x,
						y: position.y,
						w: position.w ?? 2,
						h: position.h ?? 2,
						pushed: [],
					}
					: placeNewWidget(
						{ w: position?.w ?? 2, h: position?.h ?? 2 },
						this.widgetPlacements,
						{ gridColumns: this.activeDashboard?.gridColumns },
					)

				const response = await api.addTile(this.activeDashboard.id, {
					...tileData,
					gridX: placement.x,
					gridY: placement.y,
					gridWidth: placement.w,
					gridHeight: placement.h,
				})
				this.widgetPlacements.push(response.data)

				if (placement.pushed.length > 0) {
					await this.applyPushedPlacements(placement.pushed)
				}
			} catch (error) {
				console.error('Failed to add tile:', error)
				throw error
			}
		},

		/**
		 * Apply the push-down side effects produced by `placeNewWidget`
		 * via the existing batch-update path (REQ-GRID-005). The new
		 * `gridY` values are merged into the in-memory placement list and
		 * the whole list is sent in a single round-trip — preserves the
		 * REQ-WDG-008 single-batch contract (no per-widget PUT storm) and
		 * inherits the 300 ms debounce already in `updatePlacements`.
		 *
		 * @param {Array<{id: any, gridY: number}>} pushed list of push-down side effects from `placeNewWidget`
		 */
		async applyPushedPlacements(pushed) {
			if (!pushed || pushed.length === 0) {
				return
			}
			const pushIndex = new Map(pushed.map(p => [String(p.id), p.gridY]))
			const merged = this.widgetPlacements.map(p => {
				const newY = pushIndex.get(String(p.id))
				if (newY !== undefined) {
					return { ...p, gridY: newY }
				}
				return p
			})
			await this.updatePlacements(merged)
		},

		async removeWidgetFromDashboard(placementId) {
			const placement = this.getPlacementById(placementId)
			if (placement?.isCompulsory && this.permissionLevel !== 'full') {
				console.warn('Cannot remove compulsory widget')
				return
			}

			try {
				await api.removeWidget(placementId)
				this.widgetPlacements = this.widgetPlacements.filter(p => p.id !== placementId)
			} catch (error) {
				console.error('Failed to remove widget:', error)
			}
		},

		/**
		 * Promote a group-shared dashboard to the group's default
		 * (REQ-DASH-015). Optimistically flips `isDefault` to 1 on the
		 * target row and to 0 on every other row in the same group, then
		 * calls the backend. On 4xx/5xx the snapshot is restored so the
		 * UI never lies.
		 *
		 * @param {string} groupId The dashboard's group id.
		 * @param {string} uuid The target dashboard's uuid.
		 * @return {Promise<void>}
		 */
		async setGroupDashboardDefault(groupId, uuid) {
			// Snapshot the affected rows so we can roll back on failure.
			const snapshot = this.dashboards
				.filter(d => d.groupId === groupId && d.source !== 'user')
				.map(d => ({ id: d.id, uuid: d.uuid, isDefault: d.isDefault }))

			// Optimistic update: target → 1, every other row in group → 0.
			this.dashboards = this.dashboards.map(d => {
				if (d.groupId !== groupId || d.source === 'user') {
					return d
				}
				return { ...d, isDefault: d.uuid === uuid ? 1 : 0 }
			})

			try {
				await api.setGroupDashboardDefault(groupId, uuid)
			} catch (error) {
				// Roll back the snapshot — restore every flipped row.
				this.dashboards = this.dashboards.map(d => {
					const prev = snapshot.find(s => s.uuid === d.uuid)
					if (prev === undefined) {
						return d
					}
					return { ...d, isDefault: prev.isDefault }
				})
				console.error('Failed to set group default dashboard:', error)
				throw error
			}
		},

		async updateWidgetPlacement(placementId, updates) {
			console.log('[DashboardStore] updateWidgetPlacement called:', JSON.stringify({ placementId, updates }, null, 2))
			try {
				const response = await api.updateWidgetPlacement(placementId, updates)
				console.log('[DashboardStore] API response:', JSON.stringify(response.data, null, 2))

				const index = this.widgetPlacements.findIndex(p => p.id === placementId)
				console.log('[DashboardStore] Found placement at index:', index)

				if (index !== -1) {
				// Use splice for reactive update.
					this.widgetPlacements.splice(index, 1, response.data)
					console.log('[DashboardStore] Updated placement:', JSON.stringify(this.widgetPlacements[index], null, 2))
				}
			} catch (error) {
				console.error('Failed to update widget placement:', error)
				console.error('Error details:', error.response?.data)
			}
		},
	},
})
