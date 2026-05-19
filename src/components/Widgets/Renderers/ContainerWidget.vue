<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="container-widget" :style="wrapperStyle">
		<h4 v-if="hasTitle" class="container-widget__title">
			{{ titleText }}
		</h4>

		<div ref="innerGrid" class="grid-stack mydash-container-grid">
			<div
				v-for="(child, index) in children"
				:key="childKey(child, index)"
				class="grid-stack-item container-widget__child"
				:gs-x="child.gridX || 0"
				:gs-y="child.gridY || 0"
				:gs-w="child.gridWidth || 2"
				:gs-h="child.gridHeight || 2">
				<div class="grid-stack-item-content">
					<ContainerChild
						:placement="child"
						:edit-mode="editMode" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import ContainerChild from './ContainerChild.vue'
import {
	useNestedGridManager,
	getNestedGridOptions,
} from '../../../composables/useNestedGridManager.js'

const PADDING_TOKENS = Object.freeze({
	none: '0',
	small: '4px',
	medium: '8px',
	large: '16px',
})

/**
 * ContainerWidget — the `container` widget type renderer (REQ-CONT-001..005).
 *
 * Renders a wrapper element with optional background colour, padding and
 * title, plus an inner GridStack instance bounded by the container's outer
 * cell. Each child placement in `content.placements[]` is rendered through
 * the ContainerChild dispatcher, which in turn dispatches by widget type
 * via the same widget registry used at the top level — so a container can
 * hold any registered widget type, including another container (subject to
 * the REQ-CONT-006 max-depth=3 server-side guard).
 *
 * View mode (REQ-CONT-004): the wrapper is `pointer-events: none` so
 * clicks fall through to the child widget under the cursor; child wrappers
 * re-enable pointer events. Edit mode (REQ-CONT-005): the inner grid
 * becomes editable independently of the outer grid via GridStack's nested-
 * grid behaviour.
 */
export default {
	name: 'ContainerWidget',

	components: {
		ContainerChild,
	},

	props: {
		content: {
			type: Object,
			default: () => ({}),
		},
		editMode: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:content'],

	data() {
		return {
			gridInstance: null,
		}
	},

	computed: {
		children() {
			const list = this.content?.placements
			return Array.isArray(list) ? list : []
		},

		backgroundColor() {
			const value = this.content?.backgroundColor
			return (typeof value === 'string' && value !== '') ? value : 'transparent'
		},

		paddingToken() {
			const value = this.content?.padding
			if (typeof value === 'string' && Object.prototype.hasOwnProperty.call(PADDING_TOKENS, value)) {
				return value
			}
			return 'medium'
		},

		paddingPx() {
			return PADDING_TOKENS[this.paddingToken]
		},

		titleText() {
			return typeof this.content?.title === 'string' ? this.content.title : ''
		},

		hasTitle() {
			return this.titleText.trim() !== ''
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				padding: this.paddingPx,
				'background-color': this.backgroundColor,
				// REQ-CONT-004: in view mode, the container itself is
				// non-interactive — clicks fall through to children. The
				// child wrappers re-enable pointer events. In edit mode,
				// the wrapper is interactive so the user can drop new
				// widgets into the inner grid.
				'pointer-events': this.editMode ? 'auto' : 'none',
			}
		},
	},

	mounted() {
		this.initInnerGrid()
	},

	beforeDestroy() {
		this.destroyInnerGrid()
	},

	methods: {
		/**
		 * Stable key for v-for over child placements. Falls back to the
		 * loop index when a child has neither id nor uuid, which keeps
		 * Vue happy during the brief window between an add and the
		 * server-issued id round-trip.
		 *
		 * @param {object} child the child placement
		 * @param {number} index the loop index
		 * @return {string|number}
		 */
		childKey(child, index) {
			if (child && (child.id !== undefined && child.id !== null)) {
				return `id-${child.id}`
			}
			if (child && typeof child.uuid === 'string' && child.uuid !== '') {
				return `uuid-${child.uuid}`
			}
			return `idx-${index}`
		},

		/**
		 * Initialise the inner GridStack instance once the DOM ref is
		 * available. Defers to the runtime GridStack import so the unit
		 * test surface stays JSDOM-friendly — when GridStack is not
		 * available (test env), the renderer still mounts and renders
		 * children, just without drag/resize affordances.
		 *
		 * @return {Promise<void>}
		 */
		async initInnerGrid() {
			if (!this.$refs.innerGrid) {
				return
			}
			let GridStackCtor = null
			try {
				const mod = await import('gridstack')
				GridStackCtor = mod && (mod.GridStack || mod.default)
			} catch (e) {
				// GridStack runtime unavailable — non-fatal in tests.
				return
			}
			if (!GridStackCtor || typeof GridStackCtor.init !== 'function') {
				return
			}
			const opts = getNestedGridOptions()
			this.gridInstance = GridStackCtor.init(opts, this.$refs.innerGrid)

			// Wire the persistence callback (REQ-CONT-005). Movements and
			// resizes inside the inner grid call back to the parent so the
			// container placement's `content.placements[]` array is kept
			// in sync; the parent persists via the existing widget-update
			// API path.
			const manager = useNestedGridManager({
				persistPlacements: this.handlePersist,
			})
			this._nestedManager = manager
			if (this.gridInstance && typeof this.gridInstance.on === 'function') {
				this.gridInstance.on('change', this.onGridChange)
			}
		},

		/**
		 * GridStack 'change' callback — translates inner-grid node
		 * coordinates back into the MyDash field-name form and emits
		 * an `update:content` event so the parent placement's content
		 * blob is updated.
		 *
		 * @param {Event} _event the GridStack event (ignored)
		 * @param {Array<object>} nodes the changed nodes
		 */
		onGridChange(_event, nodes) {
			if (!Array.isArray(nodes) || nodes.length === 0) {
				return
			}
			const byKey = new Map()
			for (const child of this.children) {
				byKey.set(this.childKey(child, 0), child)
			}
			const updated = this.children.map((child, index) => {
				const node = nodes.find(n => n.el && n.el.dataset && n.el.dataset.mydashIndex === String(index))
				if (!node) {
					return child
				}
				return {
					...child,
					gridX: node.x,
					gridY: node.y,
					gridWidth: node.w,
					gridHeight: node.h,
				}
			})
			this.handlePersist(updated)
		},

		/**
		 * Bridge for the nested manager's persist callback — re-emits
		 * `update:content` with the merged placements so the parent
		 * AddWidgetModal / placement-store update path picks up the
		 * change.
		 *
		 * @param {Array<object>} placements the new child placements
		 */
		handlePersist(placements) {
			this.$emit('update:content', {
				...(this.content || {}),
				placements: Array.isArray(placements) ? placements : [],
			})
		},

		destroyInnerGrid() {
			if (this.gridInstance && typeof this.gridInstance.destroy === 'function') {
				try {
					this.gridInstance.destroy(false)
				} catch (e) {
					// no-op — best-effort teardown
				}
			}
			this.gridInstance = null
			this._nestedManager = null
		},
	},
}
</script>

<style scoped>
.container-widget {
	width: 100%;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	gap: 4px;
	overflow: hidden;
}

.container-widget__title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast, var(--color-main-text));
	pointer-events: auto;
}

.mydash-container-grid {
	flex: 1;
	min-height: 0;
}

.container-widget__child {
	/* REQ-CONT-004: child wrappers re-enable pointer events so clicks
	   on a child fire the child's own handlers — the container itself
	   is `pointer-events: none` in view mode (see wrapperStyle). */
	pointer-events: auto;
}
</style>
