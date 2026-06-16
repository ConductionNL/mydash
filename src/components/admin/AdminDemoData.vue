<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mydash-demo-showcases">
		<h3>{{ t('mydash', 'Demo data showcases') }}</h3>

		<p class="mydash-demo-showcases__hint">
			{{ t('mydash', 'Install bundled example dashboards to give users a working starting point. Each showcase is created as a group-shared dashboard visible to all users; you can uninstall it at any time.') }}
		</p>

		<div v-if="loading" class="mydash-demo-showcases__loading">
			{{ t('mydash', 'Loading showcases…') }}
		</div>

		<div v-else-if="error" class="mydash-demo-showcases__error">
			{{ error }}
		</div>

		<div v-else class="mydash-demo-showcases__grid">
			<div
				v-for="showcase in showcases"
				:key="showcase.id"
				class="mydash-demo-showcases__card"
				:data-test="'showcase-card-' + showcase.id">
				<div class="mydash-demo-showcases__thumb">
					<img
						v-if="showcase.thumbnailUrl"
						:src="showcase.thumbnailUrl"
						:alt="showcase.name"
						@error="onThumbError($event)">
					<ViewDashboard v-else :size="64" />
				</div>

				<div class="mydash-demo-showcases__body">
					<div class="mydash-demo-showcases__title-row">
						<strong class="mydash-demo-showcases__title">{{ showcase.name }}</strong>
						<span class="mydash-demo-showcases__lang-badge">{{ showcase.language.toUpperCase() }}</span>
					</div>

					<p class="mydash-demo-showcases__desc">
						{{ showcase.description }}
					</p>

					<div v-if="warnings[showcase.id]" class="mydash-demo-showcases__warning">
						{{ t('mydash', 'Installed but skipped widgets: {list}', { list: warnings[showcase.id].join(', ') }) }}
					</div>

					<div class="mydash-demo-showcases__actions">
						<NcButton
							v-if="!showcase.isInstalled"
							type="primary"
							:disabled="busy[showcase.id]"
							:data-test="'showcase-install-' + showcase.id"
							@click="install(showcase)">
							{{ busy[showcase.id] ? t('mydash', 'Installing…') : t('mydash', 'Install') }}
						</NcButton>
						<NcButton
							v-else
							type="error"
							:disabled="busy[showcase.id]"
							:data-test="'showcase-uninstall-' + showcase.id"
							@click="confirmUninstall(showcase)">
							{{ busy[showcase.id] ? t('mydash', 'Uninstalling…') : t('mydash', 'Uninstall') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@conduction/nextcloud-vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import { api } from '../../services/api.js'

export default {
	name: 'AdminDemoData',

	components: {
		NcButton,
		ViewDashboard,
	},

	data() {
		return {
			loading: false,
			error: '',
			showcases: [],
			busy: {},
			warnings: {},
		}
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/** @spec openspec/specs/demo-data-showcases/spec.md */
		async fetch() {
			this.loading = true
			this.error = ''
			try {
				const response = await api.listDemoShowcases()
				this.showcases = response.data || []
			} catch (err) {
				this.error = this.t('mydash', 'Could not load demo showcases. Please try again.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/demo-data-showcases/spec.md */
		async install(showcase) {
			this.$set(this.busy, showcase.id, true)
			this.$delete(this.warnings, showcase.id)
			try {
				const response = await api.installDemoShowcase(showcase.id)
				const skipped = (response.data && response.data.skippedWidgets) || []
				if (skipped.length > 0) {
					this.$set(this.warnings, showcase.id, skipped)
				}

				await this.fetch()
			} catch (err) {
				if (err.response && err.response.status === 404) {
					this.error = this.t('mydash', 'Showcase not found.')
				} else if (err.response && err.response.status === 403) {
					this.error = this.t('mydash', 'You need admin privileges to install showcases.')
				} else {
					this.error = this.t('mydash', 'Could not install showcase. Please try again.')
				}
			} finally {
				this.$set(this.busy, showcase.id, false)
			}
		},

		/** @spec openspec/specs/demo-data-showcases/spec.md */
		async confirmUninstall(showcase) {
			const message = this.t('mydash', 'Remove the {name} showcase dashboard for all users? You can reinstall it later.', { name: showcase.name })
			if (window.confirm(message) === false) {
				return
			}

			this.$set(this.busy, showcase.id, true)
			try {
				await api.uninstallDemoShowcase(showcase.id)
				this.$delete(this.warnings, showcase.id)
				await this.fetch()
			} catch (err) {
				this.error = this.t('mydash', 'Could not uninstall showcase. Please try again.')
			} finally {
				this.$set(this.busy, showcase.id, false)
			}
		},

		/** @spec openspec/specs/demo-data-showcases/spec.md */
		onThumbError(event) {
			// Hide broken images gracefully — fall back to the icon.
			event.target.style.display = 'none'
		},
	},
}
</script>

<style scoped>
.mydash-demo-showcases__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.mydash-demo-showcases__loading,
.mydash-demo-showcases__error {
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.mydash-demo-showcases__error {
	color: var(--color-error);
}

.mydash-demo-showcases__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.mydash-demo-showcases__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
}

.mydash-demo-showcases__thumb {
	width: 100%;
	aspect-ratio: 3 / 2;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.mydash-demo-showcases__thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.mydash-demo-showcases__body {
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	flex: 1 1 auto;
}

.mydash-demo-showcases__title-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.mydash-demo-showcases__title {
	font-size: 1rem;
}

.mydash-demo-showcases__lang-badge {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.mydash-demo-showcases__desc {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	flex: 1 1 auto;
}

.mydash-demo-showcases__warning {
	font-size: 0.75rem;
	padding: 6px 8px;
	background: var(--color-warning-hover, rgba(255, 200, 0, 0.15));
	border-radius: var(--border-radius);
}

.mydash-demo-showcases__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
