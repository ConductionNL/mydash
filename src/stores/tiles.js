/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'

export const useTileStore = defineStore('tiles', {
	state: () => ({
		tiles: [],
		loading: false,
	}),

	actions: {
		async loadTiles() {
			this.loading = true
			try {
				const response = await api.getTiles()
				this.tiles = response.data
			} catch (error) {
				console.error('Failed to load tiles:', error)
			} finally {
				this.loading = false
			}
		},

		async createTile(tileData) {
			try {
				const response = await api.createTile(tileData)
				this.tiles.push(response.data)
				return response.data
			} catch (error) {
				console.error('Failed to create tile:', error)
				throw error
			}
		},

		async updateTile(id, tileData) {
			try {
				const response = await api.updateTile(id, tileData)
				const index = this.tiles.findIndex(t => t.id === id)
				if (index !== -1) {
					this.tiles[index] = response.data
				}
				return response.data
			} catch (error) {
				console.error('Failed to update tile:', error)
				throw error
			}
		},

		async deleteTile(id) {
			try {
				await api.deleteTile(id)
				this.tiles = this.tiles.filter(t => t.id !== id)
			} catch (error) {
				console.error('Failed to delete tile:', error)
				throw error
			}
		},
	},
})
