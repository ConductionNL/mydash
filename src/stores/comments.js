/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pinia store for the dashboard-comments capability (REQ-CMNT-001..009).
 *
 * The store keeps comments keyed by dashboard UUID so that opening
 * multiple dashboards in sequence does not pay the API cost twice.
 * Each entry shape is `{enabled: boolean, comments: array}` mirroring
 * the GET /api/dashboards/{uuid}/comments envelope.
 */

import { defineStore } from 'pinia'

import { api } from '../services/api.js'

export const useCommentsStore = defineStore('comments', {
	state: () => ({
		// Map keyed by dashboard UUID — `{enabled, comments}` per dashboard.
		byDashboard: {},
		// Map keyed by dashboard UUID — `boolean` loading flag per dashboard.
		loading: {},
		// Last error per dashboard, if any.
		errors: {},
	}),

	getters: {
		/**
		 * Whether the comments thread for `uuid` has been loaded at least
		 * once. Components use this to decide between "loading" and
		 * "empty thread" states.
		 *
		 * @param {object} state Pinia state.
		 * @return {(uuid: string) => boolean} Loaded predicate.
		 */
		isLoaded: (state) => (uuid) => {
			return state.byDashboard[uuid] !== undefined
		},

		/**
		 * Get the cached `{enabled, comments}` envelope for a dashboard.
		 *
		 * @param {object} state Pinia state.
		 * @return {(uuid: string) => {enabled: boolean, comments: Array}} Envelope.
		 */
		threadFor: (state) => (uuid) => {
			return state.byDashboard[uuid] ?? { enabled: true, comments: [] }
		},
	},

	actions: {
		/**
		 * Load the comment thread for a dashboard (REQ-CMNT-001).
		 *
		 * @param {string} uuid The dashboard UUID.
		 * @return {Promise<{enabled: boolean, comments: Array}>} The envelope.
		 */
		async loadComments(uuid) {
			this.loading = { ...this.loading, [uuid]: true }
			this.errors = { ...this.errors, [uuid]: null }
			try {
				const response = await api.listDashboardComments(uuid)
				const envelope = {
					enabled: response.data?.enabled ?? true,
					comments: response.data?.comments ?? [],
				}
				this.byDashboard = { ...this.byDashboard, [uuid]: envelope }
				return envelope
			} catch (error) {
				this.errors = { ...this.errors, [uuid]: error }
				throw error
			} finally {
				this.loading = { ...this.loading, [uuid]: false }
			}
		},

		/**
		 * Create a new comment or reply (REQ-CMNT-002, REQ-CMNT-003).
		 *
		 * @param {string} uuid     The dashboard UUID.
		 * @param {object} payload  `{message, parentId?}` body.
		 * @return {Promise<object>} The created comment envelope.
		 */
		async createComment(uuid, payload) {
			const response = await api.createDashboardComment(uuid, payload)
			const created = response.data
			const thread = this.threadFor(uuid)
			if (created.parentId === null || created.parentId === undefined) {
				const next = {
					enabled: thread.enabled,
					// Newest-first for top-level (matches REQ-CMNT-001 ordering).
					comments: [{ ...created, replies: [] }, ...thread.comments],
				}
				this.byDashboard = { ...this.byDashboard, [uuid]: next }
			} else {
				const nextComments = thread.comments.map((c) => {
					if (c.id !== created.parentId) {
						return c
					}
					return {
						...c,
						replies: [...(c.replies ?? []), created],
					}
				})
				this.byDashboard = {
					...this.byDashboard,
					[uuid]: { enabled: thread.enabled, comments: nextComments },
				}
			}
			return created
		},

		/**
		 * Update an existing comment (REQ-CMNT-004).
		 *
		 * @param {string} uuid    The dashboard UUID.
		 * @param {number} id      The comment ID.
		 * @param {object} payload `{message}` body.
		 * @return {Promise<object>} The updated comment envelope.
		 */
		async updateComment(uuid, id, payload) {
			const response = await api.updateDashboardComment(uuid, id, payload)
			const updated = response.data
			const thread = this.threadFor(uuid)
			const nextComments = thread.comments.map((c) => {
				if (c.id === id) {
					return { ...c, ...updated, replies: c.replies ?? [] }
				}
				const replies = (c.replies ?? []).map((r) => (r.id === id ? { ...r, ...updated } : r))
				return { ...c, replies }
			})
			this.byDashboard = {
				...this.byDashboard,
				[uuid]: { enabled: thread.enabled, comments: nextComments },
			}
			return updated
		},

		/**
		 * Delete a comment (REQ-CMNT-005). Top-level deletes also drop
		 * the reply list locally to mirror the backend cascade.
		 *
		 * @param {string} uuid The dashboard UUID.
		 * @param {number} id   The comment ID.
		 * @return {Promise<void>} Resolves on success.
		 */
		async deleteComment(uuid, id) {
			await api.deleteDashboardComment(uuid, id)
			const thread = this.threadFor(uuid)
			const nextComments = thread.comments
				.filter((c) => c.id !== id)
				.map((c) => ({
					...c,
					replies: (c.replies ?? []).filter((r) => r.id !== id),
				}))
			this.byDashboard = {
				...this.byDashboard,
				[uuid]: { enabled: thread.enabled, comments: nextComments },
			}
		},

		/**
		 * Drop the cached thread for a dashboard. Used when the dashboard
		 * is deleted client-side so the next load fetches fresh data.
		 *
		 * @param {string} uuid The dashboard UUID.
		 * @return {void}
		 */
		clearThread(uuid) {
			const next = { ...this.byDashboard }
			delete next[uuid]
			this.byDashboard = next
		},
	},
})
