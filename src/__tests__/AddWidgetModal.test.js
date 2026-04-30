/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, it, expect, beforeAll, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AddWidgetModal from '../components/Widgets/AddWidgetModal.vue'
import { widgetRegistry } from '../constants/widgetRegistry.js'

// Stub the Nextcloud `t` global.
beforeAll(() => {
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

// ---------------------------------------------------------------------------
// Minimal form stubs.  The component :is binding resolves via the registry's
// `form` component object.  Vue 2 Test Utils matches stubs by component name.
// ---------------------------------------------------------------------------
function makeFormStub(componentName, { errorsOnValidate = [] } = {}) {
	return {
		name: componentName,
		props: {
			editingWidget: { default: null },
		},
		template: `<div class="stub-form" data-form="${componentName}"></div>`,
		methods: {
			validate() {
				return errorsOnValidate
			},
		},
	}
}

// Build stubs keyed by each form component's `name` option (required by Vue 2 TU).
function buildStubs(overrides = {}) {
	const stubs = {}
	for (const entry of Object.values(widgetRegistry)) {
		const name = entry.form.name
		stubs[name] = makeFormStub(name)
	}
	return { ...stubs, ...overrides }
}

// Mount helper for Vue 2 Test Utils (uses propsData, not props).
function mountModal(propsData = {}, extra = {}) {
	return mount(AddWidgetModal, {
		propsData: {
			show: true,
			...propsData,
		},
		stubs: buildStubs(extra.stubs),
		...extra,
	})
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AddWidgetModal', () => {
	let wrapper

	afterEach(() => {
		if (wrapper) {
			wrapper.destroy()
			wrapper = null
		}
	})

	// -------------------------------------------------------------------------
	// 1. Open in create mode — type select visible, first sub-form rendered
	// -------------------------------------------------------------------------
	it('shows type selector in create mode (no preselectedType, no editingWidget)', () => {
		wrapper = mountModal({ preselectedType: null, editingWidget: null })

		const select = wrapper.find('select.add-widget-modal__select')
		expect(select.exists()).toBe(true)

		const options = select.findAll('option')
		expect(options.length).toBe(Object.keys(widgetRegistry).length)

		// The first registry type's sub-form stub should be rendered.
		const firstType = Object.keys(widgetRegistry)[0]
		const firstName = widgetRegistry[firstType].form.name
		expect(wrapper.find(`[data-form="${firstName}"]`).exists()).toBe(true)
	})

	// -------------------------------------------------------------------------
	// 2. Open with preselectedType — type select hidden
	// -------------------------------------------------------------------------
	it('hides type selector when preselectedType is set', () => {
		wrapper = mountModal({ preselectedType: 'text', editingWidget: null })

		const select = wrapper.find('select.add-widget-modal__select')
		expect(select.exists()).toBe(false)
	})

	// -------------------------------------------------------------------------
	// 3. Open in edit mode — title 'Edit Widget', type hidden, action 'Save'
	// -------------------------------------------------------------------------
	it('renders edit mode correctly', () => {
		const editingWidget = {
			type: 'image',
			content: { url: '/img/x.png', alt: 'X', fit: 'cover', link: '' },
		}
		wrapper = mountModal({ editingWidget })

		const title = wrapper.find('.add-widget-modal__title')
		expect(title.text()).toBe('Edit Widget')

		// Type selector hidden.
		expect(wrapper.find('select.add-widget-modal__select').exists()).toBe(false)

		// Primary button reads 'Save'.
		const allBtns = wrapper.findAll('.add-widget-modal__btn')
		const primaryBtn = allBtns.at(allBtns.length - 1)
		expect(primaryBtn.text()).toBe('Save')
	})

	// -------------------------------------------------------------------------
	// 4. Type switch resets form (no cross-type field leakage)
	// -------------------------------------------------------------------------
	it('resets formSnapshot on type switch so no cross-type leakage occurs', async () => {
		wrapper = mountModal({ preselectedType: null, editingWidget: null })

		const types = Object.keys(widgetRegistry)
		expect(types.length).toBeGreaterThan(1)

		// Switch to the second type.
		await wrapper.vm.$nextTick()
		wrapper.vm.activeType = types[1]
		wrapper.vm.onTypeChange()
		await wrapper.vm.$nextTick()

		// formSnapshot should hold the defaults of the second type.
		const secondDefaults = widgetRegistry[types[1]].defaults
		for (const [key, val] of Object.entries(secondDefaults)) {
			expect(wrapper.vm.formSnapshot[key]).toBe(val)
		}

		// Keys only present in the first type must NOT leak into formSnapshot.
		const firstDefaults = widgetRegistry[types[0]].defaults
		const firstOnlyKeys = Object.keys(firstDefaults).filter(
			k => !Object.prototype.hasOwnProperty.call(secondDefaults, k),
		)
		for (const key of firstOnlyKeys) {
			expect(Object.prototype.hasOwnProperty.call(wrapper.vm.formSnapshot, key)).toBe(false)
		}
	})

	// -------------------------------------------------------------------------
	// 5. Submit emits only the selected type's fields
	// -------------------------------------------------------------------------
	it('emits submit with only the relevant type fields (no cross-type leakage)', async () => {
		wrapper = mountModal({ preselectedType: 'text' })

		// Mark the modal as valid so submit goes through.
		wrapper.vm.formErrors = []

		// Simulate content update that includes a spurious 'url' key from image type.
		wrapper.vm.onContentUpdate({ text: 'Hello', fontSize: '16px', url: '/leak.png' })
		await wrapper.vm.$nextTick()

		await wrapper.find('.add-widget-modal__btn--primary').trigger('click')

		const emitted = wrapper.emitted('submit')
		expect(emitted).toBeTruthy()
		const payload = emitted[0][0]

		expect(payload.type).toBe('text')
		// Must include text fields.
		expect(payload.content).toHaveProperty('text', 'Hello')
		// Must NOT include image-only field.
		expect(payload.content).not.toHaveProperty('url')
	})

	// -------------------------------------------------------------------------
	// 6. Validation gating — submit disabled when validate() returns errors
	// -------------------------------------------------------------------------
	it('disables submit button when formErrors is non-empty', async () => {
		wrapper = mountModal({ preselectedType: 'text' })

		// Flush initModal's deferred revalidate() tick first.
		await wrapper.vm.$nextTick()

		// Directly set errors to simulate invalid form and wait for DOM update.
		wrapper.vm.formErrors = ['Text is required']
		await wrapper.vm.$nextTick()

		const primaryBtn = wrapper.find('.add-widget-modal__btn--primary')
		expect(primaryBtn.element.disabled).toBe(true)
	})

	it('enables submit button when formErrors is empty', async () => {
		wrapper = mountModal({ preselectedType: 'text' })

		// Clear errors to simulate valid form.
		wrapper.vm.formErrors = []
		await wrapper.vm.$nextTick()

		const primaryBtn = wrapper.find('.add-widget-modal__btn--primary')
		expect(primaryBtn.element.disabled).toBe(false)
	})

	it('calls sub-form validate() on submit and blocks when invalid', async () => {
		const invalidStub = makeFormStub('TextDisplayForm', { errorsOnValidate: ['Required'] })
		wrapper = mountModal(
			{ preselectedType: 'text' },
			{ stubs: buildStubs({ TextDisplayForm: invalidStub }) },
		)

		// Ensure formErrors starts empty so we can see the guard kick in.
		wrapper.vm.formErrors = []

		await wrapper.find('.add-widget-modal__btn--primary').trigger('click')

		// revalidate() should have run and blocked the submit.
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	// -------------------------------------------------------------------------
	// 7. Backdrop click emits close (not submit)
	// -------------------------------------------------------------------------
	it('emits close (not submit) when backdrop is clicked', async () => {
		wrapper = mountModal()

		await wrapper.find('.add-widget-modal__backdrop').trigger('click')

		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('does NOT close when clicking the modal panel (not backdrop)', async () => {
		wrapper = mountModal()

		// Click on the modal container itself (not the backdrop @click.self target).
		await wrapper.find('.add-widget-modal').trigger('click')

		expect(wrapper.emitted('close')).toBeFalsy()
	})

	// -------------------------------------------------------------------------
	// 8. Escape key emits close (not submit)
	// -------------------------------------------------------------------------
	it('emits close when Escape is pressed while modal is visible', async () => {
		wrapper = mountModal({ show: true })

		const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
		document.dispatchEvent(event)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('does NOT emit close on Escape when modal is hidden', async () => {
		wrapper = mountModal({ show: false })

		const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
		document.dispatchEvent(event)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('close')).toBeFalsy()
	})

	// -------------------------------------------------------------------------
	// 9. Reopening resets to defaults (not stale state)
	// -------------------------------------------------------------------------
	it('resets formSnapshot to type defaults when modal is reopened', async () => {
		wrapper = mountModal({ show: true, preselectedType: 'text' })

		// Simulate user entering data.
		wrapper.vm.onContentUpdate({ text: 'Stale data', fontSize: '20px' })

		// Close and reopen.
		await wrapper.setProps({ show: false })
		await wrapper.setProps({ show: true })
		await wrapper.vm.$nextTick()

		// formSnapshot should be reset to defaults for 'text' type.
		const textDefaults = widgetRegistry.text.defaults
		for (const [key, val] of Object.entries(textDefaults)) {
			expect(wrapper.vm.formSnapshot[key]).toBe(val)
		}
	})
})
