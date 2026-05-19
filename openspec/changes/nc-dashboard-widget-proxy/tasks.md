# Tasks — nc-dashboard-widget-proxy

## Tasks

- [ ] Task 1: Add `WidgetBridge::pollForCallback(widgetId, options)` returning a cancellable `Promise<boolean>` — `setInterval` with cleanup on resolve/abort/max-retries; first check synchronous (no `setInterval` if already registered); internally calls `hasWidgetCallback` as the single source of truth
- [ ] Task 2: Create `src/components/Widgets/Renderers/NcDashboardWidget.vue` — on mount try native; if absent fire API request AND `pollForCallback`; switch to native if poll resolves true (race winner wins, no flicker)
- [ ] Task 3: Renderer hardening — defensive normalisation for PHP-serialised sequential arrays (`Array.isArray(injected) ? injected : Object.values(injected)`); header shows title + iconUrl from `widgetMeta`; two display modes per REQ-WDG-020 CSS
- [ ] Task 4: Create `src/components/Widgets/Forms/NcDashboardForm.vue` — picker `<select>` from initial-state `widgets`; display-mode `<select>` (vertical/horizontal); `validate()` requires non-empty `widgetId`; pre-fill from `editingWidget.content`
- [ ] Task 5: Register `nc-widget` in `widgetRegistry.js` with defaults `{widgetId:'', displayMode:'vertical'}`
- [ ] Task 6: Vitest bridge — `pollForCallback` happy path (callback registers mid-poll → resolves true); timeout (no registration → resolves false after ~3s); abort (signal aborts → resolves false immediately); synchronous resolve when already registered
- [ ] Task 7: Vitest renderer — switches mode mid-flight when poll wins; array normalisation handles object-with-numeric-keys input
- [ ] Task 8: Playwright — `weather_status` widget renders natively when the bundle is present; widget falls back to API list when the bundle is absent; empty-list state shows the translated string
- [ ] Task 9: Quality — ESLint clean
- [ ] Task 10: i18n — `nl_NL` + `en_US` translations for `Nextcloud Widget`, `Select Widget`, `Choose a widget…`, `Display Mode`, `Vertical (list)`, `Horizontal (cards)`, `Loading…`, `No items available`

## Verification

`openspec validate` exits clean. Both rendering modes (native + API fallback) work for at least one stock NC widget; poll cancellation leaks no intervals.

## Tests (company-wide ADR-009)

Vitest per Tasks 6–7; Playwright per Task 8. No new backend surface.

## Documentation (company-wide ADR-010)

Changelog entry covering the new widget type + the bridging + fallback behaviour.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 10.
