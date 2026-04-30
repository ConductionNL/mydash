# Tasks — widget-context-menu

## 1. Frontend component

- [ ] 1.1 Create `src/components/Widgets/WidgetContextMenu.vue` with three buttons (`Edit`, `Remove`, `Cancel`) and `top` / `left` props
- [ ] 1.2 Style: `position: absolute`, `min-width: 150px`, `z-index: 10000`, NC-themed background, rounded corners, subtle shadow
- [ ] 1.3 Emit `edit`, `remove`, `close` events; each click closes the popover via `closeContextMenu()`
- [ ] 1.4 Use `t('mydash', 'Edit' | 'Remove' | 'Cancel')` for button labels (i18n-ready)

## 2. Composable wiring

- [ ] 2.1 In `useGridManager.js` add reactive state: `contextMenuOpen`, `contextMenuPosition` (`{x, y}`), `selectedWidget`
- [ ] 2.2 Add `onWidgetRightClick(event, widget)` — early-return when `!canEdit.value`; call `event.preventDefault()`; capture `clientX/clientY`; set `selectedWidget` and `contextMenuPosition`; set `contextMenuOpen = true`
- [ ] 2.3 Add `closeContextMenu()` — sets `contextMenuOpen = false`, clears `selectedWidget`
- [ ] 2.4 Extend existing `handleClickOutside` to also close the context menu when the click target is not inside `.widget-context-menu`
- [ ] 2.5 Add `onMounted` / `onUnmounted` hooks for the document-level `click` listener (single shared listener for grid + popover)
- [ ] 2.6 Viewport overflow correction: when computing the rendered `left` / `top`, subtract overflow from `viewportWidth` / `viewportHeight` so popover stays on-screen

## 3. Shell wiring

- [ ] 3.1 In the workspace shell template, bind `@contextmenu.prevent="onWidgetRightClick($event, widget)"` on each grid item
- [ ] 3.2 Render `<WidgetContextMenu>` once at the shell root, conditional on `contextMenuOpen`, with `:top` / `:left` from `contextMenuPosition`
- [ ] 3.3 Wire `@edit` → `editWidget(widget)` (opens `AddWidgetModal` with `editingWidget`); `@remove` → call placement-delete path of REQ-WDG-005, on success splice from `layout` and call `grid.removeWidget(el)`; `@close` → `closeContextMenu()` only

## 4. Tests

- [ ] 4.1 Vitest: in view mode (`canEdit = false`), right-click does NOT open the popover and does NOT call `preventDefault`
- [ ] 4.2 Vitest: in edit mode, right-click opens the popover at the captured `clientX/clientY`
- [ ] 4.3 Vitest: clicking `Edit` closes the popover and emits `edit(widget)` once
- [ ] 4.4 Vitest: clicking `Remove` closes the popover, calls the placement-delete path, then removes from `layout`
- [ ] 4.5 Vitest: clicking `Cancel` closes the popover and fires no API call
- [ ] 4.6 Vitest: outside click closes the popover; the document listener is removed on unmount
- [ ] 4.7 Vitest: right-clicking a second widget switches the popover (only one visible at a time)
- [ ] 4.8 Playwright: popover stays fully on-screen when right-clicking near the right edge and near the bottom edge
- [ ] 4.9 Playwright: removing a widget through the popover persists across reload

## 5. Quality

- [ ] 5.1 ESLint clean (no new warnings)
- [ ] 5.2 Translation entries `Edit`, `Remove`, `Cancel` present in `l10n/en.js` and `l10n/nl.js`
- [ ] 5.3 File a follow-up issue: keyboard navigation (Up/Down/Enter/Esc) for the context menu (deferred from v1)
