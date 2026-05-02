/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

import { api } from '../services/api.js'

/**
 * Stable backend error code returned by `POST /api/dashboard` when the admin
 * setting `allow_user_dashboards` is `false` (REQ-ASET-003). Surfaced as a
 * localised toast so the UI stays coherent even when the user reaches the
 * endpoint via a stale-cached affordance or a direct API call.
 *
 * @type {string}
 */
const ERR_PERSONAL_DASHBOARDS_DISABLED = 'personal_dashboards_disabled'

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
			// Accept either a plain name string or an object with name/description
			// (legacy callers may pass a string).
			const data = typeof payload === 'string'
				? { name: payload }
				: { name: payload.name || 'My Dashboard', description: payload.description }
			this.loading = true
			try {
				const response = await api.createDashboard(data)
				this.dashboards.push(response.data.dashboard)
				this.activeDashboard = response.data.dashboard
				this.widgetPlacements = []
			} catch (error) {
				// REQ-ASET-003 (extended): when the backend returns the
				// stable `personal_dashboards_disabled` envelope, surface
				// a localised toast — the UI may have offered a stale
				// affordance or the call may bypass the UI altogether.
				if (error?.response?.status === 403
					&& error?.response?.data?.error === ERR_PERSONAL_DASHBOARDS_DISABLED) {
					showError(t('mydash', 'Personal dashboards are not enabled by your administrator'))
				}
				console.error('Failed to create dashboard:', error)
				throw error
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

		async addWidgetToDashboard(widgetId, position = null) {
			try {
				const response = await api.addWidget(this.activeDashboard.id, {
					widgetId,
					gridX: position?.x ?? 0,
					gridY: position?.y ?? 0,
					gridWidth: position?.w ?? 4,
					gridHeight: position?.h ?? 4,
				})
				this.widgetPlacements.push(response.data)
			} catch (error) {
				console.error('Failed to add widget:', error)
			}
		},

		async addTileToDashboard(tileData, position = null) {
			try {
				const response = await api.addTile(this.activeDashboard.id, {
					...tileData,
					gridX: position?.x ?? 0,
					gridY: position?.y ?? 0,
					gridWidth: position?.w ?? 2,
					gridHeight: position?.h ?? 2,
				})
				this.widgetPlacements.push(response.data)
			} catch (error) {
				console.error('Failed to add tile:', error)
				throw error
			}
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
