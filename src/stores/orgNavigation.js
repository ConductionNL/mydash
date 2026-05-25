/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pinia store for the org-wide navigation editor (REQ-ONAV-001..012).
 *
 * State of the store reflects the API truth: the `tree` array is the
 * filtered tree returned by GET /api/admin/org-navigation (REQ-ONAV-002).
 * The store does NOT cache the unfiltered admin view — the editor
 * fetches the raw tree on demand via the same endpoint (admins see
 * everything because the filter trivially passes for them when groups
 * resolve, and the admin editor always uses the most-recent PUT
 * payload as truth).
 *
 * Position is also tracked in this store so the runtime panel and the
 * admin editor share the same source of truth (REQ-ONAV-004).
 */

import { defineStore } from 'pinia'

import { api } from '../services/api.js'

/**
 * Allowed values for the global rail position (REQ-ONAV-004).
 *
 * @type {string[]}
 */
export const ORG_NAV_POSITIONS = Object.freeze(['left', 'right', 'top', 'hidden'])

export const useOrgNavigationStore = defineStore('orgNavigation', {
	state: () => ({
		// Current filtered tree for the active language. Empty array
		// means "no nodes visible" — REQ-ONAV-008's empty-state branch
		// is driven by `isEmpty` below.
		tree: [],
		// Active language code; v1 ships with 'nl' and 'en'.
		language: 'nl',
		// Global rail position; defaults to 'hidden' until the GET
		// /position call returns.
		position: 'hidden',
		// Pending HTTP — used by the panel/editor to render a spinner
		// or disable the Save button.
		loading: false,
		// Error string — last user-facing failure. Cleared on the next
		// successful call.
		error: null,
	}),

	getters: {
		/**
		 * The current filtered tree (REQ-ONAV-002). Mirrors `state.tree`
		 * — exposed as a getter so consumers stay decoupled from the
		 * raw state shape.
		 *
		 * @param {object} state Pinia state.
		 * @return {Array<object>} The filtered tree.
		 */
		visibleTree: (state) => state.tree,

		/**
		 * REQ-ONAV-008 — true when there are no visible nodes (the
		 * panel MUST not render in that case).
		 *
		 * @param {object} state Pinia state.
		 * @return {boolean}
		 */
		isEmpty: (state) => !Array.isArray(state.tree) || state.tree.length === 0,

		/**
		 * REQ-ONAV-008 — true when the panel SHOULD render. Combines
		 * the empty check with the position setting: position 'hidden'
		 * suppresses the rail unconditionally.
		 *
		 * @param {object} state Pinia state.
		 * @return {boolean}
		 */
		shouldRender: (state) => {
			if (state.position === 'hidden') {
				return false
			}
			return Array.isArray(state.tree) && state.tree.length > 0
		},
	},

	actions: {
		/**
		 * Fetch the (filtered) tree for the given language. Stores the
		 * result in `state.tree` and clears any previous error.
		 *
		 * @param {string} lang ISO language code (defaults to current
		 *                      `state.language`).
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		async fetchTree(lang) {
			const language = lang || this.language || 'nl'
			this.loading = true
			try {
				const response = await api.getOrgNavigation(language)
				const data = response && response.data ? response.data : {}
				this.tree = Array.isArray(data.tree) ? data.tree : []
				this.language = data.language || language
				this.error = null
			} catch (err) {
				this.tree = []
				this.error = (err && err.message) ? err.message : 'Failed to load navigation'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist a new tree (admin-only on the backend).
		 *
		 * On success the local `tree` is replaced with the returned
		 * payload (the backend may normalise / strip unknown fields in
		 * the future). On failure the response body's `error` string is
		 * lifted into `state.error` so the editor can surface it to the
		 * admin.
		 *
		 * @param {Array<object>} newTree The full replacement tree.
		 * @param {string} lang ISO language code (defaults to current).
		 * @return {Promise<boolean>} true on success, false on failure.
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		async updateTree(newTree, lang) {
			const language = lang || this.language || 'nl'
			this.loading = true
			try {
				const response = await api.updateOrgNavigation(newTree, language)
				const data = response && response.data ? response.data : {}
				this.tree = Array.isArray(data.tree) ? data.tree : newTree
				this.language = data.language || language
				this.error = null
				return true
			} catch (err) {
				const responseError = err && err.response && err.response.data
					? err.response.data.error
					: null
				this.error = responseError || 'Failed to save navigation'
				return false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the current global rail position (REQ-ONAV-004).
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		async fetchPosition() {
			try {
				const response = await api.getOrgNavigationPosition()
				const data = response && response.data ? response.data : {}
				if (ORG_NAV_POSITIONS.includes(data.position)) {
					this.position = data.position
				}
			} catch (err) {
				// Defensive — leave the previous value in place.
				this.error = (err && err.message) ? err.message : 'Failed to load position'
			}
		},

		/**
		 * Persist a new global rail position (admin-only).
		 *
		 * @param {string} newPosition One of ORG_NAV_POSITIONS.
		 * @return {Promise<boolean>} true on success.
		 */
		/** @spec openspec/specs/navigation-editor-org/spec.md */
		async updatePosition(newPosition) {
			if (!ORG_NAV_POSITIONS.includes(newPosition)) {
				this.error = 'Unsupported position'
				return false
			}
			try {
				const response = await api.updateOrgNavigationPosition(newPosition)
				const data = response && response.data ? response.data : {}
				this.position = data.position || newPosition
				this.error = null
				return true
			} catch (err) {
				const responseError = err && err.response && err.response.data
					? err.response.data.error
					: null
				this.error = responseError || 'Failed to save position'
				return false
			}
		},
	},
})
