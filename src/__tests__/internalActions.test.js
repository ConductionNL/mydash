/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, vi } from 'vitest'
/* eslint-enable n/no-unpublished-import */

/**
 * Vitest tests for useInternalActions composable (REQ-LBN-005 + task 6.6).
 *
 * The registry is a module-level singleton so we re-import the module once per
 * file; individual tests clean up their own registrations.
 */

import { useInternalActions } from '../composables/useInternalActions.js'

describe('useInternalActions — register + invoke happy path', () => {
	it('registers and invokes a function once', () => {
		const fn = vi.fn()
		const { register, invoke } = useInternalActions()

		register('test-happy', fn)
		invoke('test-happy')

		expect(fn).toHaveBeenCalledOnce()
	})

	it('has() returns true for a registered id', () => {
		const { register, has } = useInternalActions()
		register('has-test', () => {})
		expect(has('has-test')).toBe(true)
	})

	it('has() returns false for an unknown id', () => {
		const { has } = useInternalActions()
		expect(has('definitely-not-registered-xyz')).toBe(false)
	})

	it('overwrites an existing registration without error', () => {
		const fn1 = vi.fn()
		const fn2 = vi.fn()
		const { register, invoke } = useInternalActions()

		register('overwrite-test', fn1)
		register('overwrite-test', fn2)
		invoke('overwrite-test')

		expect(fn1).not.toHaveBeenCalled()
		expect(fn2).toHaveBeenCalledOnce()
	})
})

describe('useInternalActions — warn-on-miss (REQ-LBN-005)', () => {
	it('logs console.warn with the unknown id', () => {
		const warnMock = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const { invoke } = useInternalActions()

		invoke('no-such-action-abc')

		expect(warnMock).toHaveBeenCalledWith(
			expect.stringContaining('no-such-action-abc'),
		)

		warnMock.mockRestore()
	})

	it('does not throw when id is missing', () => {
		const { invoke } = useInternalActions()
		expect(() => invoke('does-not-exist-either')).not.toThrow()
	})
})

describe('useInternalActions — singleton behaviour', () => {
	it('returns the same registry from multiple calls', () => {
		const a = useInternalActions()
		const b = useInternalActions()

		const fn = vi.fn()
		a.register('singleton-test', fn)

		// The registry from a second call should see the same map entry.
		expect(b.has('singleton-test')).toBe(true)
		b.invoke('singleton-test')
		expect(fn).toHaveBeenCalledOnce()
	})
})
