/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'

import LabelWidget from '../components/Widgets/Renderers/LabelWidget.vue'
import LabelForm from '../components/Widgets/Forms/LabelForm.vue'
import { widgetRegistry, getWidgetRegistryEntry } from '../constants/widgetRegistry.js'

beforeAll(() => {
	// Stub the Nextcloud `t` global with an identity function so components
	// render during tests without depending on @nextcloud/l10n.
	if (typeof globalThis.t !== 'function') {
		globalThis.t = (_app, key) => key
	}
})

describe('LabelWidget — plain-text only (REQ-LBL-001)', () => {
	it('renders embedded HTML as literal text — no <b> element generated from content', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Sales <b>Q4</b>' } },
		})
		// The visible text must contain the literal angle brackets.
		expect(wrapper.text()).toContain('Sales <b>Q4</b>')
		// And there must be no <b> element rendered inside the widget.
		expect(wrapper.find('.label-widget__text b').exists()).toBe(false)
		expect(wrapper.find('b').exists()).toBe(false)
	})

	it('renders <script> tags in text as literal characters with no script execution', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '<script>alert(1)</script>' } },
		})
		expect(wrapper.text()).toContain('<script>alert(1)</script>')
		// The DOM must not contain a real <script> element coming from content.
		expect(wrapper.find('.label-widget__text script').exists()).toBe(false)
	})
})

describe('LabelWidget — defaults applied (REQ-LBL-002)', () => {
	it('applies font-size 16px, font-weight bold and text-align center when only text is set', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Header' } },
		})
		const span = wrapper.find('.label-widget__text')
		const style = span.element.getAttribute('style') || ''
		expect(style).toContain('font-size: 16px')
		expect(style).toContain('font-weight: bold')
		expect(style).toContain('text-align: center')
		// Theme-aware colour
		expect(style).toContain('var(--color-main-text)')
	})

	it('overrides individual fields while leaving untouched defaults intact', () => {
		const wrapper = mount(LabelWidget, {
			propsData: {
				content: {
					text: 'X',
					fontSize: '32px',
					fontWeight: 'normal',
					textAlign: 'left',
				},
			},
		})
		const spanStyle = wrapper.find('.label-widget__text').element.getAttribute('style') || ''
		expect(spanStyle).toContain('font-size: 32px')
		expect(spanStyle).toContain('font-weight: normal')
		expect(spanStyle).toContain('text-align: left')
		// color still falls back to theme
		expect(spanStyle).toContain('var(--color-main-text)')
		// background on wrapper is transparent
		const wrapperStyle = wrapper.element.getAttribute('style') || ''
		expect(wrapperStyle).toContain('background-color: transparent')
	})
})

describe('LabelWidget — long word wrap (REQ-LBL-003)', () => {
	it('applies overflow-wrap: break-word on the inner span', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Pneumonoultramicroscopicsilicovolcanoconiosis' } },
		})
		const span = wrapper.find('.label-widget__text')
		const style = span.element.getAttribute('style') || ''
		// jsdom does not perform real layout, but we can assert the style is set.
		expect(style).toContain('overflow-wrap: break-word')
	})
})

describe('LabelWidget — empty placeholder (REQ-LBL-004)', () => {
	it('renders the localised "Label" fallback when text is empty', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '' } },
		})
		expect(wrapper.text()).toBe('Label')
	})

	it('treats whitespace-only text as empty and renders the fallback', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: '   ' } },
		})
		expect(wrapper.text()).toBe('Label')
	})

	it('renders the fallback when text is undefined', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: {} },
		})
		expect(wrapper.text()).toBe('Label')
	})
})

describe('LabelWidget — wrapper layout (REQ-LBL-006)', () => {
	it('wrapper has width 100%, height 100% and 12px padding', () => {
		const wrapper = mount(LabelWidget, {
			propsData: { content: { text: 'Hi' } },
		})
		const style = wrapper.element.getAttribute('style') || ''
		expect(style).toContain('width: 100%')
		expect(style).toContain('height: 100%')
		expect(style).toContain('padding: 12px')
		expect(style).toContain('display: flex')
	})
})

describe('LabelForm — validation (REQ-LBL-005)', () => {
	it('validate() returns ["Label text is required"] when text is empty', () => {
		const wrapper = mount(LabelForm, {
			propsData: { editingWidget: { content: { text: '' } } },
		})
		expect(wrapper.vm.validate()).toEqual(['Label text is required'])
	})

	it('validate() returns ["Label text is required"] when text is whitespace only', () => {
		const wrapper = mount(LabelForm, {
			propsData: { editingWidget: { content: { text: '   ' } } },
		})
		expect(wrapper.vm.validate()).toEqual(['Label text is required'])
	})

	it('validate() returns [] when text is populated', () => {
		const wrapper = mount(LabelForm, {
			propsData: { editingWidget: { content: { text: 'Hello' } } },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('pre-fills all six controls from editingWidget.content on mount', () => {
		const wrapper = mount(LabelForm, {
			propsData: {
				editingWidget: {
					content: {
						text: 'Hi',
						fontSize: '20px',
						color: '#ff0000',
						backgroundColor: '#ffffff',
						fontWeight: '700',
						textAlign: 'right',
					},
				},
			},
		})
		expect(wrapper.vm.form.text).toBe('Hi')
		expect(wrapper.vm.form.fontSize).toBe('20px')
		expect(wrapper.vm.form.color).toBe('#ff0000')
		expect(wrapper.vm.form.backgroundColor).toBe('#ffffff')
		expect(wrapper.vm.form.fontWeight).toBe('700')
		expect(wrapper.vm.form.textAlign).toBe('right')
	})

	it('emits update:content when the user types in the text input', async () => {
		const wrapper = mount(LabelForm, {
			propsData: { editingWidget: { content: { text: '' } } },
		})
		const input = wrapper.find('input[type="text"]')
		await input.setValue('Heading')
		const events = wrapper.emitted('update:content')
		expect(events).toBeTruthy()
		expect(events[events.length - 1][0].text).toBe('Heading')
		expect(wrapper.vm.validate()).toEqual([])
	})
})

describe('widgetRegistry — label entry (REQ-LBL-007)', () => {
	it('exposes a label entry distinct from text', () => {
		expect(widgetRegistry.label).toBeTruthy()
		expect(widgetRegistry.label.type).toBe('label')
		expect(widgetRegistry.text).toBeTruthy()
		expect(widgetRegistry.label.type).not.toBe(widgetRegistry.text.type)
	})

	it('uses the spec-mandated default content', () => {
		expect(widgetRegistry.label.defaults).toEqual({
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		})
	})

	it('is reachable via getWidgetRegistryEntry("label")', () => {
		const entry = getWidgetRegistryEntry('label')
		expect(entry).toBeTruthy()
		expect(entry.type).toBe('label')
		expect(entry.component).toBeTruthy()
		expect(entry.form).toBeTruthy()
	})
})
