<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<footer
		v-if="showFooter"
		class="mydash-footer"
		:style="footerStyle"
		role="contentinfo">
		<!-- HTML mode (REQ-FTR-002): server-sanitised payload rendered
		     directly. The string already passed FooterService::sanitiseHtml
		     server-side so v-html is the documented integration point. -->
		<div
			v-if="resolvedHtml !== null"
			class="mydash-footer__html"
			v-html="resolvedHtml" />

		<!-- Structured mode (REQ-FTR-003 / REQ-FTR-004). Layout switches
		     on the layoutMode field — `columns` for a 3-column grid,
		     `inline` for a single horizontal row. -->
		<div
			v-else-if="resolvedConfig"
			class="mydash-footer__config"
			:class="{
				'mydash-footer__config--columns': layoutMode === 'columns',
				'mydash-footer__config--inline': layoutMode === 'inline',
			}">
			<div v-if="resolvedConfig.logoUrl || resolvedConfig.organisation" class="mydash-footer__col">
				<img
					v-if="resolvedConfig.logoUrl"
					class="mydash-footer__logo"
					:src="resolvedConfig.logoUrl"
					alt="">
				<div v-if="orgName" class="mydash-footer__org">
					{{ orgName }}
				</div>
			</div>
			<div v-if="addressLines.length > 0" class="mydash-footer__col mydash-footer__address">
				<div v-for="(line, idx) in addressLines" :key="idx">
					{{ line }}
				</div>
			</div>
			<div v-if="hasLinksOrLegal" class="mydash-footer__col">
				<ul v-if="(resolvedConfig.links || []).length > 0" class="mydash-footer__links">
					<li v-for="(link, idx) in resolvedConfig.links" :key="idx">
						<a :href="link.url" rel="noopener noreferrer" target="_blank">{{ link.label }}</a>
					</li>
				</ul>
				<div v-if="legalText" class="mydash-footer__legal">
					{{ legalText }}
					<template v-if="resolvedConfig.copyrightYear">
						© {{ resolvedConfig.copyrightYear }}
					</template>
				</div>
			</div>
		</div>
	</footer>
</template>

<script>
/**
 * DashboardFooter — renders the resolved instance footer below the
 * dashboard grid. Hidden when `footer` is null (REQ-FTR-001 disabled
 * scenario, REQ-FTR-006 hidden mode).
 *
 * Props:
 *  - `footer` (Object|null) — payload from `FooterService::resolveFooterForDashboard`:
 *    `{ mode, html, config, backgroundColor, textColor }`. NULL means
 *    no footer renders.
 *  - `locale` (String) — the viewer's NC locale; used to pick a variant
 *    when `footer.html` or any structured-config field is a language map.
 *
 * Localisation (REQ-FTR-007):
 *  - Three-step fallback: viewer locale → first key → empty string.
 *  - The dashboard's primary-language fallback (step 2 of D3) is left
 *    to the parent that knows the dashboard context; this component
 *    ships locale + first-key for the read path.
 *
 * Print stylesheet (REQ-FTR-008): the footer renders unchanged in
 * `@media print`; no `display: none` is applied.
 *
 * Theme awareness (REQ-FTR-009): the inline `style` binds the optional
 * admin colour overrides; when null the CSS falls through to the NC
 * theme variables (`--color-main-background`, `--color-main-text`).
 */
export default {
	name: 'DashboardFooter',

	props: {
		footer: {
			type: Object,
			default: null,
		},
		locale: {
			type: String,
			default: 'en',
		},
	},

	computed: {
		showFooter() {
			if (!this.footer) {
				return false
			}
			return this.resolvedHtml !== null || this.resolvedConfig !== null
		},

		resolvedHtml() {
			if (!this.footer) {
				return null
			}
			const { html } = this.footer
			if (html === null || html === undefined || html === '') {
				return null
			}
			if (typeof html === 'string') {
				return html
			}
			if (typeof html === 'object') {
				return this.pickVariant(html)
			}
			return null
		},

		resolvedConfig() {
			if (!this.footer) {
				return null
			}
			const config = this.footer.config
			if (!config || typeof config !== 'object') {
				return null
			}
			if (Object.keys(config).length === 0) {
				return null
			}
			// Pick locale variants on each leaf field that holds a
			// language-tagged map (REQ-FTR-007 structured variant scenario).
			const out = {}
			for (const key of Object.keys(config)) {
				const value = config[key]
				if (value && typeof value === 'object' && !Array.isArray(value) && !('label' in value)) {
					// Plain locale map — pick a variant.
					out[key] = this.pickVariant(value)
				} else {
					out[key] = value
				}
			}
			return out
		},

		layoutMode() {
			if (!this.resolvedConfig) {
				return 'columns'
			}
			return this.resolvedConfig.layoutMode === 'inline' ? 'inline' : 'columns'
		},

		orgName() {
			if (!this.resolvedConfig) {
				return ''
			}
			return this.resolvedConfig.organisation || ''
		},

		addressLines() {
			if (!this.resolvedConfig || !this.resolvedConfig.address) {
				return []
			}
			return String(this.resolvedConfig.address)
				.split('\n')
				.map((l) => l.trim())
				.filter((l) => l.length > 0)
		},

		legalText() {
			if (!this.resolvedConfig) {
				return ''
			}
			return this.resolvedConfig.legal || ''
		},

		hasLinksOrLegal() {
			if (!this.resolvedConfig) {
				return false
			}
			const hasLinks = Array.isArray(this.resolvedConfig.links) && this.resolvedConfig.links.length > 0
			const hasLegal = Boolean(this.legalText) || Boolean(this.resolvedConfig.copyrightYear)
			return hasLinks || hasLegal
		},

		footerStyle() {
			const style = {}
			if (this.footer && this.footer.backgroundColor) {
				style.background = this.footer.backgroundColor
			}
			if (this.footer && this.footer.textColor) {
				style.color = this.footer.textColor
			}
			return style
		},
	},

	methods: {
		/**
		 * Locale fallback (REQ-FTR-007) — viewer locale → first key →
		 * empty string. The parent layer is responsible for inserting the
		 * dashboard primary-language fallback before this component sees
		 * the map.
		 * @param map
		 */
		pickVariant(map) {
			if (!map || typeof map !== 'object') {
				return ''
			}
			if (Object.prototype.hasOwnProperty.call(map, this.locale)) {
				return map[this.locale]
			}
			const keys = Object.keys(map)
			if (keys.length === 0) {
				return ''
			}
			return map[keys[0]]
		},
	},
}
</script>

<style scoped>
.mydash-footer {
	width: 100%;
	padding: 16px 24px;
	margin-top: 24px;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border-top: 1px solid var(--color-border, #e0e0e0);
	font-size: 0.9em;
	line-height: 1.45;
	contain: layout paint;
}

.mydash-footer__html :deep(a) {
	color: var(--color-primary-element, #1976d2);
}

.mydash-footer__config--columns {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 2rem;
}

.mydash-footer__config--inline {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 1rem;
}

.mydash-footer__config--inline .mydash-footer__col + .mydash-footer__col::before {
	content: '·';
	margin-right: 1rem;
	opacity: 0.5;
}

.mydash-footer__logo {
	max-height: 48px;
	max-width: 200px;
	display: block;
	margin-bottom: 6px;
}

.mydash-footer__org {
	font-weight: 600;
}

.mydash-footer__links {
	list-style: none;
	margin: 0;
	padding: 0;
}

.mydash-footer__links li {
	margin-bottom: 4px;
}

.mydash-footer__links a {
	color: inherit;
	text-decoration: underline;
}

.mydash-footer__legal {
	margin-top: 6px;
	opacity: 0.85;
}

@media (max-width: 720px) {
	.mydash-footer__config--columns {
		grid-template-columns: 1fr;
	}
}

/* REQ-FTR-008 — explicitly opt the footer INTO print output. NC's
   default print stylesheet hides large chrome regions; we want the
   footer to survive the export so legal disclaimers stay visible. */
@media print {
	.mydash-footer {
		display: block !important;
		break-inside: avoid;
		page-break-inside: avoid;
	}
}
</style>
