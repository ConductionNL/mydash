/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
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
	},

	actions: {
		async loadDashboards() {
			this.loading = true
			try {
				const response = await api.getDashboards()
				this.dashboards = response.data

				// Load the active dashboard
				const activeResponse = await api.getActiveDashboard()
				if (activeResponse.data) {
					this.activeDashboard = activeResponse.data.dashboard
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
				await api.activateDashboard(dashboardId)
				const response = await api.getActiveDashboard()
				this.activeDashboard = response.data.dashboard
				this.widgetPlacements = response.data.placements || []
				this.permissionLevel = response.data.permissionLevel || 'full'
			} catch (error) {
				console.error('Failed to switch dashboard:', error)
			} finally {
				this.loading = false
			}
		},

		async createDashboard(name = 'My Dashboard') {
			this.loading = true
			try {
				const response = await api.createDashboard({ name })
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
