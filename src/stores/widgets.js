/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'

export const useWidgetStore = defineStore('widgets', {
	state: () => ({
		availableWidgets: [],
		widgetItems: {},
		loading: false,
	}),

	getters: {
		getWidgetById: (state) => (id) => {
			return state.availableWidgets.find(w => w.id === id)
		},

		getWidgetItems: (state) => (widgetId) => {
			return state.widgetItems[widgetId] || { items: [], loading: false }
		},
	},

	actions: {
		async loadAvailableWidgets() {
			this.loading = true
			try {
				const response = await api.getAvailableWidgets()
				this.availableWidgets = response.data
			} catch (error) {
				console.error('Failed to load available widgets:', error)
			} finally {
				this.loading = false
			}
		},

		async loadWidgetItems(widgetIds) {
			// Mark widgets as loading
			for (const id of widgetIds) {
				this.widgetItems[id] = { ...this.widgetItems[id], loading: true }
			}

			try {
				const response = await api.getWidgetItems(widgetIds)
				for (const [widgetId, data] of Object.entries(response.data)) {
					this.widgetItems[widgetId] = {
						items: data.items || [],
						emptyContentMessage: data.emptyContentMessage || '',
						halfEmptyContentMessage: data.halfEmptyContentMessage || '',
						loading: false,
					}
				}
			} catch (error) {
				console.error('Failed to load widget items:', error)
				for (const id of widgetIds) {
					this.widgetItems[id] = { ...this.widgetItems[id], loading: false }
				}
			}
		},

		async refreshWidgetItems(widgetId) {
			await this.loadWidgetItems([widgetId])
		},
	},
})
