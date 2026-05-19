# Tasks — widget-context-menu

## Tasks

- [ ] Task 1: Create `src/components/Widgets/WidgetContextMenu.vue` with three buttons (`Edit`, `Remove`, `Cancel`) and `top`/`left` props; styled `position:absolute; min-width:150px; z-index:10000`, NC-themed background, rounded corners, subtle shadow
- [ ] Task 2: Emit `edit`, `remove`, `close` events; each click closes the popover via `closeContextMenu()`; button labels use `t('mydash', 'Edit' | 'Remove' | 'Cancel')`
- [ ] Task 3: Extend `useGridManager.js` with reactive state `contextMenuOpen`, `contextMenuPosition` (`{x, y}`), `selectedWidget`
- [ ] Task 4: Add `onWidgetRightClick(event, widget)` — early-return when `!canEdit.value`; call `event.preventDefault()`; capture `clientX`/`clientY`; set `selectedWidget` + `contextMenuPosition`; set `contextMenuOpen = true`
- [ ] Task 5: Add `closeContextMenu()` (sets `contextMenuOpen=false`, clears `selectedWidget`); extend the existing `handleClickOutside` to also close when the click target is outside `.widget-context-menu`; register the single shared document `click` listener in `onMounted`/`onUnmounted`
- [ ] Task 6: Viewport overflow correction — when computing rendered `left`/`top`, subtract overflow from `viewportWidth`/`viewportHeight` so the popover stays on-screen
- [ ] Task 7: Shell wiring — bind `@contextmenu.prevent="onWidgetRightClick($event, widget)"` on each grid item; render `<WidgetContextMenu>` once at the shell root, conditional on `contextMenuOpen`, with `:top`/`:left` from `contextMenuPosition`; wire `@edit` → `editWidget(widget)` (opens `AddWidgetModal` with `editingWidget`), `@remove` → REQ-WDG-005 placement-delete path then splice from `layout` + `grid.removeWidget(el)`, `@close` → `closeContextMenu()` only
- [ ] Task 8: Vitest — view mode (`canEdit=false`) right-click does NOT open the popover and does NOT call `preventDefault`; edit mode right-click opens the popover at the captured `clientX`/`clientY`; right-clicking a second widget switches the popover (only one visible at a time)
- [ ] Task 9: Vitest — `Edit` click closes the popover and emits `edit(widget)` once; `Remove` click closes the popover + calls the placement-delete path + removes from `layout`; `Cancel` click closes the popover with no API call
- [ ] Task 10: Vitest — outside click closes the popover; the document listener is removed on unmount (no leak)
- [ ] Task 11: Playwright — popover stays fully on-screen when right-clicking near the right and bottom edges; removing a widget through the popover persists across reload
- [ ] Task 12: Quality — ESLint clean (no new warnings); `Edit`/`Remove`/`Cancel` translation entries in `l10n/en.js` + `l10n/nl.js`; file a follow-up issue for keyboard navigation (Up/Down/Enter/Esc) on the context menu (deferred from v1)

## Verification

`openspec validate` exits clean. Right-click in view mode is a no-op; in edit mode the popover handles three actions, closes via outside click + Cancel, and stays on-screen at viewport edges.

## Tests (company-wide ADR-009)

Vitest per Tasks 8–10; Playwright per Task 11. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry covering the new context-menu UX.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for `Edit`, `Remove`, `Cancel` per Task 12.
