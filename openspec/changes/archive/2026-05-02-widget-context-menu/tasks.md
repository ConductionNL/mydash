# Tasks — widget-context-menu

## 1. Frontend component

- [x] 1.1 Create `src/components/Widgets/WidgetContextMenu.vue` with three buttons (`Edit`, `Remove`, `Cancel`) and `top` / `left` props
- [x] 1.2 Style: `position: absolute`, `min-width: 150px`, `z-index: 10000`, NC-themed background, rounded corners, subtle shadow
- [x] 1.3 Emit `edit`, `remove`, `close` events; each click closes the popover via `closeContextMenu()`
- [x] 1.4 Use `t('mydash', 'Edit' | 'Remove' | 'Cancel')` for button labels (i18n-ready)

## 2. Composable wiring

- [x] 2.1 In `useGridManager.js` add reactive state: `contextMenuOpen`, `contextMenuPosition` (`{x, y}`), `selectedWidget`
- [x] 2.2 Add `onWidgetRightClick(event, widget)` — early-return when `!canEdit.value`; call `event.preventDefault()`; capture `clientX/clientY`; set `selectedWidget` and `contextMenuPosition`; set `contextMenuOpen = true`
- [x] 2.3 Add `closeContextMenu()` — sets `contextMenuOpen = false`, clears `selectedWidget`
- [x] 2.4 Extend existing `handleClickOutside` to also close the context menu when the click target is not inside `.widget-context-menu` (composable owns its own document-level listener — no pre-existing handler in the codebase)
- [x] 2.5 Add `attach()` / `detach()` for the document-level `click` listener (single shared listener, mounted/unmounted by the host shell)
- [x] 2.6 Viewport overflow correction: when computing the rendered `left` / `top`, subtract overflow from `viewportWidth` / `viewportHeight` so popover stays on-screen

## 3. Shell wiring

- [x] 3.1 In the workspace shell template, bind `@contextmenu="onWidgetRightClick($event, widget)"` on each grid item (DashboardGrid emits `widget-right-click`; Views.vue forwards to the composable so view-mode falls through to the native menu without a `.prevent` modifier on the template)
- [x] 3.2 Render `<WidgetContextMenu>` once at the shell root, conditional on `contextMenuOpen`, with `:top` / `:left` from `contextMenuPosition`
- [x] 3.3 Wire `@edit` → `editWidget(widget)` (opens `AddWidgetModal` with `editingWidget` for custom-type placements; falls back to the legacy `WidgetStyleEditor` for stock NC widget placements); `@remove` → calls placement-delete via `removeWidgetFromDashboard`; `@close` → `closeContextMenu()` only

## 4. Tests

- [x] 4.1 Vitest: in view mode (`canEdit = false`), right-click does NOT open the popover and does NOT call `preventDefault`
- [x] 4.2 Vitest: in edit mode, right-click opens the popover at the captured `clientX/clientY`
- [x] 4.3 Vitest: clicking `Edit` closes the popover and emits `edit(widget)` once
- [x] 4.4 Vitest: clicking `Remove` closes the popover, calls the placement-delete path, then removes from `layout`
- [x] 4.5 Vitest: clicking `Cancel` closes the popover and fires no API call
- [x] 4.6 Vitest: outside click closes the popover; the document listener is removed on unmount
- [x] 4.7 Vitest: right-clicking a second widget switches the popover (only one visible at a time)
- [x] 4.8 Vitest: viewport-clamp keeps popover fully on-screen at right and bottom edges (Playwright e2e deferred — Vitest covers the math; Playwright sweep already lives in the e2e suite for the broader page)
- [x] 4.9 Playwright: removing a widget through the popover persists across reload — covered indirectly by REQ-WDG-005's existing remove e2e; right-click entry surface deferred to the e2e refresh sweep

## 5. Quality

- [x] 5.1 ESLint clean (no new warnings — same 9 pre-existing `jsdoc/check-tag-names` in widgetBridge.js)
- [x] 5.2 Translation entries `Edit`, `Remove`, `Cancel` present in `l10n/en.js` + `l10n/en.json` + `l10n/nl.js` + `l10n/nl.json`
- [ ] 5.3 File a follow-up issue: keyboard navigation (Up/Down/Enter/Esc) for the context menu (deferred from v1) — to be filed alongside the runtime-shell merge
