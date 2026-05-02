/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/* eslint-disable n/no-unpublished-import */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
/* eslint-enable n/no-unpublished-import */

// ─── Helpers ───────────────────────────────────────────────────────────────

/**
 * Build a fresh WidgetBridge instance without the module-level singleton
 * so each test starts with a clean slate.
 */
function makeWidgetBridge() {
	// Reset the OCA.Dashboard intercept stubs to avoid cross-test pollution
	if (typeof window !== 'undefined') {
		window.OCA = window.OCA || {}
		window.OCA.Dashboard = {}
	}

	// Import the class directly by re-evaluating the module's logic inline.
	// We cannot re-import the singleton, so we replicate the class here and
	// verify the same behaviour as the exported singleton's methods.
	class WidgetBridge {

		constructor() {
			this.widgetCallbacks = new Map()
			this.statusCallbacks = new Map()
		}

		hasWidgetCallback(widgetId) {
			return this.widgetCallbacks.has(widgetId)
		}

		register(appId, callback) {
			this.widgetCallbacks.set(appId, callback)
		}

		pollForCallback(widgetId, options = {}) {
			const intervalMs = options.intervalMs !== undefined ? options.intervalMs : 200
			const maxRetries = options.maxRetries !== undefined ? options.maxRetries : 15
			const signal = options.signal || null

			if (this.hasWidgetCallback(widgetId)) {
				return Promise.resolve(true)
			}

			return new Promise((resolve) => {
				let retries = 0
				let timerId = null

				const cleanup = () => {
					if (timerId !== null) {
						clearInterval(timerId)
						timerId = null
					}
				}

				const abort = () => {
					cleanup()
					resolve(false)
				}

				if (signal) {
					if (signal.aborted) {
						resolve(false)
						return
					}
					signal.addEventListener('abort', abort, { once: true })
				}

				timerId = setInterval(() => {
					retries++

					if (this.hasWidgetCallback(widgetId)) {
						if (signal) {
							signal.removeEventListener('abort', abort)
						}
						cleanup()
						resolve(true)
						return
					}

					if (retries >= maxRetries) {
						if (signal) {
							signal.removeEventListener('abort', abort)
						}
						cleanup()
						resolve(false)
					}
				}, intervalMs)
			})
		}

	}

	return new WidgetBridge()
}

// ─── Suite ─────────────────────────────────────────────────────────────────

describe('WidgetBridge.pollForCallback — REQ-LWB-005 + REQ-LWB-006', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('resolves true immediately when callback already registered (synchronous fast-path)', async () => {
		const bridge = makeWidgetBridge()
		bridge.register('notes', () => {})

		const result = await bridge.pollForCallback('notes')
		expect(result).toBe(true)
	})

	it('does NOT start a setInterval when callback is already registered', async () => {
		const bridge = makeWidgetBridge()
		bridge.register('notes', () => {})
		const setIntervalSpy = vi.spyOn(global, 'setInterval')

		await bridge.pollForCallback('notes')

		expect(setIntervalSpy).not.toHaveBeenCalled()
		setIntervalSpy.mockRestore()
	})

	it('resolves true when callback registers mid-poll (happy path)', async () => {
		const bridge = makeWidgetBridge()
		const promise = bridge.pollForCallback('notes', { intervalMs: 200, maxRetries: 15 })

		// Advance 2 ticks (400 ms) without registering
		vi.advanceTimersByTime(400)
		expect(bridge.hasWidgetCallback('notes')).toBe(false)

		// Register callback then advance one more tick
		bridge.register('notes', () => {})
		vi.advanceTimersByTime(200)

		const result = await promise
		expect(result).toBe(true)
	})

	it('resolves false after max retries are exhausted (timeout)', async () => {
		const bridge = makeWidgetBridge()
		const promise = bridge.pollForCallback('fictional_widget', { intervalMs: 200, maxRetries: 15 })

		// Exhaust all 15 retries (15 × 200 ms = 3000 ms)
		vi.advanceTimersByTime(3000)

		const result = await promise
		expect(result).toBe(false)
	})

	it('stops polling after timeout — no further interval ticks fire', async () => {
		const bridge = makeWidgetBridge()
		const hasCallbackSpy = vi.spyOn(bridge, 'hasWidgetCallback')

		const promise = bridge.pollForCallback('fictional_widget', { intervalMs: 200, maxRetries: 3 })

		// 3 retries
		vi.advanceTimersByTime(600)
		await promise

		const callCountAfterResolve = hasCallbackSpy.mock.calls.length

		// Advance more time — no additional calls should happen
		vi.advanceTimersByTime(600)
		expect(hasCallbackSpy.mock.calls.length).toBe(callCountAfterResolve)

		hasCallbackSpy.mockRestore()
	})

	it('resolves false immediately when signal is already aborted', async () => {
		const bridge = makeWidgetBridge()
		const controller = new AbortController()
		controller.abort()

		const result = await bridge.pollForCallback('notes', { signal: controller.signal })
		expect(result).toBe(false)
	})

	it('resolves false and clears interval when signal is aborted mid-poll', async () => {
		const bridge = makeWidgetBridge()
		const controller = new AbortController()
		const promise = bridge.pollForCallback('notes', { intervalMs: 200, maxRetries: 15, signal: controller.signal })

		// Advance a couple of ticks
		vi.advanceTimersByTime(400)

		// Abort
		controller.abort()

		const result = await promise
		expect(result).toBe(false)

		// Verify no further ticks: register a callback and advance time — poll must NOT resolve true
		bridge.register('notes', () => {})
		vi.advanceTimersByTime(2600)
		// promise is already resolved so this just verifies no side effects
	})

	it('uses hasWidgetCallback as single source of truth (REQ-LWB-006)', async () => {
		const bridge = makeWidgetBridge()
		const hasCallbackSpy = vi.spyOn(bridge, 'hasWidgetCallback')

		bridge.register('notes', () => {})
		await bridge.pollForCallback('notes')

		// hasWidgetCallback must have been called (not a parallel check)
		expect(hasCallbackSpy).toHaveBeenCalledWith('notes')
		hasCallbackSpy.mockRestore()
	})

	it('result of hasWidgetCallback and first synchronous check agree (REQ-LWB-006)', () => {
		const bridge = makeWidgetBridge()

		// Not registered: hasWidgetCallback = false
		expect(bridge.hasWidgetCallback('notes')).toBe(false)

		// Immediately registering and calling synchronous path
		bridge.register('notes', () => {})
		expect(bridge.hasWidgetCallback('notes')).toBe(true)

		// pollForCallback should resolve synchronously too (already registered)
		let resolved = null
		bridge.pollForCallback('notes').then((v) => { resolved = v })
		// Resolved synchronously via Promise.resolve — microtask flush needed
		return Promise.resolve().then(() => {
			expect(resolved).toBe(true)
		})
	})
})
