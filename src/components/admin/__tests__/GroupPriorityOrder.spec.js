/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `GroupPriorityOrder.vue`. Covers REQ-ASET-012,
 * REQ-ASET-013, REQ-ASET-014:
 *  - loads `{active, inactive, allKnown}` from `api.getAdminGroups()` on
 *    mount and renders both columns
 *  - stale IDs (in active but not in allKnown) render with the
 *    "(removed)" affix and remain in the active column
 *  - move-to-active / move-to-inactive arrow buttons rebuild the lists
 *    correctly and trigger a debounced auto-save (300ms)
 *  - filter inputs perform case-insensitive substring match on
 *    displayName OR raw id
 *  - persist sends the full active list to the POST endpoint
 *
 * The `api` module is mocked at the import boundary so no HTTP traffic
 * is generated; `@nextcloud/dialogs` is similarly stubbed because its
 * default export wires DOM-bound toast helpers.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import GroupPriorityOrder from '../GroupPriorityOrder.vue'
import { api } from '../../../services/api.js'

vi.mock('../../../services/api.js', () => ({
	api: {
		getAdminGroups: vi.fn(),
		updateAdminGroupOrder: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

const ncButtonStub = {
	name: 'NcButton',
	props: ['type', 'ariaLabel'],
	template: '<button @click="$emit(\'click\')"><slot /></button>',
}
const ncTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label', 'placeholder'],
	template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}

beforeEach(() => {
	globalThis.t = (_app, key) => key
	api.getAdminGroups.mockReset()
	api.updateAdminGroupOrder.mockReset().mockResolvedValue({ data: { status: 'ok' } })
})

function mountWith(payload) {
	api.getAdminGroups.mockResolvedValue({ data: payload })
	return mount(GroupPriorityOrder, {
		stubs: {
			NcButton: ncButtonStub,
			NcTextField: ncTextFieldStub,
		},
	})
}

async function flush() {
	// Two ticks: one for the create() promise, one for the resulting
	// reactive update + re-render.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('GroupPriorityOrder', () => {
	it('REQ-ASET-013: loads {active, inactive, allKnown} on mount', async () => {
		const wrapper = mountWith({
			active: ['eng'],
			inactive: ['mkt'],
			allKnown: [
				{ id: 'eng', displayName: 'Engineering' },
				{ id: 'mkt', displayName: 'Marketing' },
			],
		})
		await flush()

		expect(api.getAdminGroups).toHaveBeenCalledOnce()
		expect(wrapper.vm.active).toEqual(['eng'])
		expect(wrapper.vm.inactive).toEqual(['mkt'])
	})

	it('REQ-ASET-013: stale active IDs render with "(removed)" affix', async () => {
		const wrapper = mountWith({
			active: ['deleted-group', 'eng'],
			inactive: [],
			allKnown: [{ id: 'eng', displayName: 'Engineering' }],
		})
		await flush()

		const staleItem = wrapper
			.findAll('[data-test="group-priority-active"] li')
			.wrappers.find((li) => li.attributes('data-test-id') === 'deleted-group')
		expect(staleItem).toBeTruthy()
		expect(staleItem.text()).toContain('(removed)')
		expect(staleItem.classes()).toContain('group-priority__item--stale')
	})

	it('REQ-ASET-012: moveToActive moves an inactive id into active and queues save', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = mountWith({
				active: ['eng'],
				inactive: ['mkt'],
				allKnown: [
					{ id: 'eng', displayName: 'Engineering' },
					{ id: 'mkt', displayName: 'Marketing' },
				],
			})
			// Drain the loadGroups() microtasks under fake timers.
			await vi.runAllTimersAsync()

			wrapper.vm.moveToActive('mkt')
			expect(wrapper.vm.active).toEqual(['eng', 'mkt'])
			expect(wrapper.vm.inactive).toEqual([])

			// Debounced — no save yet, until 300ms elapses.
			expect(api.updateAdminGroupOrder).not.toHaveBeenCalled()
			await vi.advanceTimersByTimeAsync(300)
			expect(api.updateAdminGroupOrder).toHaveBeenCalledWith(['eng', 'mkt'])
		} finally {
			vi.useRealTimers()
		}
	})

	it('REQ-ASET-012: moveToInactive removes from active and re-sorts inactive by name', async () => {
		const wrapper = mountWith({
			active: ['mkt'],
			inactive: ['eng'],
			allKnown: [
				{ id: 'eng', displayName: 'Engineering' },
				{ id: 'mkt', displayName: 'Marketing' },
			],
		})
		await flush()

		wrapper.vm.moveToInactive('mkt')
		// Engineering before Marketing alphabetically.
		expect(wrapper.vm.active).toEqual([])
		expect(wrapper.vm.inactive).toEqual(['eng', 'mkt'])
	})

	it('REQ-ASET-013: filter performs case-insensitive substring match on displayName OR id', async () => {
		const wrapper = mountWith({
			active: ['eng', 'mkt'],
			inactive: [],
			allKnown: [
				{ id: 'eng', displayName: 'Engineering' },
				{ id: 'mkt', displayName: 'Marketing' },
			],
		})
		await flush()

		wrapper.vm.activeFilter = 'MARK'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.filteredActive).toEqual(['mkt'])

		// Match on raw id when displayName missed.
		wrapper.vm.activeFilter = 'eng'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.filteredActive).toEqual(['eng'])
	})

	it('REQ-ASET-014: persist sends current active list as the wholesale payload', async () => {
		const wrapper = mountWith({
			active: ['a', 'b'],
			inactive: [],
			allKnown: [
				{ id: 'a', displayName: 'Alpha' },
				{ id: 'b', displayName: 'Bravo' },
			],
		})
		await flush()

		wrapper.vm.active = ['b', 'a']
		await wrapper.vm.persist()
		expect(api.updateAdminGroupOrder).toHaveBeenCalledWith(['b', 'a'])
	})

	it('REQ-ASET-013: stale ids never appear in displayNameMap (no display name available)', async () => {
		const wrapper = mountWith({
			active: ['stale'],
			inactive: [],
			allKnown: [],
		})
		await flush()
		expect(wrapper.vm.isStale('stale')).toBe(true)
		expect(wrapper.vm.displayName('stale')).toBe('stale')
	})
})
