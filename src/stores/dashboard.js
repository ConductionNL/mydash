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
			// Update local state immediately for responsiveness
			this.widgetPlacements = placements

			// Debounced save to backend
			this.saving = true
			try {
				await api.updateDashboard(this.activeDashboard.id, {
					placements: placements.map(p => ({
						id: p.id,
						gridX: p.gridX,
						gridY: p.gridY,
						gridWidth: p.gridWidth,
						gridHeight: p.gridHeight,
					})),
				})
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
			try {
				const response = await api.updateWidgetPlacement(placementId, updates)
				const index = this.widgetPlacements.findIndex(p => p.id === placementId)
				if (index !== -1) {
					this.widgetPlacements[index] = response.data
				}
			} catch (error) {
				console.error('Failed to update widget placement:', error)
			}
		},
	},
})
