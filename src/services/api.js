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

	// REQ-DASH-013 — deduplicated union of personal + group + default dashboards.
	getVisibleDashboards() {
		return axios.get(`${baseUrl}/api/dashboards/visible`)
	},

	// REQ-DASH-014 — group-shared dashboard CRUD.
	getGroupDashboards(groupId) {
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

	getActiveDashboard() {
		return axios.get(`${baseUrl}/api/dashboard`)
	},

	// Persist the user's active-dashboard preference (REQ-DASH-019).
	// Empty string clears the pref so the resolver falls back through the
	// 7-step chain on next render. The endpoint does NOT validate that
	// the UUID exists — the resolver's stale-pref path handles invalid
	// UUIDs on the next page load.
	setActiveDashboardPreference(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/active`, { uuid })
	},

	/*
	 * Wave3.7 — pin (or clear) the user's EXPLICIT default-dashboard
	 * choice. Distinct from `setActiveDashboardPreference` above which
	 * auto-overwrites on every switch — this one is only ever written
	 * when the user clicks "Set as default" on a row's cog menu, and
	 * the resolver checks it before the active pref so the pin
	 * survives across switches.
	 */
	setDefaultDashboardPreference(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/default`, { uuid })
	},
	clearDefaultDashboardPreference() {
		return axios.post(`${baseUrl}/api/dashboards/default`, { uuid: '' })
	},
	getDefaultDashboardPreference() {
		return axios.get(`${baseUrl}/api/dashboards/default`)
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

	// Group-shared dashboard CRUD alias (REQ-DASH-014). `listGroupDashboards`
	// mirrors the `getGroupDashboards` method above for callers that prefer
	// the verb-prefixed name.
	listGroupDashboards(groupId) {
		return axios.get(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}`)
	},

	// Promote a single group-shared dashboard to the group's default
	// (REQ-DASH-015). Admin-only — backend enforces the admin guard and
	// runs the flip in a single transaction.
	setGroupDashboardDefault(groupId, uuid) {
		return axios.post(
			`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/default`,
			{ uuid },
		)
	},

	// Fork any visible dashboard into a brand-new personal copy
	// (REQ-DASH-020). Body shape is `{name?: string}` — when `name`
	// is omitted the backend applies the localised default
	// `My copy of {source name}`.
	forkDashboard(sourceUuid, name) {
		const payload = (name === undefined || name === null || name === '')
			? {}
			: { name }
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(sourceUuid)}/fork`, payload)
	},

	// REQ-DASH-032: publish a dashboard. Owner-or-admin only on the
	// backend; a 403 envelope surfaces here when the caller lacks
	// permission.
	publishDashboard(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/publish`)
	},

	// REQ-DASH-033: unpublish (return to draft). Preserves publishedAt
	// on the backend for audit history.
	unpublishDashboard(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/unpublish`)
	},

	// REQ-DASH-034: schedule a dashboard for automatic publication at a
	// future ISO-8601 timestamp. Backend rejects past dates with a
	// localised 400 error message.
	scheduleDashboard(uuid, publishAt) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/schedule`,
			{ publishAt },
		)
	},

	// REQ-ANLT-002: record a dashboard view event. Authed users only;
	// server short-circuits to 204 silently when the user has opted
	// out (REQ-ANLT-004) or analytics is globally disabled
	// (REQ-ANLT-005). Body is empty `{}`.
	recordDashboardViewEvent(uuid) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/view-event`,
			{},
		)
	},

	// REQ-ANLT-006: top-N dashboards by view count for the given period.
	getAnalyticsTopDashboards(period = '30d', limit = 10) {
		return axios.get(
			`${baseUrl}/api/admin/analytics/dashboards/top`,
			{ params: { period, limit } },
		)
	},

	// REQ-ANLT-007: daily breakdown for one dashboard.
	getAnalyticsDashboardDetail(uuid, period = '30d') {
		return axios.get(
			`${baseUrl}/api/admin/analytics/dashboards/${encodeURIComponent(uuid)}`,
			{ params: { period } },
		)
	},

	// REQ-ANLT-008: instance-wide totals + top-5.
	getAnalyticsInstanceSummary(period = '30d') {
		return axios.get(
			`${baseUrl}/api/admin/analytics/summary`,
			{ params: { period } },
		)
	},

	// REQ-ANLT-010: trigger a CSV export download. Returns the raw
	// response so the caller can stream it to a file or pass it to
	// `URL.createObjectURL` for in-browser download.
	getAnalyticsCsvExport(period = '30d') {
		return axios.get(
			`${baseUrl}/api/admin/analytics/export`,
			{ params: { period }, responseType: 'blob' },
		)
	},

	// Fetch the nested dashboard tree (REQ-DASH-026).
	getDashboardTree() {
		return axios.get(`${baseUrl}/api/dashboards/tree`)
	},

	// Resolve a slug-chain path to a dashboard (REQ-DASH-027).
	// Path segments are forwarded verbatim — the backend folds case and
	// strips trailing slashes, but callers SHOULD normalise to lowercase
	// without leading/trailing `/` to maximise the cache hit rate.
	getDashboardByPath(path) {
		const cleanPath = String(path || '').replace(/^\/+/, '').replace(/\/+$/, '')
		return axios.get(`${baseUrl}/api/dashboards/by-path/${cleanPath}`)
	},

	// REQ-LOCK-001..008: dashboard editing-lock management.
	// Re-entrant for the same user (a second tab refreshes the lease
	// instead of getting 409). Heartbeat MUST be sent every 60 s by
	// any client in active edit mode (15 min TTL = 15× safety margin).
	acquireDashboardLock(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	heartbeatDashboardLock(uuid) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	releaseDashboardLock(uuid) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	getDashboardLock(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	// Admin-only: force-release whoever holds the lock so the
	// dashboard returns to an unlocked state. Admin then acquires
	// normally if they want to edit (REQ-LOCK-006).
	forceReleaseDashboardLock(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock/force-release`)
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

	// Template gallery (REQ-TMPL-014). Returns
	// `{status, templates: [{uuid, name, description, category,
	// previewImage, gridColumns, widgetCount, lastUpdatedAt}]}`.
	getTemplateGallery({ category = null, sort = 'name' } = {}) {
		const params = { sort }
		if (category) {
			params.category = category
		}
		return axios.get(`${baseUrl}/api/templates/gallery`, { params })
	},

	// Save a personal dashboard as an admin template (REQ-TMPL-015).
	// Body: `{name, description?, category?, previewImage?}`. Owner-only;
	// 403 envelope when caller does not own the source dashboard.
	saveDashboardAsTemplate(dashboardUuid, metadata = {}) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(dashboardUuid)}/save-as-template`,
			metadata,
		)
	},

	// Upload an admin template preview image (REQ-TMPL-017). Admin-only.
	// Body: `{base64: 'data:image/<type>;base64,<bytes>'}`. Reuses the
	// resource-uploads pipeline so the same MIME / size validation applies.
	uploadTemplatePreviewImage(templateUuid, base64) {
		return axios.post(
			`${baseUrl}/api/admin/templates/${encodeURIComponent(templateUuid)}/preview-image`,
			{ base64 },
		)
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

	// Setup wizard endpoints (REQ-WIZ-008, REQ-WIZ-009, REQ-WIZ-003).
	// All three are admin-only on the server side; the UI gates the
	// banner + modal behind the same check via `getSetupWizardState`.
	getSetupWizardState() {
		return axios.get(`${baseUrl}/api/admin/setup-wizard/state`)
	},

	completeSetupWizard() {
		return axios.post(`${baseUrl}/api/admin/setup-wizard/complete`)
	},

	setSetupWizardStorage(storage) {
		return axios.post(`${baseUrl}/api/admin/setup-wizard/storage`, { storage })
	},

	// Dashboard export / import (REQ-EXIM-002..004). Both endpoints
	// require Nextcloud-admin and are gated server-side; the admin UI
	// only renders the controls behind the same admin check. Export
	// returns a binary blob streamed straight to disk; import accepts a
	// multipart upload.
	exportDashboards({ scope = 'site', dashboardUuid = null } = {}) {
		const params = { scope }
		if (dashboardUuid) {
			params.dashboardUuid = dashboardUuid
		}
		return axios.post(`${baseUrl}/api/admin/export`, null, {
			params,
			responseType: 'blob',
		})
	},

	importDashboards(file, { preserveUuids = false } = {}) {
		const form = new FormData()
		form.append('file', file)
		return axios.post(`${baseUrl}/api/admin/import`, form, {
			params: { preserveUuids },
			headers: { 'Content-Type': 'multipart/form-data' },
		})
	},

	// Confluence HTML export importer (REQ-CFLI-001..012). Both endpoints
	// are admin-only on the server side; the UI gates the controls behind
	// the same admin check. Dry-run returns the parse preview without
	// touching the database; the import endpoint creates one MyDash
	// dashboard per Confluence page.
	confluenceImportDryRun(file) {
		const form = new FormData()
		form.append('file', file)
		return axios.post(
			`${baseUrl}/api/admin/import/confluence/dry-run`,
			form,
			{ headers: { 'Content-Type': 'multipart/form-data' } },
		)
	},

	confluenceImport(file, { parentUuid = null } = {}) {
		const form = new FormData()
		form.append('file', file)
		const params = {}
		if (parentUuid) {
			params.parentUuid = parentUuid
		}
		return axios.post(`${baseUrl}/api/admin/import/confluence`, form, {
			params,
			headers: { 'Content-Type': 'multipart/form-data' },
		})
	},

	// Dashboard comments (REQ-CMNT-001..009). Threaded comments backed by
	// Nextcloud's ICommentsManager. The `enabled` field on the GET
	// response carries the effective per-dashboard / global toggle.
	listDashboardComments(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments`)
	},

	createDashboardComment(uuid, payload) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments`, payload)
	},

	updateDashboardComment(uuid, id, payload) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments/${id}`, payload)
	},

	deleteDashboardComment(uuid, id) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments/${id}`)
	},

	// Dashboard metadata-fields admin CRUD (REQ-MDFL-001..003).
	getMetadataFields() {
		return axios.get(`${baseUrl}/api/admin/metadata-fields`)
	},

	createMetadataField(field) {
		return axios.post(`${baseUrl}/api/admin/metadata-fields`, field)
	},

	getMetadataField(id) {
		return axios.get(`${baseUrl}/api/admin/metadata-fields/${id}`)
	},

	updateMetadataField(id, patch) {
		return axios.put(`${baseUrl}/api/admin/metadata-fields/${id}`, patch)
	},

	deleteMetadataField(id, cascade = false) {
		const query = cascade ? '?cascade=true' : ''
		return axios.delete(`${baseUrl}/api/admin/metadata-fields/${id}${query}`)
	},

	// Per-dashboard metadata read/write (REQ-MDFL-004..006, REQ-MDFL-008).
	getDashboardMetadata(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/metadata`)
	},

	updateDashboardMetadata(uuid, metadata) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/metadata`, { metadata })
	},

	// REQ-FEED-001..003 — per-user RSS / Atom feed-token management.
	// `getFeedToken` issues-or-returns the existing token; `regenerate`
	// atomically rotates; `revoke` soft-revokes (idempotent — 204 even
	// when no token exists).
	getFeedToken() {
		return axios.get(`${baseUrl}/api/feed/token`)
	},

	regenerateFeedToken() {
		return axios.post(`${baseUrl}/api/feed/token/regenerate`)
	},

	revokeFeedToken() {
		return axios.delete(`${baseUrl}/api/feed/token`)
	},

	// Org-wide navigation editor (REQ-ONAV-001..012). The GET endpoint
	// is accessible to any logged-in user (the backend filters the tree
	// by group visibility); the PUT endpoint is admin-only on the
	// server. The position endpoints follow the same pattern. The lang
	// query parameter selects which per-language JSON file is read or
	// written; defaults to 'nl' on the backend.
	getOrgNavigation(lang = 'nl') {
		return axios.get(`${baseUrl}/api/admin/org-navigation`, { params: { lang } })
	},

	updateOrgNavigation(tree, lang = 'nl') {
		return axios.put(`${baseUrl}/api/admin/org-navigation`, { tree }, { params: { lang } })
	},

	getOrgNavigationPosition() {
		return axios.get(`${baseUrl}/api/admin/org-navigation/position`)
	},

	updateOrgNavigationPosition(position) {
		return axios.put(`${baseUrl}/api/admin/org-navigation/position`, { position })
	},

	// Dashboard bulk operations (REQ-BULK-001..011). All four endpoints
	// are admin-only on the server side; the UI gates the controls behind
	// the same admin check. Each endpoint accepts `dryRun` to preview
	// outcomes without mutating the database, and returns
	// `{deletedCount|movedCount|updatedCount|reindexedCount, skippedCount, errors, dryRun}`
	// (or the `wouldX` variants in dry-run mode).
	bulkDeleteDashboards(dashboardUuids, { dryRun = false, cascade = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-delete`, {
			dashboardUuids,
			dryRun,
			cascade,
		})
	},

	bulkMoveDashboards(dashboardUuids, parentUuid, { dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-move`, {
			dashboardUuids,
			parentUuid,
			dryRun,
		})
	},

	bulkStatusDashboards(dashboardUuids, publicationStatus, { publishAt = null, dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-status`, {
			dashboardUuids,
			publicationStatus,
			publishAt,
			dryRun,
		})
	},

	bulkReindexDashboards(dashboardUuids, { dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-reindex`, {
			dashboardUuids,
			dryRun,
		})
	},

	// Demo showcase install / list / uninstall (REQ-DEMO-002..006).
	// All three endpoints are admin-only on the server side; the UI
	// only renders the controls behind the same admin check.
	listDemoShowcases() {
		return axios.get(`${baseUrl}/api/admin/demo-showcases`)
	},

	installDemoShowcase(id, { lang = 'nl', force = false } = {}) {
		const params = { lang }
		if (force) {
			params.force = true
		}
		return axios.post(`${baseUrl}/api/admin/demo-showcases/${encodeURIComponent(id)}/install`, null, { params })
	},

	uninstallDemoShowcase(id) {
		return axios.delete(`${baseUrl}/api/admin/demo-showcases/${encodeURIComponent(id)}`)
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

	// Dashboard reaction endpoints (REQ-RXN-001..004).
	getDashboardReactions(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions`)
	},

	addDashboardReaction(uuid, emoji) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions`,
			{ emoji },
		)
	},

	removeDashboardReaction(uuid, emoji) {
		return axios.delete(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions/${encodeURIComponent(emoji)}`,
		)
	},

	getDashboardReactors(uuid, emoji, cursor = null) {
		const params = cursor !== null && cursor !== '' ? { cursor } : {}
		return axios.get(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions/${encodeURIComponent(emoji)}/users`,
			{ params },
		)
	},

	// Dashboard translation endpoints (REQ-DASH-038..044) — per-language
	// content variants for dashboards. All endpoints scope by dashboard
	// UUID and require ownership; the server returns 403 for cross-user
	// attempts.
	listDashboardTranslations(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations`)
	},

	createDashboardTranslation(uuid, data) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations`, data)
	},

	updateDashboardTranslation(uuid, lang, data) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}`, data)
	},

	deleteDashboardTranslation(uuid, lang) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}`)
	},

	setDashboardTranslationPrimary(uuid, lang) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}/set-primary`)
	},

	// Resolve the dashboard for the viewer's locale; optional `?lang=`
	// query parameter overrides the user's Nextcloud locale.
	getResolvedDashboard(uuid, lang) {
		const params = (lang !== undefined && lang !== null && lang !== '') ? { params: { lang } } : {}
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/resolved`, params)
	},
}
