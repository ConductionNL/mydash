<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article class="template-card">
		<div class="template-card__preview">
			<img
				v-if="template.previewImage"
				:src="template.previewImage"
				:alt="t('mydash', 'Preview image')"
				class="template-card__preview-img">
			<div v-else class="template-card__preview-placeholder">
				<span aria-hidden="true">📊</span>
			</div>
		</div>
		<div class="template-card__body">
			<h3 class="template-card__title">
				{{ template.name }}
			</h3>
			<p v-if="template.description" class="template-card__description">
				{{ template.description }}
			</p>
			<div class="template-card__meta">
				<span v-if="template.category" class="template-card__category">
					{{ template.category }}
				</span>
				<span class="template-card__widget-count">
					{{ widgetCountLabel }}
				</span>
				<span v-if="template.lastUpdatedAt" class="template-card__updated">
					{{ template.lastUpdatedAt }}
				</span>
			</div>
		</div>
	</article>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'TemplateCard',
	props: {
		template: {
			type: Object,
			required: true,
		},
	},
	computed: {
		/** @spec openspec/specs/admin-templates/spec.md */
		widgetCountLabel() {
			return t('mydash', '{count} widgets', {
				count: this.template.widgetCount ?? 0,
			})
		},
	},
	methods: {
		t,
	},
}
</script>

<style scoped>
.template-card {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
	background: var(--color-main-background, #fff);
}

.template-card__preview {
	aspect-ratio: 16 / 9;
	background: var(--color-background-dark, #f5f5f5);
	display: flex;
	align-items: center;
	justify-content: center;
}

.template-card__preview-img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.template-card__preview-placeholder {
	font-size: 2.5rem;
	opacity: 0.4;
}

.template-card__body {
	padding: 12px;
}

.template-card__title {
	margin: 0 0 4px;
	font-size: 1rem;
}

.template-card__description {
	margin: 0 0 8px;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast, #555);
}

.template-card__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast, #555);
}

.template-card__category {
	background: var(--color-primary-light, #e6f0fa);
	padding: 2px 6px;
	border-radius: 4px;
}
</style>
