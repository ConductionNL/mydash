/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `widgetBridge.pollForCallback` covering REQ-LWB-005
 * and REQ-LWB-006: synchronous resolution when the callback is already
 * registered, mid-poll registration upgrade, full timeout, and abort
 * cancellation.
 *
 * Tests run on the singleton instance — each test resets the
 * `widgetCallbacks` map to keep them deterministic in arbitrary order.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { widgetBridge } from '../widgetBridge.js'

beforeEach(() => {
	vi.useFakeTimers()
	widgetBridge.widgetCallbacks.clear()
})

afterEach(() => {
	vi.useRealTimers()
	widgetBridge.widgetCallbacks.clear()
})

describe('WidgetBridge.pollForCallback', () => {
	it('REQ-LWB-005: resolves true when callback registers mid-poll', async () => {
		const promise = widgetBridge.pollForCallback('notes', { intervalMs: 200, maxRetries: 15 })

		// First synchronous check has already run and returned false; advance
		// 600 ms then register, then run another tick so the next interval
		// fires and detects the registration.
		await vi.advanceTimersByTimeAsync(600)
		widgetBridge.widgetCallbacks.set('notes', () => {})
		await vi.advanceTimersByTimeAsync(200)

		await expect(promise).resolves.toBe(true)
	})

	it('REQ-LWB-005: resolves false after timeout when nothing registers', async () => {
		const promise = widgetBridge.pollForCallback('fictional_widget', { intervalMs: 200, maxRetries: 15 })

		// 15 ticks * 200 ms = 3 s.
		await vi.advanceTimersByTimeAsync(3000)

		await expect(promise).resolves.toBe(false)
	})

	it('REQ-LWB-005: aborts immediately when the signal fires', async () => {
		const controller = new AbortController()
		const promise = widgetBridge.pollForCallback('notes', { signal: controller.signal })

		controller.abort()

		await expect(promise).resolves.toBe(false)
	})

	it('REQ-LWB-005: aborts before scheduling when signal is already aborted', async () => {
		const controller = new AbortController()
		controller.abort()

		await expect(widgetBridge.pollForCallback('notes', { signal: controller.signal })).resolves.toBe(false)
	})

	it('REQ-LWB-006: synchronously resolves true when callback already registered', async () => {
		widgetBridge.widgetCallbacks.set('notes', () => {})

		// No timer advancement needed — first check is synchronous.
		await expect(widgetBridge.pollForCallback('notes')).resolves.toBe(true)
	})

	it('REQ-LWB-006: pollForCallback uses hasWidgetCallback as single source of truth', async () => {
		const spy = vi.spyOn(widgetBridge, 'hasWidgetCallback')
		widgetBridge.widgetCallbacks.set('notes', () => {})

		await widgetBridge.pollForCallback('notes')

		expect(spy).toHaveBeenCalledWith('notes')
		spy.mockRestore()
	})
})
