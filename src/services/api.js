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

	/** @spec openspec/specs/dashboards/spec.md */
	createGroupDashboard(groupId, data) {
		return axios.post(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}`, data)
	},

	getGroupDashboard(groupId, uuid) {
		return axios.get(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/${encodeURIComponent(uuid)}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateGroupDashboard(groupId, uuid, data) {
		return axios.put(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}/${encodeURIComponent(uuid)}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	clearDefaultDashboardPreference() {
		return axios.post(`${baseUrl}/api/dashboards/default`, { uuid: '' })
	},
	getDefaultDashboardPreference() {
		return axios.get(`${baseUrl}/api/dashboards/default`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createDashboard(data) {
		return axios.post(`${baseUrl}/api/dashboard`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateDashboard(id, data) {
		return axios.put(`${baseUrl}/api/dashboard/${id}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteDashboard(id) {
		return axios.delete(`${baseUrl}/api/dashboard/${id}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	activateDashboard(id) {
		return axios.post(`${baseUrl}/api/dashboard/${id}/activate`)
	},

	getDashboardById(id) {
		return axios.get(`${baseUrl}/api/dashboard/${id}`)
	},

	// Group-shared dashboard CRUD alias (REQ-DASH-014). `listGroupDashboards`
	// mirrors the `getGroupDashboards` method above for callers that prefer
	// the verb-prefixed name.
	/** @spec openspec/specs/dashboards/spec.md */
	listGroupDashboards(groupId) {
		return axios.get(`${baseUrl}/api/dashboards/group/${encodeURIComponent(groupId)}`)
	},

	// Promote a single group-shared dashboard to the group's default
	// (REQ-DASH-015). Admin-only — backend enforces the admin guard and
	// runs the flip in a single transaction.
	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	forkDashboard(sourceUuid, name) {
		const payload = (name === undefined || name === null || name === '')
			? {}
			: { name }
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(sourceUuid)}/fork`, payload)
	},

	// REQ-DASH-032: publish a dashboard. Owner-or-admin only on the
	// backend; a 403 envelope surfaces here when the caller lacks
	// permission.
	/** @spec openspec/specs/dashboards/spec.md */
	publishDashboard(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/publish`)
	},

	// REQ-DASH-033: unpublish (return to draft). Preserves publishedAt
	// on the backend for audit history.
	/** @spec openspec/specs/dashboards/spec.md */
	unpublishDashboard(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/unpublish`)
	},

	// REQ-DASH-034: schedule a dashboard for automatic publication at a
	// future ISO-8601 timestamp. Backend rejects past dates with a
	// localised 400 error message.
	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	recordDashboardViewEvent(uuid) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/view-event`,
			{},
		)
	},

	// REQ-ANLT-006: top-N dashboards by view count for the given period.
	/** @spec openspec/specs/dashboards/spec.md */
	getAnalyticsTopDashboards(period = '30d', limit = 10) {
		return axios.get(
			`${baseUrl}/api/admin/analytics/dashboards/top`,
			{ params: { period, limit } },
		)
	},

	// REQ-ANLT-007: daily breakdown for one dashboard.
	/** @spec openspec/specs/dashboards/spec.md */
	getAnalyticsDashboardDetail(uuid, period = '30d') {
		return axios.get(
			`${baseUrl}/api/admin/analytics/dashboards/${encodeURIComponent(uuid)}`,
			{ params: { period } },
		)
	},

	// REQ-ANLT-008: instance-wide totals + top-5.
	/** @spec openspec/specs/dashboards/spec.md */
	getAnalyticsInstanceSummary(period = '30d') {
		return axios.get(
			`${baseUrl}/api/admin/analytics/summary`,
			{ params: { period } },
		)
	},

	// REQ-ANLT-010: trigger a CSV export download. Returns the raw
	// response so the caller can stream it to a file or pass it to
	// `URL.createObjectURL` for in-browser download.
	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	getDashboardByPath(path) {
		const cleanPath = String(path || '').replace(/^\/+/, '').replace(/\/+$/, '')
		return axios.get(`${baseUrl}/api/dashboards/by-path/${cleanPath}`)
	},

	// Reverse lookup: dashboard UUID → canonical slug-chain path. Used
	// by the sidebar after every switch to keep `window.location.pathname`
	// in sync with the active dashboard. Server returns `{path: '...'}`;
	// an empty path is a valid response (the dashboard has no slug, so
	// there is no addressable URL).
	getDashboardPath(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/path`)
	},

	// REQ-LOCK-001..008: dashboard editing-lock management.
	// Re-entrant for the same user (a second tab refreshes the lease
	// instead of getting 409). Heartbeat MUST be sent every 60 s by
	// any client in active edit mode (15 min TTL = 15× safety margin).
	/** @spec openspec/specs/dashboards/spec.md */
	acquireDashboardLock(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	heartbeatDashboardLock(uuid) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	releaseDashboardLock(uuid) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	getDashboardLock(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock`)
	},

	// Admin-only: force-release whoever holds the lock so the
	// dashboard returns to an unlocked state. Admin then acquires
	// normally if they want to edit (REQ-LOCK-006).
	/** @spec openspec/specs/dashboards/spec.md */
	forceReleaseDashboardLock(uuid) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/lock/force-release`)
	},

	// Sharing endpoints
	/** @spec openspec/specs/dashboards/spec.md */
	listShares(dashboardId) {
		return axios.get(`${baseUrl}/api/dashboard/${dashboardId}/shares`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	addShare(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/shares`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	replaceShares(dashboardId, shares) {
		return axios.put(`${baseUrl}/api/dashboard/${dashboardId}/shares`, { shares })
	},

	/** @spec openspec/specs/dashboards/spec.md */
	removeShare(shareId) {
		return axios.delete(`${baseUrl}/api/dashboard/share/${shareId}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	revokeAllForRecipient(shareType, shareWith) {
		return axios.delete(`${baseUrl}/api/sharees/${shareType}/${encodeURIComponent(shareWith)}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	searchSharees(query) {
		return axios.get(`${baseUrl}/api/sharees`, { params: { query } })
	},

	// Widget endpoints
	getAvailableWidgets() {
		return axios.get(`${baseUrl}/api/widgets`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	getWidgetItems(widgetIds) {
		const params = new URLSearchParams()
		widgetIds.forEach(id => params.append('widgets[]', id))
		return axios.get(`${baseUrl}/api/widgets/items?${params.toString()}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	addWidget(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/widgets`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	addTile(dashboardId, data) {
		return axios.post(`${baseUrl}/api/dashboard/${dashboardId}/tile`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateWidgetPlacement(placementId, data) {
		return axios.put(`${baseUrl}/api/widgets/${placementId}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	removeWidget(placementId) {
		return axios.delete(`${baseUrl}/api/widgets/${placementId}`)
	},

	// Conditional rules endpoints
	getWidgetRules(placementId) {
		return axios.get(`${baseUrl}/api/widgets/${placementId}/rules`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	addWidgetRule(placementId, data) {
		return axios.post(`${baseUrl}/api/widgets/${placementId}/rules`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateRule(ruleId, data) {
		return axios.put(`${baseUrl}/api/rules/${ruleId}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteRule(ruleId) {
		return axios.delete(`${baseUrl}/api/rules/${ruleId}`)
	},

	// Admin endpoints
	getAdminTemplates() {
		return axios.get(`${baseUrl}/api/admin/templates`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createAdminTemplate(data) {
		return axios.post(`${baseUrl}/api/admin/templates`, data)
	},

	getAdminTemplate(id) {
		return axios.get(`${baseUrl}/api/admin/templates/${id}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateAdminTemplate(id, data) {
		return axios.put(`${baseUrl}/api/admin/templates/${id}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteAdminTemplate(id) {
		return axios.delete(`${baseUrl}/api/admin/templates/${id}`)
	},

	// Template gallery (REQ-TMPL-014). Returns
	// `{status, templates: [{uuid, name, description, category,
	// previewImage, gridColumns, widgetCount, lastUpdatedAt}]}`.
	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	saveDashboardAsTemplate(dashboardUuid, metadata = {}) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(dashboardUuid)}/save-as-template`,
			metadata,
		)
	},

	// Upload an admin template preview image (REQ-TMPL-017). Admin-only.
	// Body: `{base64: 'data:image/<type>;base64,<bytes>'}`. Reuses the
	// resource-uploads pipeline so the same MIME / size validation applies.
	/** @spec openspec/specs/dashboards/spec.md */
	uploadTemplatePreviewImage(templateUuid, base64) {
		return axios.post(
			`${baseUrl}/api/admin/templates/${encodeURIComponent(templateUuid)}/preview-image`,
			{ base64 },
		)
	},

	getAdminSettings() {
		return axios.get(`${baseUrl}/api/admin/settings`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateAdminSettings(data) {
		return axios.put(`${baseUrl}/api/admin/settings`, data)
	},

	// Admin group-priority order (REQ-ASET-012/013/014).
	// Both endpoints are admin-only on the server side; the UI gates
	// rendering of the section behind the same admin check.
	getAdminGroups() {
		return axios.get(`${baseUrl}/api/admin/groups`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateAdminGroupOrder(groups) {
		return axios.post(`${baseUrl}/api/admin/groups`, { groups })
	},

	// ADR-023 action-authorization matrix. Both endpoints are admin-only
	// on the server side via #[AuthorizedAdminSetting]; the UI gates the
	// section behind the same admin check.
	getActionMatrix() {
		return axios.get(`${baseUrl}/api/admin/action-matrix`)
	},

	/** @spec openspec/architecture/adr-023-action-authorization.md */
	updateActionMatrix(matrix) {
		return axios.put(`${baseUrl}/api/admin/action-matrix`, { matrix })
	},

	// Setup wizard endpoints (REQ-WIZ-008, REQ-WIZ-009, REQ-WIZ-003).
	// All three are admin-only on the server side; the UI gates the
	// banner + modal behind the same check via `getSetupWizardState`.
	getSetupWizardState() {
		return axios.get(`${baseUrl}/api/admin/setup-wizard/state`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
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

	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	confluenceImportDryRun(file) {
		const form = new FormData()
		form.append('file', file)
		return axios.post(
			`${baseUrl}/api/admin/import/confluence/dry-run`,
			form,
			{ headers: { 'Content-Type': 'multipart/form-data' } },
		)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	listDashboardComments(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createDashboardComment(uuid, payload) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments`, payload)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateDashboardComment(uuid, id, payload) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments/${id}`, payload)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteDashboardComment(uuid, id) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/comments/${id}`)
	},

	// Dashboard metadata-fields admin CRUD (REQ-MDFL-001..003).
	getMetadataFields() {
		return axios.get(`${baseUrl}/api/admin/metadata-fields`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createMetadataField(field) {
		return axios.post(`${baseUrl}/api/admin/metadata-fields`, field)
	},

	getMetadataField(id) {
		return axios.get(`${baseUrl}/api/admin/metadata-fields/${id}`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateMetadataField(id, patch) {
		return axios.put(`${baseUrl}/api/admin/metadata-fields/${id}`, patch)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteMetadataField(id, cascade = false) {
		const query = cascade ? '?cascade=true' : ''
		return axios.delete(`${baseUrl}/api/admin/metadata-fields/${id}${query}`)
	},

	// Per-dashboard metadata read/write (REQ-MDFL-004..006, REQ-MDFL-008).
	getDashboardMetadata(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/metadata`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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

	/** @spec openspec/specs/dashboards/spec.md */
	regenerateFeedToken() {
		return axios.post(`${baseUrl}/api/feed/token/regenerate`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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

	/** @spec openspec/specs/dashboards/spec.md */
	updateOrgNavigation(tree, lang = 'nl') {
		return axios.put(`${baseUrl}/api/admin/org-navigation`, { tree }, { params: { lang } })
	},

	getOrgNavigationPosition() {
		return axios.get(`${baseUrl}/api/admin/org-navigation/position`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateOrgNavigationPosition(position) {
		return axios.put(`${baseUrl}/api/admin/org-navigation/position`, { position })
	},

	// Dashboard bulk operations (REQ-BULK-001..011). All four endpoints
	// are admin-only on the server side; the UI gates the controls behind
	// the same admin check. Each endpoint accepts `dryRun` to preview
	// outcomes without mutating the database, and returns
	// `{deletedCount|movedCount|updatedCount|reindexedCount, skippedCount, errors, dryRun}`
	// (or the `wouldX` variants in dry-run mode).
	/** @spec openspec/specs/dashboards/spec.md */
	bulkDeleteDashboards(dashboardUuids, { dryRun = false, cascade = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-delete`, {
			dashboardUuids,
			dryRun,
			cascade,
		})
	},

	/** @spec openspec/specs/dashboards/spec.md */
	bulkMoveDashboards(dashboardUuids, parentUuid, { dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-move`, {
			dashboardUuids,
			parentUuid,
			dryRun,
		})
	},

	/** @spec openspec/specs/dashboards/spec.md */
	bulkStatusDashboards(dashboardUuids, publicationStatus, { publishAt = null, dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-status`, {
			dashboardUuids,
			publicationStatus,
			publishAt,
			dryRun,
		})
	},

	/** @spec openspec/specs/dashboards/spec.md */
	bulkReindexDashboards(dashboardUuids, { dryRun = false } = {}) {
		return axios.post(`${baseUrl}/api/admin/dashboards/bulk-reindex`, {
			dashboardUuids,
			dryRun,
		})
	},

	// Demo showcase install / list / uninstall (REQ-DEMO-002..006).
	// All three endpoints are admin-only on the server side; the UI
	// only renders the controls behind the same admin check.
	/** @spec openspec/specs/dashboards/spec.md */
	listDemoShowcases() {
		return axios.get(`${baseUrl}/api/admin/demo-showcases`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	installDemoShowcase(id, { lang = 'nl', force = false } = {}) {
		const params = { lang }
		if (force) {
			params.force = true
		}
		return axios.post(`${baseUrl}/api/admin/demo-showcases/${encodeURIComponent(id)}/install`, null, { params })
	},

	/** @spec openspec/specs/dashboards/spec.md */
	uninstallDemoShowcase(id) {
		return axios.delete(`${baseUrl}/api/admin/demo-showcases/${encodeURIComponent(id)}`)
	},

	// Tile endpoints
	getTiles() {
		return axios.get(`${baseUrl}/api/tiles`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createTile(data) {
		return axios.post(`${baseUrl}/api/tiles`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateTile(id, data) {
		return axios.put(`${baseUrl}/api/tiles/${id}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteTile(id) {
		return axios.delete(`${baseUrl}/api/tiles/${id}`)
	},

	// Dashboard reaction endpoints (REQ-RXN-001..004).
	getDashboardReactions(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	addDashboardReaction(uuid, emoji) {
		return axios.post(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions`,
			{ emoji },
		)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	removeDashboardReaction(uuid, emoji) {
		return axios.delete(
			`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/reactions/${encodeURIComponent(emoji)}`,
		)
	},

	/** @spec openspec/specs/dashboards/spec.md */
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
	/** @spec openspec/specs/dashboards/spec.md */
	listDashboardTranslations(uuid) {
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations`)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	createDashboardTranslation(uuid, data) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	updateDashboardTranslation(uuid, lang, data) {
		return axios.put(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}`, data)
	},

	/** @spec openspec/specs/dashboards/spec.md */
	deleteDashboardTranslation(uuid, lang) {
		return axios.delete(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}`)
	},

	setDashboardTranslationPrimary(uuid, lang) {
		return axios.post(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/translations/${encodeURIComponent(lang)}/set-primary`)
	},

	// Resolve the dashboard for the viewer's locale; optional `?lang=`
	// query parameter overrides the user's Nextcloud locale.
	/** @spec openspec/specs/dashboards/spec.md */
	getResolvedDashboard(uuid, lang) {
		const params = (lang !== undefined && lang !== null && lang !== '') ? { params: { lang } } : {}
		return axios.get(`${baseUrl}/api/dashboards/${encodeURIComponent(uuid)}/resolved`, params)
	},
}
