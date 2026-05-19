<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<component
		:is="childRenderer"
		v-if="childRenderer"
		:content="childContent"
		:placement="placement"
		:edit-mode="editMode" />
	<div v-else class="container-child container-child--unknown">
		<span class="container-child__missing">{{ unknownLabel }}</span>
	</div>
</template>

<script>
import { getWidgetTypeEntry } from '../../../constants/widgetRegistry.js'

/**
 * ContainerChild — registry-driven dispatcher for a single child placement
 * inside a ContainerWidget (REQ-CONT-003).
 *
 * Looks up the placement's `type` in the shared widget registry and
 * mounts the matching `renderer` component, forwarding the placement's
 * `content` blob. Because the `container` widget type is itself a
 * registry entry whose renderer is `ContainerWidget`, this component is
 * naturally recursive: nesting is bounded only by the server-side
 * REQ-CONT-006 max-depth=3 invariant enforced in
 * `WidgetPlacementService::validateContainerDepth`.
 *
 * The dispatcher deliberately stays tiny — no header chrome, no edit
 * affordances of its own — so child widgets render exactly as they
 * would on the top-level grid.
 */
export default {
	name: 'ContainerChild',

	props: {
		placement: {
			type: Object,
			required: true,
		},
		editMode: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		registryEntry() {
			const type = this.placement?.type
			if (typeof type !== 'string' || type === '') {
				return null
			}
			return getWidgetTypeEntry(type)
		},

		childRenderer() {
			return this.registryEntry?.renderer || null
		},

		childContent() {
			return this.placement?.content || {}
		},

		unknownLabel() {
			const type = this.placement?.type || ''
			return t('mydash', 'Unknown widget type: {type}', { type })
		},
	},
}
</script>

<style scoped>
.container-child {
	width: 100%;
	height: 100%;
}

.container-child--unknown {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
