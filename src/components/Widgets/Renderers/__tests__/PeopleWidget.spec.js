/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `PeopleWidget.vue` covering REQ-PPL-008 (three
 * layout modes), REQ-PPL-009 (empty / error states), REQ-PPL-010
 * (click-through href), REQ-PPL-011 (client-side search), and the local
 * client-side `daysToBirthday` helper.
 *
 * Network calls are entirely avoided; tests pre-seed `users` on the
 * mounted component or stub `fetchPage` so the lazy `@nextcloud/*`
 * imports are never triggered.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PeopleWidget from '../PeopleWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key, params) => {
		if (params) {
			return Object.entries(params).reduce(
				(acc, [k, v]) => acc.replace(`{${k}}`, String(v)),
				key,
			)
		}
		return key
	}
})

const sample = (uid, displayName, extra = {}) => ({
	uid,
	displayName,
	avatarUrl: `https://example.test/avatar/${uid}`,
	groups: [],
	...extra,
})

const mountWidget = (content = {}, users = []) => {
	const wrapper = mount(PeopleWidget, {
		propsData: { content },
		methods: { fetchPage: vi.fn() },
	})
	wrapper.setData({
		users,
		total: users.length,
		hasMore: false,
		loading: false,
	})
	return wrapper
}

describe('PeopleWidget', () => {
	it('REQ-PPL-010: renders each user as an <a> linking to /u/{uid}', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [
			sample('alice', 'Alice'),
			sample('bob', 'Bob'),
		])
		await wrapper.vm.$nextTick()

		const links = wrapper.findAll('a.people-widget__item')
		expect(links).toHaveLength(2)
		expect(links.at(0).attributes('href')).toBe('/u/alice')
		expect(links.at(1).attributes('href')).toBe('/u/bob')
	})

	it('REQ-PPL-008: list layout uses 44 px avatar', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [sample('alice', 'Alice')])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.avatarSize).toBe(44)
		expect(wrapper.classes('people-widget--list')).toBe(true)
	})

	it('REQ-PPL-008: card layout uses 80 px avatar', async () => {
		const wrapper = mountWidget({ layout: 'card' }, [sample('alice', 'Alice')])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.avatarSize).toBe(80)
	})

	it('REQ-PPL-008: grid layout uses 64 px avatar and applies columns to grid-template-columns', async () => {
		const wrapper = mountWidget({ layout: 'grid', columns: 4 }, [sample('alice', 'Alice')])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.avatarSize).toBe(64)
		expect(wrapper.vm.gridStyle['grid-template-columns']).toBe('repeat(4, minmax(0, 1fr))')
	})

	it('REQ-PPL-009: empty results render the localised "No matching users." copy', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [])
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('No matching users.')
	})

	it('REQ-PPL-009: error state renders Failed-to-load message + Retry', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [])
		wrapper.setData({ error: new Error('boom') })
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Failed to load users')
		expect(wrapper.find('button.people-widget__retry').exists()).toBe(true)
	})

	it('REQ-PPL-011: search filters by displayName case-insensitive', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [
			sample('alice', 'Alice Smith'),
			sample('bob', 'Bob Jones'),
		])
		wrapper.setData({ search: 'ALICE' })
		await wrapper.vm.$nextTick()

		const links = wrapper.findAll('a.people-widget__item')
		expect(links).toHaveLength(1)
		expect(links.at(0).attributes('href')).toBe('/u/alice')
	})

	it('REQ-PPL-011: search matches on email substring', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [
			sample('alice', 'Alice', { email: 'alice@example.com' }),
			sample('bob', 'Bob', { email: 'bob@elsewhere.org' }),
		])
		wrapper.setData({ search: 'example.com' })
		await wrapper.vm.$nextTick()

		const links = wrapper.findAll('a.people-widget__item')
		expect(links).toHaveLength(1)
		expect(links.at(0).attributes('href')).toBe('/u/alice')
	})

	it('REQ-PPL-011: empty search shows all users', async () => {
		const wrapper = mountWidget({ layout: 'list' }, [
			sample('alice', 'Alice'),
			sample('bob', 'Bob'),
		])
		wrapper.setData({ search: '' })
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('a.people-widget__item')).toHaveLength(2)
	})

	it('REQ-PPL-005: daysToBirthday wraps to next year when birthday already passed', () => {
		const wrapper = mountWidget({ layout: 'list' }, [])
		const today = new Date()
		const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)
		const isoYesterday = `1990-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`

		const days = wrapper.vm.daysToBirthday(isoYesterday)
		// "yesterday" wraps to next year — days must be > 360
		expect(days).toBeGreaterThan(360)
	})

	it('REQ-PPL-005: showBirthdayBadge respects birthdayWindowDays', async () => {
		const wrapper = mountWidget(
			{ layout: 'card', showBirthdays: true, birthdayWindowDays: 7 },
			[],
		)
		const today = new Date()
		const inThreeDays = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 3)
		const iso = `1990-${String(inThreeDays.getMonth() + 1).padStart(2, '0')}-${String(inThreeDays.getDate()).padStart(2, '0')}`

		expect(wrapper.vm.showBirthdayBadge({ birthdate: iso })).toBe(true)
		expect(wrapper.vm.showBirthdayBadge({ birthdate: null })).toBe(false)
	})

	it('REQ-PPL-012: forceRefresh clears users + cache and triggers a refetch', async () => {
		const fetchSpy = vi.fn()
		const wrapper = mount(PeopleWidget, {
			propsData: { content: { layout: 'list' } },
			methods: { fetchPage: fetchSpy },
		})
		wrapper.setData({
			users: [sample('alice', 'Alice')],
			total: 1,
			cacheKey: 'something',
			cacheStoredAt: Date.now(),
		})
		fetchSpy.mockClear()

		wrapper.vm.forceRefresh()

		expect(wrapper.vm.users).toEqual([])
		expect(wrapper.vm.cacheKey).toBe('')
		expect(fetchSpy).toHaveBeenCalledWith(0)
	})
})
