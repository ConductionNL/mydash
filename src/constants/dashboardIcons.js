/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Dashboard icons registry — capability `dashboard-icons`
 *
 * Curated registry of built-in Material Design Icons used across MyDash
 * dashboard surfaces (sidebar switcher, admin list, tile editor). The
 * `icon` field on a dashboard record may hold one of three values:
 *
 *   - `null` / `''` — render the `DEFAULT_ICON`
 *   - A registry key (e.g. `'ViewDashboard'`) — looked up in `DASHBOARD_ICONS`
 *   - A URL (starts with `/` or `http`) — handled by the sibling capability
 *     `custom-icon-upload-pattern`. Use `isCustomIconUrl()` as the
 *     discriminator.
 *
 * REQ-ICON-001: Curated registry of at least 15 named built-in icons.
 * REQ-ICON-002: `getIconComponent` MUST tolerate null/undefined/empty/unknown
 *               and resolve to `DEFAULT_ICON` without throwing.
 * REQ-ICON-003: Admin pickers MUST enumerate options from
 *               `Object.keys(DASHBOARD_ICONS)` to stay in lock-step.
 * REQ-ICON-004: Each icon MUST be a separate `import` (no wildcard / barrel)
 *               to keep the production bundle tree-shake-friendly.
 */

import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import HeartIcon from 'vue-material-design-icons/Heart.vue'
import BookOpenVariantIcon from 'vue-material-design-icons/BookOpenVariant.vue'
import LightbulbIcon from 'vue-material-design-icons/Lightbulb.vue'
import RocketLaunchIcon from 'vue-material-design-icons/RocketLaunch.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import BriefcaseIcon from 'vue-material-design-icons/Briefcase.vue'

/**
 * Map of icon registry name → Vue component reference.
 *
 * The keys are the canonical strings persisted on `dashboards.icon`.
 * Iteration order is the order options should appear in pickers.
 *
 * @type {Record<string, object>}
 */
export const DASHBOARD_ICONS = Object.freeze({
	ViewDashboard: ViewDashboardIcon,
	Home: HomeIcon,
	ChartBar: ChartBarIcon,
	Cog: CogIcon,
	AccountGroup: AccountGroupIcon,
	Calendar: CalendarIcon,
	FileDocument: FileDocumentIcon,
	Bell: BellIcon,
	Star: StarIcon,
	Heart: HeartIcon,
	BookOpenVariant: BookOpenVariantIcon,
	Lightbulb: LightbulbIcon,
	RocketLaunch: RocketLaunchIcon,
	Earth: EarthIcon,
	Briefcase: BriefcaseIcon,
})

/**
 * The fallback icon name used when no icon is set or the requested name
 * is not in the registry.
 *
 * @type {string}
 */
export const DEFAULT_ICON = 'ViewDashboard'

// Module-load assertion — guarantees the default is always resolvable.
// REQ-ICON-001 scenario: "Default icon is 'ViewDashboard'".
if (!DASHBOARD_ICONS[DEFAULT_ICON]) {
	throw new Error(
		`dashboardIcons: DEFAULT_ICON "${DEFAULT_ICON}" is not present in DASHBOARD_ICONS`,
	)
}

/**
 * Resolve an icon name to a Vue component reference.
 *
 * Returns null when the name is a URL (per REQ-ICON-006) — callers must
 * render via `<img>` in that case. For registry names, tolerates null,
 * undefined, empty string, and unknown names — all resolve to
 * `DASHBOARD_ICONS[DEFAULT_ICON]`. Never throws on non-URL inputs.
 *
 * @param {string|null|undefined} name - Icon registry key, URL, or null/empty.
 * @return {object|null} A Vue component suitable for `<component :is>`, or null if name is a URL.
 */
export function getIconComponent(name) {
	// URL inputs return null — caller must use <img> instead
	if (isCustomIconUrl(name)) {
		return null
	}

	// Registry names or null/empty → resolve to DEFAULT_ICON
	if (typeof name !== 'string' || name.length === 0) {
		return DASHBOARD_ICONS[DEFAULT_ICON]
	}
	return DASHBOARD_ICONS[name] || DASHBOARD_ICONS[DEFAULT_ICON]
}

/**
 * Discriminator for the `icon` field — true when the value should be
 * rendered as an `<img>` (a URL) rather than looked up in the registry.
 *
 * The URL branch itself is implemented by the sibling capability
 * `custom-icon-upload-pattern`. This helper is exported here so that
 * any consumer (including this capability's own `IconRenderer`) can
 * safely branch without depending on the upload code.
 *
 * @param {string|null|undefined} name - Value from `dashboards.icon`.
 * @return {boolean} True if `name` is a non-empty string starting with
 *                   `/` or `http`.
 */
export function isCustomIconUrl(name) {
	if (typeof name !== 'string' || name.length === 0) {
		return false
	}
	return name.startsWith('/') || name.startsWith('http')
}
