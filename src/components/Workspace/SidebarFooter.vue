<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	SidebarFooter — capability `dashboard-switcher`

	Persistent footer block rendered at the bottom of the dashboard-switcher
	sidebar (REQ-SWITCH-009). Contains:

	  1. A "Powered by" caption with two brand logos:
	     - Sendent  → https://sendent.com
	     - Conduction → https://conduction.nl
	     Both wrapped in `<a target="_blank" rel="noopener noreferrer">`
	     (security gate — never omit `noopener noreferrer`).
	  2. A Documentation link (icon + label) targeting the same URL the
	     gear-menu Documentation entry used before runtime-shell-trim
	     removed it (https://mydash.app).

	The footer itself is a stateless block; the parent
	(`DashboardSwitcherSidebar`) owns the `position: sticky; bottom: 0`
	wrapper so the footer pins to the bottom of the viewport while the
	dashboards list above scrolls.
-->

<template>
	<footer
		class="dashboard-switcher-sidebar-footer"
		data-testid="sidebar-footer">
		<a
			class="dashboard-switcher-sidebar-footer__doc-link"
			:href="docsUrl"
			target="_blank"
			rel="noopener noreferrer">
			<BookOpenVariantOutline :size="18" />
			<span class="dashboard-switcher-sidebar-footer__doc-label">
				{{ t('mydash', 'Documentation') }}
			</span>
		</a>

		<div class="dashboard-switcher-sidebar-footer__brand">
			<span class="dashboard-switcher-sidebar-footer__brand-caption">
				{{ t('mydash', 'Powered by') }}
			</span>
			<div class="dashboard-switcher-sidebar-footer__brand-logos">
				<a
					class="dashboard-switcher-sidebar-footer__brand-link"
					href="https://sendent.com"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="Sendent">
					<img
						:src="sendentLogo"
						alt="Sendent"
						class="dashboard-switcher-sidebar-footer__brand-image">
				</a>
				<a
					class="dashboard-switcher-sidebar-footer__brand-link"
					href="https://conduction.nl"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="Conduction">
					<img
						:src="conductionLogo"
						alt="Conduction"
						class="dashboard-switcher-sidebar-footer__brand-image dashboard-switcher-sidebar-footer__brand-image--invert">
				</a>
			</div>
		</div>
	</footer>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'

import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'

/**
 * Documentation URL targeted by both the (now-removed) gear menu entry
 * and the new sidebar footer link. Kept in module scope so the test
 * suite can assert exact value parity with the previous gear-menu link.
 */
export const DOCS_URL = 'https://mydash.app'

export default {
	name: 'SidebarFooter',

	components: {
		BookOpenVariantOutline,
	},

	computed: {
		docsUrl() {
			return DOCS_URL
		},
		sendentLogo() {
			return generateFilePath('mydash', 'img', 'sendent-logo.png')
		},
		conductionLogo() {
			return generateFilePath('mydash', 'img', 'conduction-logo.png')
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.dashboard-switcher-sidebar-footer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
	background: var(--color-main-background, #fff);
	border-top: 1px solid var(--color-border, #e0e0e0);
}

.dashboard-switcher-sidebar-footer__doc-link {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: 4px;
	color: var(--color-main-text, #222);
	text-decoration: none;
	font-size: 13px;
	transition: background-color 0.15s ease;
}

.dashboard-switcher-sidebar-footer__doc-link:hover,
.dashboard-switcher-sidebar-footer__doc-link:focus {
	background: var(--color-background-hover, #f5f5f5);
	outline: none;
	text-decoration: none;
}

.dashboard-switcher-sidebar-footer__doc-label {
	font-weight: 500;
}

.dashboard-switcher-sidebar-footer__brand {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
}

.dashboard-switcher-sidebar-footer__brand-caption {
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.5px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast, #757575);
}

.dashboard-switcher-sidebar-footer__brand-logos {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: nowrap;
	gap: 12px;
	width: 100%;
	min-width: 0;
}

.dashboard-switcher-sidebar-footer__brand-link {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 1 auto;
	min-width: 0;
	padding: 2px 4px;
	border-radius: 4px;
	transition: background-color 0.15s ease;
}

.dashboard-switcher-sidebar-footer__brand-link:hover,
.dashboard-switcher-sidebar-footer__brand-link:focus {
	background: var(--color-background-hover, #f5f5f5);
	outline: none;
}

.dashboard-switcher-sidebar-footer__brand-image {
	height: 16px;
	width: auto;
	max-width: 100%;
	object-fit: contain;
	display: block;
}

/* Conduction wordmark renders dark on dark NC themes — invert so it
   reads on either background. */
.dashboard-switcher-sidebar-footer__brand-image--invert {
	filter: var(--background-invert-if-dark, none);
}
</style>
