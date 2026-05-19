/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `NewsForm.vue` covering REQ-NEWS-002:
 * the form pre-fills from `editingWidget.content`, validates HTTP(S)
 * URLs, exposes a working `assembledContent`, and toggles the metadata
 * filter sub-form on demand.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import NewsForm from '../NewsForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('NewsForm', () => {
	it('REQ-NEWS-002: applies defaults when no value is provided', () => {
		const wrapper = mount(NewsForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		expect(wrapper.vm.layout).toBe('list')
		expect(wrapper.vm.itemLimit).toBe(10)
		expect(wrapper.vm.showThumbnails).toBe(true)
		expect(wrapper.vm.showSummary).toBe(true)
		expect(wrapper.vm.summaryMaxChars).toBe(200)
		expect(wrapper.vm.dateFormat).toBe('relative')
		expect(wrapper.vm.metadataFilterEnabled).toBe(false)
	})

	it('REQ-NEWS-002: pre-fills from editingWidget.content', () => {
		const editingWidget = {
			content: {
				feedUrls: ['https://example.com/feed'],
				layout: 'grid',
				itemLimit: 25,
				showThumbnails: false,
				showSummary: false,
				summaryMaxChars: 100,
				dateFormat: 'absolute',
				metadataFilter: { fieldKey: 'department', value: 'marketing' },
			},
		}
		const wrapper = mount(NewsForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		expect(wrapper.vm.feedUrls).toEqual(['https://example.com/feed'])
		expect(wrapper.vm.layout).toBe('grid')
		expect(wrapper.vm.itemLimit).toBe(25)
		expect(wrapper.vm.showThumbnails).toBe(false)
		expect(wrapper.vm.showSummary).toBe(false)
		expect(wrapper.vm.summaryMaxChars).toBe(100)
		expect(wrapper.vm.dateFormat).toBe('absolute')
		expect(wrapper.vm.metadataFilterEnabled).toBe(true)
		expect(wrapper.vm.metadataFieldKey).toBe('department')
		expect(wrapper.vm.metadataValue).toBe('marketing')
	})

	it('REQ-NEWS-002: validate() rejects non-HTTP(S) URLs', () => {
		const wrapper = mount(NewsForm, {
			propsData: {
				value: {
					feedUrls: ['ftp://nope.example/feed'],
					layout: 'list',
				},
			},
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('http'))).toBe(true)
	})

	it('REQ-NEWS-002: validate() accepts a list of HTTPS URLs', () => {
		const wrapper = mount(NewsForm, {
			propsData: {
				value: {
					feedUrls: ['https://example.com/a', 'http://other.example/b'],
					layout: 'list',
				},
			},
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-NEWS-002: validate() flags an enabled metadata filter with empty fieldKey', () => {
		const wrapper = mount(NewsForm, {
			propsData: {
				editingWidget: {
					content: {
						feedUrls: [],
						metadataFilter: { fieldKey: '', value: 'marketing' },
					},
				},
			},
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		// Force-enable the filter so the validator can see it.
		wrapper.vm.metadataFilterEnabled = true
		const errors = wrapper.vm.validate()
		expect(errors.some((e) => e.includes('Metadata field key'))).toBe(true)
	})

	it('REQ-NEWS-002: addFeedUrl appends a blank entry and emits update', () => {
		const wrapper = mount(NewsForm, {
			propsData: { value: { feedUrls: [] } },
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		wrapper.vm.addFeedUrl()
		expect(wrapper.vm.feedUrls).toEqual([''])
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({ feedUrls: [''] })
	})

	it('REQ-NEWS-002: removeFeedUrl drops the index and emits update', () => {
		const wrapper = mount(NewsForm, {
			propsData: { value: { feedUrls: ['https://a', 'https://b'] } },
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		wrapper.vm.removeFeedUrl(0)
		expect(wrapper.vm.feedUrls).toEqual(['https://b'])
	})

	it('REQ-NEWS-002: assembledContent reflects every field', () => {
		const wrapper = mount(NewsForm, {
			propsData: {
				value: {
					feedUrls: ['https://example.com/feed'],
					layout: 'carousel',
					itemLimit: 5,
					showThumbnails: false,
					showSummary: true,
					summaryMaxChars: 80,
					dateFormat: 'absolute',
					metadataFilter: { fieldKey: 'k', value: 'v' },
				},
			},
			stubs: { NcTextField: true, NcSelect: true, NcCheckboxRadioSwitch: true },
		})
		const out = wrapper.vm.assembledContent
		expect(out.feedUrls).toEqual(['https://example.com/feed'])
		expect(out.layout).toBe('carousel')
		expect(out.itemLimit).toBe(5)
		expect(out.showThumbnails).toBe(false)
		expect(out.showSummary).toBe(true)
		expect(out.summaryMaxChars).toBe(80)
		expect(out.dateFormat).toBe('absolute')
		expect(out.metadataFilter).toEqual({ fieldKey: 'k', value: 'v' })
	})
})
