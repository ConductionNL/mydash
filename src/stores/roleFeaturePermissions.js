/**
 * Pinia store for role-feature permissions and role-layout defaults.
 *
 * Backs the admin UI's RolePermissionsSection. Talks to the four CRUD
 * endpoints introduced by `lib/Controller/RoleFeaturePermissionApiController.php`
 * (REQ-RFP-001..010).
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const PERMS_URL = generateUrl('/apps/mydash/api/role-feature-permissions')
const DEFAULTS_URL = generateUrl('/apps/mydash/api/role-layout-defaults')

export const useRoleFeaturePermissionStore = defineStore('roleFeaturePermissions', {
	state: () => ({
		permissions: [],
		layoutDefaults: [],
		loading: false,
		saving: false,
		error: null,
	}),

	actions: {
		/**
		 * Fetch all RoleFeaturePermission rows.
		 */
		async loadPermissions() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(PERMS_URL)
				const payload = response.data?.data ?? response.data
				this.permissions = Array.isArray(payload) ? payload : []
			} catch (e) {
				this.error = e.message ?? 'Failed to load role-feature permissions'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch all RoleLayoutDefault rows.
		 */
		async loadLayoutDefaults() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(DEFAULTS_URL)
				const payload = response.data?.data ?? response.data
				this.layoutDefaults = Array.isArray(payload) ? payload : []
			} catch (e) {
				this.error = e.message ?? 'Failed to load role-layout defaults'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Upsert a RoleFeaturePermission keyed by groupId.
		 *
		 * @param {object} permission The permission payload.
		 */
		async savePermission(permission) {
			this.saving = true
			this.error = null
			try {
				const response = await axios.post(PERMS_URL, permission)
				const saved = response.data?.data ?? response.data
				const idx = this.permissions.findIndex(
					(p) => p.groupId === saved.groupId,
				)
				if (idx >= 0) {
					this.permissions.splice(idx, 1, saved)
				} else {
					this.permissions.push(saved)
				}
				return saved
			} catch (e) {
				this.error = e.message ?? 'Failed to save permission'
				throw e
			} finally {
				this.saving = false
			}
		},

		/**
		 * Delete a RoleFeaturePermission row by id.
		 *
		 * @param {number} id The row id.
		 */
		async deletePermission(id) {
			this.saving = true
			this.error = null
			try {
				await axios.delete(`${PERMS_URL}/${id}`)
				this.permissions = this.permissions.filter((p) => p.id !== id)
			} catch (e) {
				this.error = e.message ?? 'Failed to delete permission'
				throw e
			} finally {
				this.saving = false
			}
		},

		/**
		 * Upsert a RoleLayoutDefault keyed by (groupId, widgetId).
		 *
		 * @param {object} layoutDefault The layout-default payload.
		 */
		async saveLayoutDefault(layoutDefault) {
			this.saving = true
			this.error = null
			try {
				const response = await axios.post(DEFAULTS_URL, layoutDefault)
				const saved = response.data?.data ?? response.data
				const idx = this.layoutDefaults.findIndex(
					(d) => d.groupId === saved.groupId && d.widgetId === saved.widgetId,
				)
				if (idx >= 0) {
					this.layoutDefaults.splice(idx, 1, saved)
				} else {
					this.layoutDefaults.push(saved)
				}
				return saved
			} catch (e) {
				this.error = e.message ?? 'Failed to save layout default'
				throw e
			} finally {
				this.saving = false
			}
		},

		/**
		 * Delete a RoleLayoutDefault row by id.
		 *
		 * @param {number} id The row id.
		 */
		async deleteLayoutDefault(id) {
			this.saving = true
			this.error = null
			try {
				await axios.delete(`${DEFAULTS_URL}/${id}`)
				this.layoutDefaults = this.layoutDefaults.filter((d) => d.id !== id)
			} catch (e) {
				this.error = e.message ?? 'Failed to delete layout default'
				throw e
			} finally {
				this.saving = false
			}
		},
	},
})
