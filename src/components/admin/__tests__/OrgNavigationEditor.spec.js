/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `OrgNavigationEditor.vue` (REQ-ONAV-007,
 * REQ-ONAV-012). Drives the local working-copy CRUD operations:
 * adding root-level sections / links, reordering via move-up /
 * move-down, deleting nodes, and persisting via the store.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Vue from 'vue'
import { PiniaVuePlugin, createPinia, setActivePinia } from 'pinia'

import OrgNavigationEditor from '../OrgNavigationEditor.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('../../../services/api.js', () => ({
	api: {
		getOrgNavigation: vi.fn().mockResolvedValue({ data: { tree: [], language: 'nl' } }),
		updateOrgNavigation: vi.fn().mockResolvedValue({ data: { tree: [], language: 'nl' } }),
		getOrgNavigationPosition: vi.fn().mockResolvedValue({ data: { position: 'hidden' } }),
		updateOrgNavigationPosition: vi.fn().mockResolvedValue({ data: { position: 'left' } }),
	},
}))

Vue.use(PiniaVuePlugin)

beforeEach(() => {
	globalThis.t = (_app, key) => key
	// `globalThis.crypto` is a read-only getter in Node — define a stub
	// `randomUUID` directly so the editor's UUID generator returns a
	// deterministic, validator-compliant value during tests.
	if (!globalThis.crypto || typeof globalThis.crypto.randomUUID !== 'function') {
		Object.defineProperty(globalThis, 'crypto', {
			value: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
			configurable: true,
		})
	} else {
		// Crypto exists but possibly without randomUUID — patch it.
		try {
			globalThis.crypto.randomUUID = () => '11111111-1111-4111-8111-111111111111'
		} catch (_e) {
			// Read-only — leave as-is; the fallback Math.random branch is fine.
		}
	}
	setActivePinia(createPinia())
})

async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('OrgNavigationEditor', () => {
	it('REQ-ONAV-007: clicking Add section appends a node to the working tree', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		expect(wrapper.vm.workingTree).toHaveLength(0)
		await wrapper.find('[data-test="org-nav-add-section"]').trigger('click')
		expect(wrapper.vm.workingTree).toHaveLength(1)
		expect(wrapper.vm.workingTree[0].url).toBeNull()
	})

	it('REQ-ONAV-007: clicking Add link appends a link node with default url', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		await wrapper.find('[data-test="org-nav-add-link"]').trigger('click')
		expect(wrapper.vm.workingTree[0].url).toBe('/')
	})

	it('REQ-ONAV-007: Save persists via store.updateTree and shows success', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		await wrapper.find('[data-test="org-nav-add-link"]').trigger('click')
		await wrapper.find('[data-test="org-nav-save"]').trigger('click')
		await flush()

		expect(wrapper.vm.successFlag).toBe(true)
	})

	it('REQ-ONAV-007: surfaces backend error when save fails', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)

		const apiMod = await import('../../../services/api.js')
		const failure = new Error('boom')
		failure.response = { data: { error: 'Tree depth cannot exceed 3 levels' } }
		apiMod.api.updateOrgNavigation.mockRejectedValueOnce(failure)

		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		await wrapper.find('[data-test="org-nav-save"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-test="org-nav-editor-error"]').text())
			.toContain('Tree depth')
		expect(wrapper.vm.successFlag).toBe(false)
	})

	it('REQ-ONAV-007: moveUp swaps the node with its previous sibling', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		wrapper.vm.workingTree = [
			{ id: 'a', label: 'A', url: '/a', children: [] },
			{ id: 'b', label: 'B', url: '/b', children: [] },
		]
		wrapper.vm.moveUp({ siblings: wrapper.vm.workingTree, index: 1 })
		expect(wrapper.vm.workingTree[0].id).toBe('b')
		expect(wrapper.vm.workingTree[1].id).toBe('a')
	})

	it('REQ-ONAV-007: onDelete removes the node from its siblings', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		wrapper.vm.workingTree = [
			{ id: 'a', label: 'A', url: '/a', children: [] },
			{ id: 'b', label: 'B', url: '/b', children: [] },
		]
		wrapper.vm.onDelete({ siblings: wrapper.vm.workingTree, index: 0 })
		expect(wrapper.vm.workingTree).toHaveLength(1)
		expect(wrapper.vm.workingTree[0].id).toBe('b')
	})

	it('REQ-ONAV-004: changing position calls store.updatePosition', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const wrapper = mount(OrgNavigationEditor, { pinia })
		await flush()

		wrapper.vm.selectedPosition = 'left'
		await wrapper.vm.onPositionChange()

		const apiMod = await import('../../../services/api.js')
		expect(apiMod.api.updateOrgNavigationPosition).toHaveBeenCalledWith('left')
	})
})
