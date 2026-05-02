# Tasks — nc-dashboard-widget-proxy

## 1. Bridge

- [ ] Add `WidgetBridge::pollForCallback(widgetId, options)` returning a cancellable Promise<boolean>
- [ ] Use `setInterval` with cleanup on resolve, abort, or max retries
- [ ] First check is synchronous (no `setInterval` if already registered)
- [ ] Internally calls `hasWidgetCallback` (single source of truth)

## 2. Renderer

- [ ] Create `src/components/Widgets/Renderers/NcDashboardWidget.vue`
- [ ] On mount: try native; if absent, fire API request AND `pollForCallback`
- [ ] Switch to native if poll resolves true (race winner wins, no flicker)
- [ ] Defensive normalisation: PHP can serialise sequential arrays as objects; do `Array.isArray(injected) ? injected : Object.values(injected)`
- [ ] Header: title + iconUrl from `widgetMeta`
- [ ] Two display modes per REQ-WDG-020 CSS

## 3. Form

- [ ] Create `src/components/Widgets/Forms/NcDashboardForm.vue`
- [ ] Picker `<select>` from initial-state `widgets`
- [ ] Display-mode `<select>` (vertical/horizontal)
- [ ] `validate()` requires non-empty `widgetId`
- [ ] Pre-fill from `editingWidget.content`

## 4. Registry

- [ ] Add `nc-widget` to `widgetRegistry.js` with defaults `{widgetId:'', displayMode:'vertical'}`

## 5. Tests

- [ ] Vitest: `pollForCallback` happy path (callback registers mid-poll → resolves true)
- [ ] Vitest: timeout (no registration → resolves false after ~3 s)
- [ ] Vitest: abort (signal aborts → resolves false immediately)
- [ ] Vitest: synchronous resolve when already registered
- [ ] Vitest: renderer switches mode mid-flight when poll wins
- [ ] Vitest: array normalisation handles object-with-numeric-keys input
- [ ] Playwright: weather_status widget renders natively when bundle present
- [ ] Playwright: widget falls back to API list when bundle absent
- [ ] Playwright: empty-list state translated string

## 6. Quality

- [ ] ESLint clean
- [ ] Translation entries: `Nextcloud Widget`, `Select Widget`, `Choose a widget…`, `Display Mode`, `Vertical (list)`, `Horizontal (cards)`, `Loading…`, `No items available`
