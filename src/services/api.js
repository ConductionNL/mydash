/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/mydash')

export const api = {
	// Dashboard endpoints
	getDashboards() {
		return axios.get(`${baseUrl}/api/dashboards`)
	},

	getActiveDashboard() {
		return axios.get(`${baseUrl}/api/dashboard`)
	},

	createDashboard(data) {
		return axios.post(`${baseUrl}/api/dashboard`, data)
	},

	updateDashboard(id, data) {
		return axios.put(`${baseUrl}/api/dashboard/${id}`, data)
	},

	deleteDashboard(id) {
		return axios.delete(`${baseUrl}/api/dashboard/${id}`)
	},

	activateDashboard(id) {
		return axios.post(`${baseUrl}/api/dashboard/${id}/activate`)
	},

	getDashboardById(id) {
		return axios.get(`${baseUrl}/api/dashboard/${id}`)
	},

	// Visible-to-user resolution endpoint (REQ-DASH-013).
	// Returns deduplicated union of personal + group + default-group
	// dashboards, each tagged with `source: 'user' | 'group' | 'default'`.
	getVisibleDashboards() {
		return axios.get(`${baseUrl}/api/dashboards/visible`)
	},

	// Group-shared dashboard CRUD (REQ-DASH-014).
	listGroupDashboards(groupId) {
		return axios.get(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}`)
	},

	createGroupDashboard(groupId, data) {
		return axios.post(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}`, data)
	},

	getGroupDashboard(groupId, uuid) {
		return axios.get(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/${encodeURIComponent(uuid)}`)
	},

	updateGroupDashboard(groupId, uuid, data) {
		return axios.put(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/${encodeURIComponent(uuid)}`, data)
	},

	deleteGroupDashboard(groupId, uuid) {
		return axios.delete(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/${encodeURIComponent(uuid)}`)
	},

	// Sharing endpoints
	listShares(dashboardId) {
		return axios.get(`${baseUrl}/api/dashboard/${dashboardId}/shares`)
	},

	addShare(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/shares`, data)
	},

	replaceShares(dashboardId, shares) {
		return axios.put(`${baseUrl}/api/dashboard/${dashboardId}/shares`, { shares })
	},

	removeShare(shareId) {
		return axios.delete(`${baseUrl}/api/dashboard/share/${shareId}`)
	},

	revokeAllForRecipient(shareType, shareWith) {
		return axios.delete(`${baseUrl}/api/sharees/${shareType}/${encodeURIComponent(shareWith)}`)
	},

	searchSharees(query) {
		return axios.get(`${baseUrl}/api/sharees`, { params: { query } })
	},

	// Widget endpoints
	getAvailableWidgets() {
		return axios.get(`${baseUrl}/api/widgets`)
	},

	getWidgetItems(widgetIds) {
		const params = new URLSearchParams()
		widgetIds.forEach(id => params.append('widgets[]', id))
		return axios.get(`${baseUrl}/api/widgets/items?${params.toString()}`)
	},

	addWidget(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/widgets`, data)
	},

	addTile(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/tile`, data)
	},

	updateWidgetPlacement(placementId, data) {
		return axios.put(`${baseUrl}/api/widgets/${placementId}`, data)
	},

	removeWidget(placementId) {
		return axios.delete(`${baseUrl}/api/widgets/${placementId}`)
	},

	// Conditional rules endpoints
	getWidgetRules(placementId) {
		return axios.get(`${baseUrl}/api/widgets/${placementId}/rules`)
	},

	addWidgetRule(placementId, data) {
		return axios.post(`${baseUrl}/api/widgets/${placementId}/rules`, data)
	},

	updateRule(ruleId, data) {
		return axios.put(`${baseUrl}/api/rules/${ruleId}`, data)
	},

	deleteRule(ruleId) {
		return axios.delete(`${baseUrl}/api/rules/${ruleId}`)
	},

	// Admin endpoints
	getAdminTemplates() {
		return axios.get(`${baseUrl}/api/admin/templates`)
	},

	createAdminTemplate(data) {
		return axios.post(`${baseUrl}/api/admin/templates`, data)
	},

	getAdminTemplate(id) {
		return axios.get(`${baseUrl}/api/admin/templates/${id}`)
	},

	updateAdminTemplate(id, data) {
		return axios.put(`${baseUrl}/api/admin/templates/${id}`, data)
	},

	deleteAdminTemplate(id) {
		return axios.delete(`${baseUrl}/api/admin/templates/${id}`)
	},

	getAdminSettings() {
		return axios.get(`${baseUrl}/api/admin/settings`)
	},

	updateAdminSettings(data) {
		return axios.put(`${baseUrl}/api/admin/settings`, data)
	},

	// Admin group-priority order (REQ-ASET-012/013/014).
	// Both endpoints are admin-only on the server side; the UI gates
	// rendering of the section behind the same admin check.
	getAdminGroups() {
		return axios.get(`${baseUrl}/api/admin/groups`)
	},

	updateAdminGroupOrder(groups) {
		return axios.post(`${baseUrl}/api/admin/groups`, { groups })
	},

	// Tile endpoints
	getTiles() {
		return axios.get(`${baseUrl}/api/tiles`)
	},

	createTile(data) {
		return axios.post(`${baseUrl}/api/tiles`, data)
	},

	updateTile(id, data) {
		return axios.put(`${baseUrl}/api/tiles/${id}`, data)
	},

	deleteTile(id) {
		return axios.delete(`${baseUrl}/api/tiles/${id}`)
	},
}
