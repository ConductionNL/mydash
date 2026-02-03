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
}
