/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `AddWidgetModal.vue`. Covers REQ-WDG-010, REQ-WDG-012,
 * REQ-WDG-013, REQ-WDG-014:
 *  - registry-driven type select renders only types with a usable form
 *  - type switch resets state (no cross-type leakage)
 *  - edit mode pre-fills from editingWidget and hides the type select
 *  - submit emits {type, content} with only the active type's fields
 *  - validation gating disables submit until required fields are filled
 *  - cancel/Esc/NcModal close emit `close`, never `submit`
 *
 * NcModal is stubbed via Vue's component option so the modal renders inline
 * and we can drive the Vue instance directly without poking the DOM.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AddWidgetModal from '../AddWidgetModal.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

const ncModalStub = {
	name: 'NcModal',
	props: ['name', 'size'],
	template: '<div class="nc-modal-stub"><slot /></div>',
}
const ncButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled', 'title'],
	template: '<button :disabled="disabled" :title="title" @click="$emit(\'click\')"><slot /></button>',
}

function mountModal(props = {}) {
	return mount(AddWidgetModal, {
		propsData: {
			show: true,
			preselectedType: null,
			editingWidget: null,
			...props,
		},
		stubs: {
			NcModal: ncModalStub,
			NcButton: ncButtonStub,
			NcTextField: true,
			NcSelect: true,
		},
	})
}

describe('AddWidgetModal', () => {
	it('REQ-WDG-014: type select renders one option per registered type with a form', () => {
		const wrapper = mountModal()
		const opts = wrapper.findAll('.add-widget-modal__type-select option')
		// Currently only `label` is registered with a form; the four pending
		// per-widget proposals add their own entries when they ship.
		expect(opts.length).toBeGreaterThanOrEqual(1)
		const values = opts.wrappers.map((o) => o.element.value)
		expect(values).toContain('label')
	})

	it('REQ-WDG-010: action button reads `Add` in create mode and `Save` in edit mode', async () => {
		const create = mountModal()
		expect(create.text()).toContain('Add')

		const edit = mountModal({
			editingWidget: { type: 'label', content: { text: 'Hi' } },
		})
		expect(edit.text()).toContain('Save')
	})

	it('REQ-WDG-010: edit mode hides the type select (placement type is immutable)', () => {
		const wrapper = mountModal({
			editingWidget: { type: 'label', content: { text: 'Hi' } },
		})
		expect(wrapper.find('.add-widget-modal__type').exists()).toBe(false)
	})

	it('REQ-WDG-010: preselected-type also hides the type select', () => {
		const wrapper = mountModal({ preselectedType: 'label' })
		expect(wrapper.find('.add-widget-modal__type').exists()).toBe(false)
	})

	it('REQ-WDG-010: edit mode pre-fills the sub-form from editingWidget.content', () => {
		const wrapper = mountModal({
			editingWidget: {
				type: 'label',
				content: {
					text: 'Pre-filled',
					fontSize: '20px',
					color: '#abc123',
					backgroundColor: '#ffffff',
					fontWeight: '700',
					textAlign: 'right',
				},
			},
		})
		const sub = wrapper.findComponent({ name: 'LabelForm' })
		expect(sub.exists()).toBe(true)
		expect(sub.vm.text).toBe('Pre-filled')
		expect(sub.vm.fontSize).toBe('20px')
	})

	it('REQ-WDG-010: switching the type via the select resets form state', async () => {
		const wrapper = mountModal()
		// Mutate the active sub-form's text, then "switch" by triggering
		// the same flow the change handler uses. Since only `label` is
		// registered we cannot literally switch types yet — instead we
		// invoke the handler with a fresh reset and assert content goes
		// back to defaults.
		const sub = wrapper.findComponent({ name: 'LabelForm' })
		sub.vm.text = 'dirty'
		sub.vm.$emit('update:content', { ...sub.vm.assembledContent })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.state.content.text).toBe('dirty')

		wrapper.vm.onTypeSwitch()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.state.content.text).toBe('')
	})

	it('REQ-WDG-012: action button is disabled when sub-form validate() returns errors', async () => {
		const wrapper = mountModal()
		await wrapper.vm.$nextTick()
		const submit = wrapper.findAll('button').wrappers.find((b) => b.text().trim() === 'Add')
		// Empty text → LabelForm.validate() returns one error → button disabled
		expect(submit.attributes('disabled')).toBeDefined()
	})

	it('REQ-WDG-012: action button enables once required field is filled', async () => {
		const wrapper = mountModal()
		const sub = wrapper.findComponent({ name: 'LabelForm' })
		sub.vm.updateField('text', 'Hello')
		await wrapper.vm.$nextTick()
		const submit = wrapper.findAll('button').wrappers.find((b) => b.text().trim() === 'Add')
		expect(submit.attributes('disabled')).toBeUndefined()
	})

	it('REQ-WDG-010: submit emits {type, content} containing only active type fields', async () => {
		const wrapper = mountModal()
		const sub = wrapper.findComponent({ name: 'LabelForm' })
		sub.vm.updateField('text', 'Hello')
		await wrapper.vm.$nextTick()
		wrapper.vm.onSubmit()
		const emitted = wrapper.emitted('submit')
		expect(emitted).toBeTruthy()
		const [payload] = emitted[emitted.length - 1]
		expect(payload.type).toBe('label')
		expect(payload.content.text).toBe('Hello')
		// Ensure no foreign-type fields leaked in
		expect(payload.content).not.toHaveProperty('url')
		expect(payload.content).not.toHaveProperty('alt')
	})

	it('REQ-WDG-013: cancel button emits close, never submit', () => {
		const wrapper = mountModal()
		const cancel = wrapper.findAll('button').wrappers.find((b) => b.text().trim() === 'Cancel')
		cancel.trigger('click')
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('REQ-WDG-013: Esc keydown emits close, never submit', () => {
		const wrapper = mountModal()
		const event = new KeyboardEvent('keydown', { key: 'Escape' })
		document.dispatchEvent(event)
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('REQ-WDG-013: NcModal close event emits close, never submit', () => {
		const wrapper = mountModal()
		const modal = wrapper.findComponent({ name: 'NcModal' })
		modal.vm.$emit('close')
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('REQ-WDG-013: Esc removes its global listener on beforeDestroy (no leaks)', () => {
		const removeSpy = vi.spyOn(document, 'removeEventListener')
		const wrapper = mountModal()
		wrapper.destroy()
		const calls = removeSpy.mock.calls.filter((c) => c[0] === 'keydown')
		expect(calls.length).toBeGreaterThan(0)
		removeSpy.mockRestore()
	})
})
