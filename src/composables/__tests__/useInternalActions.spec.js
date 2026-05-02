/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useInternalActions` composable. Covers
 * REQ-LBN-005: register/invoke happy path, has() lookup, missing-id
 * `console.warn`-without-throw behaviour, and the singleton contract
 * (separate `useInternalActions()` calls share the same Map).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { useInternalActions, __resetInternalActionsForTest } from '../useInternalActions.js'

beforeEach(() => {
	__resetInternalActionsForTest()
})

afterEach(() => {
	__resetInternalActionsForTest()
})

describe('useInternalActions', () => {
	it('REQ-LBN-005: register + invoke happy path runs the registered function exactly once', () => {
		const fn = vi.fn()
		const { register, invoke } = useInternalActions()
		register('open-talk', fn)

		invoke('open-talk')

		expect(fn).toHaveBeenCalledTimes(1)
	})

	it('REQ-LBN-005: invoke() with unknown id logs console.warn and does not throw', () => {
		const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => null)
		const { invoke } = useInternalActions()

		expect(() => invoke('does-not-exist')).not.toThrow()
		expect(warnSpy).toHaveBeenCalledWith('Unknown internal action: does-not-exist')

		warnSpy.mockRestore()
	})

	it('REQ-LBN-005: has() returns true for registered ids and false otherwise', () => {
		const { register, has } = useInternalActions()
		register('present', () => undefined)

		expect(has('present')).toBe(true)
		expect(has('absent')).toBe(false)
	})

	it('REQ-LBN-005: registry is a singleton — separate calls share state', () => {
		const fn = vi.fn()
		useInternalActions().register('shared', fn)

		// Second consumer.
		const { invoke } = useInternalActions()
		invoke('shared')

		expect(fn).toHaveBeenCalledTimes(1)
	})

	it('REQ-LBN-005: re-registering the same id replaces the previous function', () => {
		const first = vi.fn()
		const second = vi.fn()
		const { register, invoke } = useInternalActions()

		register('act', first)
		register('act', second)
		invoke('act')

		expect(first).not.toHaveBeenCalled()
		expect(second).toHaveBeenCalledTimes(1)
	})

	it('REQ-LBN-005: registering an empty id or non-function silently no-ops', () => {
		const { register, has } = useInternalActions()
		register('', () => undefined)
		register('foo', null)
		register(undefined, () => undefined)

		expect(has('')).toBe(false)
		expect(has('foo')).toBe(false)
	})

	it('REQ-LBN-005: invoke() forwards the registered function\'s return value', () => {
		const { register, invoke } = useInternalActions()
		register('plain', () => 'value')

		expect(invoke('plain')).toBe('value')
	})
})
