---
capability: legacy-widget-bridge
delta: true
status: draft
---

# Legacy Widget Bridge — Delta from change `nc-dashboard-widget-proxy`

## ADDED Requirements

### Requirement: REQ-LWB-005 Polling helper for late callback registration

The bridge singleton MUST expose `pollForCallback(widgetId: string, options?: {intervalMs?: number, maxRetries?: number, signal?: AbortSignal}): Promise<boolean>` that periodically checks whether a callback has been registered for the given `widgetId`. Defaults: `intervalMs = 200`, `maxRetries = 15` (~3 s total). The promise MUST resolve to `true` as soon as a callback is detected, OR to `false` after the max retries are exhausted. Each call MUST be cancellable via the AbortSignal of an `AbortController` passed in `options.signal` (so callers can abort polling on unmount).

This helper exists to support the `nc-widget` renderer (REQ-WDG-019), which needs to fall through to API rendering immediately while still upgrading to native-callback rendering when the widget bundle finishes loading later.

#### Scenario: Resolve true when callback registers mid-poll

- GIVEN no callback for `'notes'` is currently registered
- WHEN a caller invokes `pollForCallback('notes')` (defaults)
- AND a `OCA.Dashboard.register('notes', cb)` call happens 600 ms later
- THEN the promise MUST resolve `true` within ~800 ms (next poll tick after registration)

#### Scenario: Resolve false on timeout

- GIVEN no callback for `'fictional_widget'` is ever registered
- WHEN `pollForCallback('fictional_widget')` is invoked
- THEN after ~3 seconds (15 x 200 ms) the promise MUST resolve `false`
- AND no further interval ticks MUST fire

#### Scenario: Abort cancels polling

- GIVEN a poll is in progress for `'notes'`
- WHEN the caller aborts via `AbortController.abort()`
- THEN the promise MUST resolve `false` immediately
- AND the underlying `setInterval` MUST be cleared
- AND no further poll ticks MUST run

#### Scenario: Already-registered callback resolves immediately

- GIVEN a callback for `'notes'` IS already in the bridge map
- WHEN `pollForCallback('notes')` is invoked
- THEN the promise MUST resolve `true` synchronously (or on the very next microtask)
- AND no `setInterval` MUST be scheduled

### Requirement: REQ-LWB-006 hasWidgetCallback consistency

`hasWidgetCallback(widgetId)` (already declared in REQ-LWB-004) MUST return `true` if and only if `pollForCallback` would resolve `true` immediately. The polling helper MUST use `hasWidgetCallback` internally as its check function — there MUST NOT be two parallel "is registered" code paths.

#### Scenario: Single source of truth

- WHEN the polling helper checks for registration
- THEN it MUST call `this.hasWidgetCallback(widgetId)`
- AND the result of `hasWidgetCallback` and the poll's first synchronous check MUST agree
