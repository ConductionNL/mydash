<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('mydash', 'Template gallery') }}</h1>
			<div class="template-gallery__filters">
				<label>
					<span>{{ t('mydash', 'Category') }}</span>
					<select :value="store.selectedCategory ?? ''" @change="onCategoryChange">
						<option value="">
							{{ t('mydash', 'All categories') }}
						</option>
						<option v-for="cat in store.categories" :key="cat" :value="cat">
							{{ cat }}
						</option>
					</select>
				</label>
				<label>
					<span>{{ t('mydash', 'Sort by') }}</span>
					<select :value="store.sortBy" @change="onSortChange">
						<option value="name">
							{{ t('mydash', 'Name') }}
						</option>
						<option value="updatedAt">
							{{ t('mydash', 'Recently updated') }}
						</option>
					</select>
				</label>
			</div>
		</header>
		<p v-if="store.loading">
			{{ t('mydash', 'Loading…') }}
		</p>
		<p v-else-if="store.galleryTemplates.length === 0">
			{{ t('mydash', 'No templates available') }}
		</p>
		<div v-else class="template-gallery__grid">
			<TemplateCard
				v-for="template in store.galleryTemplates"
				:key="template.uuid"
				:template="template" />
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import TemplateCard from '../components/TemplateCard.vue'
import { useTemplatesStore } from '../stores/templates.js'

export default {
	name: 'TemplateGallery',
	components: { TemplateCard },
	/** @spec openspec/specs/admin-templates/spec.md */
	setup() {
		const store = useTemplatesStore()
		return { store }
	},
	mounted() {
		this.store.fetchGallery()
	},
	methods: {
		t,
		/** @spec openspec/specs/admin-templates/spec.md */
		onCategoryChange(event) {
			const value = event.target.value || null
			this.store.fetchGallery({ category: value, sort: this.store.sortBy })
		},
		/** @spec openspec/specs/admin-templates/spec.md */
		onSortChange(event) {
			this.store.fetchGallery({
				category: this.store.selectedCategory,
				sort: event.target.value,
			})
		},
	},
}
</script>

<style scoped>
.template-gallery__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	margin-bottom: 16px;
}

.template-gallery__filters {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.template-gallery__filters label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.875rem;
}

.template-gallery__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
}
</style>
