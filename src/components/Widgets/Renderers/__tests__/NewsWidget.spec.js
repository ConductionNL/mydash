/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `NewsWidget.vue` covering REQ-NEWS-008
 * (failure tolerance + badge), REQ-NEWS-009 (layout class switching),
 * REQ-NEWS-010 (empty / loading / error states), and REQ-NEWS-011
 * (anchor href + target + rel).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import NewsWidget from '../NewsWidget.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => path.replace('{placementId}', params.placementId),
}))

/**
 * Flush microtasks + the next Vue tick so async data loaders settle.
 * `@vue/test-utils` v1 (Vue 2) does not export `flushPromises`.
 *
 * @param {object} wrapper mounted component wrapper
 */
async function settle(wrapper) {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	globalThis.t = (_app, key) => key
	axios.get.mockReset()
})

describe('NewsWidget', () => {
	it('REQ-NEWS-009: applies layout class from content.layout', async () => {
		axios.get.mockResolvedValue({ data: { items: [], feedsFailed: 0, failedUrls: [] } })

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: { layout: 'grid' },
				placement: { id: 5 },
			},
		})
		await settle(wrapper)

		expect(wrapper.classes()).toContain('news-widget--grid')
	})

	it('REQ-NEWS-009: defaults to list when layout is unknown', async () => {
		axios.get.mockResolvedValue({ data: { items: [], feedsFailed: 0, failedUrls: [] } })

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: { layout: 'totally-bogus' },
				placement: { id: 7 },
			},
		})
		await settle(wrapper)

		expect(wrapper.classes()).toContain('news-widget--list')
	})

	it('REQ-NEWS-010: renders empty state when no items returned', async () => {
		axios.get.mockResolvedValue({ data: { items: [], feedsFailed: 0, failedUrls: [] } })

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: {},
				placement: { id: 1 },
			},
		})
		await settle(wrapper)

		expect(wrapper.find('.news-widget__state--empty').exists()).toBe(true)
		expect(wrapper.find('.news-widget__state--empty').text()).toContain(
			'No news yet — try adding feeds in the widget settings',
		)
	})

	it('REQ-NEWS-010: renders error message when fetch rejects', async () => {
		axios.get.mockRejectedValue(new Error('boom'))

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: {},
				placement: { id: 1 },
			},
		})
		await settle(wrapper)

		expect(wrapper.find('.news-widget__state--error').exists()).toBe(true)
		expect(wrapper.find('.news-widget__state--error').text()).toContain(
			'Unable to load news. Please try again later.',
		)
	})

	it('REQ-NEWS-008: renders failure badge with count when feeds failed', async () => {
		axios.get.mockResolvedValue({
			data: {
				items: [],
				feedsFailed: 2,
				failedUrls: ['https://bad1.example', 'https://bad2.example'],
			},
		})

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: {},
				placement: { id: 1 },
			},
		})
		await settle(wrapper)

		const badge = wrapper.find('.news-widget__badge')
		expect(badge.exists()).toBe(true)
		expect(badge.text()).toContain('2')
		expect(badge.attributes('title')).toContain('https://bad1.example')
	})

	it('REQ-NEWS-011: renders anchor with target=_blank rel=noopener noreferrer for items with link', async () => {
		axios.get.mockResolvedValue({
			data: {
				items: [{
					guid: 'a',
					title: 'Hello',
					link: 'https://example.com/post',
					summary: '',
					pubDate: '',
					sourceTitle: 'Example',
					thumbnailUrl: null,
				}],
				feedsFailed: 0,
				failedUrls: [],
			},
		})

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: {},
				placement: { id: 1 },
			},
		})
		await settle(wrapper)

		const anchor = wrapper.find('a.news-widget__item-link')
		expect(anchor.exists()).toBe(true)
		expect(anchor.attributes('href')).toBe('https://example.com/post')
		expect(anchor.attributes('target')).toBe('_blank')
		expect(anchor.attributes('rel')).toBe('noopener noreferrer')
	})

	it('REQ-NEWS-011: renders inert wrapper when item has no link', async () => {
		axios.get.mockResolvedValue({
			data: {
				items: [{
					guid: 'b',
					title: 'No link',
					link: '',
					summary: '',
					pubDate: '',
					sourceTitle: 'Example',
					thumbnailUrl: null,
				}],
				feedsFailed: 0,
				failedUrls: [],
			},
		})

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: {},
				placement: { id: 1 },
			},
		})
		await settle(wrapper)

		expect(wrapper.find('a.news-widget__item-link').exists()).toBe(false)
		expect(wrapper.find('.news-widget__item-link--inert').exists()).toBe(true)
	})

	it('REQ-NEWS-002: passes itemLimit query param from content.itemLimit', async () => {
		axios.get.mockResolvedValue({ data: { items: [], feedsFailed: 0, failedUrls: [] } })

		const wrapper = mount(NewsWidget, {
			propsData: {
				content: { itemLimit: 25 },
				placement: { id: 42 },
			},
		})
		await settle(wrapper)

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/api/widgets/news/42/items'),
			{ params: { limit: 25 } },
		)
	})
})
