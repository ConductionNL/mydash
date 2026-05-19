<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="group-priority">
		<div class="group-priority__header">
			<h3>{{ t('mydash', 'Group priority order') }}</h3>
			<p class="group-priority__hint">
				{{ t('mydash', 'Drag groups between the columns to control which Nextcloud groups MyDash uses, and in what order. The first active group becomes the user\'s primary workspace.') }}
			</p>
		</div>

		<div class="group-priority__columns">
			<!-- ACTIVE column -->
			<div class="group-priority__column" data-column="active">
				<div class="group-priority__column-header">
					<h4>{{ t('mydash', 'Active groups') }}</h4>
					<span class="group-priority__count">{{ active.length }}</span>
				</div>
				<NcTextField
					:value="activeFilter"
					:label="t('mydash', 'Filter active groups')"
					:placeholder="t('mydash', 'Filter')"
					class="group-priority__filter"
					@update:value="activeFilter = $event" />

				<ul
					class="group-priority__list group-priority__list--active"
					data-test="group-priority-active"
					@dragover.prevent="onDragOver($event, 'active')"
					@drop.prevent="onDrop($event, 'active')">
					<li
						v-for="(id, index) in filteredActive"
						:key="`active-${id}`"
						:draggable="!loading"
						class="group-priority__item"
						:class="{ 'group-priority__item--stale': isStale(id) }"
						:data-test-id="id"
						@dragstart="onDragStart($event, id, 'active', index)"
						@dragover.prevent="onItemDragOver($event, index, 'active')"
						@drop.prevent.stop="onItemDrop($event, index, 'active')">
						<span class="group-priority__handle" aria-hidden="true">⋮⋮</span>
						<span class="group-priority__label">{{ displayName(id) }}<span
							v-if="isStale(id)"
							class="group-priority__stale-affix"> {{ t('mydash', '(removed)') }}</span></span>
						<NcButton
							type="tertiary"
							:aria-label="t('mydash', 'Move to inactive')"
							class="group-priority__move"
							@click="moveToInactive(id)">
							→
						</NcButton>
					</li>
					<li v-if="filteredActive.length === 0" class="group-priority__empty">
						{{ activeFilter ? t('mydash', 'No matches.') : t('mydash', 'No active groups. Drag groups here from the inactive column.') }}
					</li>
				</ul>
			</div>

			<!-- INACTIVE column -->
			<div class="group-priority__column" data-column="inactive">
				<div class="group-priority__column-header">
					<h4>{{ t('mydash', 'Inactive groups') }}</h4>
					<span class="group-priority__count">{{ inactive.length }}</span>
				</div>
				<NcTextField
					:value="inactiveFilter"
					:label="t('mydash', 'Filter inactive groups')"
					:placeholder="t('mydash', 'Filter')"
					class="group-priority__filter"
					@update:value="inactiveFilter = $event" />

				<ul
					class="group-priority__list group-priority__list--inactive"
					data-test="group-priority-inactive"
					@dragover.prevent="onDragOver($event, 'inactive')"
					@drop.prevent="onDrop($event, 'inactive')">
					<li
						v-for="id in filteredInactive"
						:key="`inactive-${id}`"
						:draggable="!loading"
						class="group-priority__item"
						:data-test-id="id"
						@dragstart="onDragStart($event, id, 'inactive', null)">
						<span class="group-priority__handle" aria-hidden="true">⋮⋮</span>
						<span class="group-priority__label">{{ displayName(id) }}</span>
						<NcButton
							type="tertiary"
							:aria-label="t('mydash', 'Move to active')"
							class="group-priority__move"
							@click="moveToActive(id)">
							←
						</NcButton>
					</li>
					<li v-if="filteredInactive.length === 0" class="group-priority__empty">
						{{ inactiveFilter ? t('mydash', 'No matches.') : t('mydash', 'No inactive groups.') }}
					</li>
				</ul>
			</div>
		</div>

		<p v-if="loading" class="group-priority__status">
			{{ t('mydash', 'Loading group list…') }}
		</p>
		<p v-else-if="saving" class="group-priority__status">
			{{ t('mydash', 'Saving…') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { api } from '../../services/api.js'

/**
 * Two-list drag-and-drop component for the admin group priority order
 * (REQ-ASET-012, REQ-ASET-013, REQ-ASET-014).
 *
 * Auto-saves on every drag with a 300ms debounce so admins don't have
 * to click a Save button. Native HTML5 drag-and-drop is used to keep
 * the dependency footprint flat (no new third-party libs).
 */
export default {
	name: 'GroupPriorityOrder',

	components: {
		NcButton,
		NcTextField,
	},

	props: {
		// Optional initial seed for the active list (admin initial-state).
		// `loadGroups()` always overwrites with the API-truth at mount.
		initialActive: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			active: [...this.initialActive],
			inactive: [],
			allKnown: [],
			activeFilter: '',
			inactiveFilter: '',
			loading: true,
			saving: false,
			saveTimer: null,
			// Tracks the in-flight drag so item drops can know origin.
			dragState: null,
		}
	},

	computed: {
		// Map id → displayName for fast O(1) label lookups.
		displayNameMap() {
			const map = {}
			for (const row of this.allKnown) {
				map[row.id] = row.displayName
			}
			return map
		},
		// Set of every known group id; anything in `active` not in here
		// renders as a stale "(removed)" entry per REQ-ASET-013.
		knownIdSet() {
			return new Set(this.allKnown.map((row) => row.id))
		},
		filteredActive() {
			return this.applyFilter(this.active, this.activeFilter)
		},
		filteredInactive() {
			return this.applyFilter(this.inactive, this.inactiveFilter)
		},
	},

	async created() {
		await this.loadGroups()
	},

	beforeDestroy() {
		if (this.saveTimer) {
			clearTimeout(this.saveTimer)
		}
	},

	methods: {
		async loadGroups() {
			this.loading = true
			try {
				const res = await api.getAdminGroups()
				const data = res?.data || {}
				this.active = Array.isArray(data.active) ? data.active : []
				this.inactive = Array.isArray(data.inactive) ? data.inactive : []
				this.allKnown = Array.isArray(data.allKnown) ? data.allKnown : []
			} catch (error) {
				console.error('Failed to load admin groups:', error)
				showError(this.t('mydash', 'Failed to load group list.'))
			} finally {
				this.loading = false
			}
		},

		applyFilter(list, filter) {
			const f = (filter || '').trim().toLowerCase()
			if (f === '') return list
			return list.filter((id) => {
				const name = (this.displayNameMap[id] || id).toLowerCase()
				return name.includes(f) || id.toLowerCase().includes(f)
			})
		},

		displayName(id) {
			return this.displayNameMap[id] || id
		},

		isStale(id) {
			return this.knownIdSet.has(id) === false
		},

		// --- Drag-and-drop handlers (native HTML5) ---

		onDragStart(event, id, column, index) {
			this.dragState = { id, fromColumn: column, fromIndex: index }
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
				event.dataTransfer.setData('text/plain', id)
			}
		},

		onDragOver(event, _column) {
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'move'
			}
		},

		onItemDragOver(event, _index, _column) {
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'move'
			}
		},

		// Drop on empty space inside a column → append at the end.
		onDrop(event, toColumn) {
			if (!this.dragState) return
			const { id, fromColumn } = this.dragState
			this.dragState = null
			if (fromColumn === toColumn && toColumn === 'inactive') {
				// Inactive is server-sorted; intra-list reorder is a no-op.
				return
			}
			this.moveBetweenColumns(id, fromColumn, toColumn, null)
		},

		// Drop directly on another item → insert before that item.
		onItemDrop(event, targetIndex, toColumn) {
			if (!this.dragState) return
			const { id, fromColumn, fromIndex } = this.dragState
			this.dragState = null

			if (fromColumn === toColumn && toColumn === 'active') {
				// Reorder within active.
				if (fromIndex === targetIndex) return
				const next = [...this.active]
				next.splice(fromIndex, 1)
				const adjustedTarget = fromIndex < targetIndex ? targetIndex - 1 : targetIndex
				next.splice(adjustedTarget, 0, id)
				this.active = next
				this.queueSave()
				return
			}

			this.moveBetweenColumns(id, fromColumn, toColumn, targetIndex)
		},

		moveBetweenColumns(id, fromColumn, toColumn, insertIndex) {
			if (fromColumn === toColumn) return
			if (fromColumn === 'active') {
				this.active = this.active.filter((x) => x !== id)
			} else {
				this.inactive = this.inactive.filter((x) => x !== id)
			}

			if (toColumn === 'active') {
				const next = [...this.active]
				if (insertIndex === null || insertIndex < 0 || insertIndex >= next.length) {
					next.push(id)
				} else {
					next.splice(insertIndex, 0, id)
				}
				this.active = next
			} else {
				// Inactive list keeps server-side sort order; resort by name.
				const next = [...this.inactive, id]
				next.sort((a, b) => {
					const an = (this.displayNameMap[a] || a).toLowerCase()
					const bn = (this.displayNameMap[b] || b).toLowerCase()
					return an.localeCompare(bn)
				})
				this.inactive = next
			}
			this.queueSave()
		},

		// Click-to-move shortcuts for accessibility (drag-and-drop is
		// not screen-reader friendly).
		moveToActive(id) {
			this.moveBetweenColumns(id, 'inactive', 'active', null)
		},

		moveToInactive(id) {
			this.moveBetweenColumns(id, 'active', 'inactive', null)
		},

		// Debounced auto-save (300ms) — REQ-ASET-012 / tasks.md 3.3.
		queueSave() {
			if (this.saveTimer) {
				clearTimeout(this.saveTimer)
			}
			this.saveTimer = setTimeout(() => {
				this.saveTimer = null
				this.persist()
			}, 300)
		},

		async persist() {
			this.saving = true
			try {
				await api.updateAdminGroupOrder(this.active)
				showSuccess(this.t('mydash', 'Group order saved.'))
			} catch (error) {
				console.error('Failed to save group order:', error)
				showError(this.t('mydash', 'Failed to save group order.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.group-priority {
	margin-bottom: 32px;
}

.group-priority__header h3 {
	margin: 0 0 8px;
}

.group-priority__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.group-priority__columns {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.group-priority__column {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px;
	min-height: 240px;
	display: flex;
	flex-direction: column;
}

.group-priority__column-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.group-priority__column-header h4 {
	margin: 0;
}

.group-priority__count {
	background: var(--color-primary-element-light, var(--color-background-dark));
	color: var(--color-primary-text, var(--color-main-text));
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.group-priority__filter {
	margin-bottom: 8px;
}

.group-priority__list {
	flex: 1;
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-height: 60px;
}

.group-priority__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	cursor: grab;
	user-select: none;
}

.group-priority__item:active {
	cursor: grabbing;
}

.group-priority__item--stale {
	opacity: 0.7;
	border-left: 3px solid var(--color-warning, #c93);
}

.group-priority__handle {
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}

.group-priority__label {
	flex: 1;
}

.group-priority__stale-affix {
	color: var(--color-warning, #c93);
	font-style: italic;
	font-size: 12px;
	margin-left: 4px;
}

.group-priority__move {
	min-width: 32px;
}

.group-priority__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px;
	text-align: center;
}

.group-priority__status {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-top: 8px;
}
</style>
