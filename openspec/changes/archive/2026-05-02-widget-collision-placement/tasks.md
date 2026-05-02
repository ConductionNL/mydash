# Tasks — widget-collision-placement

## 1. Frontend composable

- [x] 1.1 Add `placeNewWidget(spec): {x, y, w, h}` exported from `src/composables/useGridManager.js`
- [x] 1.2 Step 1 — try `grid.addWidget({autoPosition: true, ...spec})` and capture the resulting position
- [x] 1.3 Step 2 — if step 1 returns no slot OR slot is below `viewportRows`, fall back to push-down
- [x] 1.4 Push-down implementation — iterate `layout.value`, compute overlap with `[0..newW] × [0..newH]`, set `widget.gridY = newH` for overlappers, call `grid.update(el, {y})` for each
- [x] 1.5 Define default size constants `DEFAULT_W = 4`, `DEFAULT_H = 4` and use them when caller omits `w`/`h`
- [x] 1.6 Inline comment on the helper linking REQ-GRID-006 + REQ-GRID-014 and the "top-left + push-down" rationale from design.md

## 2. Add-widget UI integration

- [x] 2.1 Refactor `src/components/AddWidgetModal.vue` submit handler to call `placeNewWidget(spec)` only
- [x] 2.2 Remove any direct `grid.addWidget(...)` calls from component templates
- [x] 2.3 Confirm toolbar dropdown, keyboard shortcut, and drag-from-picker code paths all funnel through the helper

## 3. Persistence

- [x] 3.1 Trigger batch placement-update API after placement (debounced 300ms — reuse existing pattern from REQ-WDG-008)
- [x] 3.2 Single round-trip carrying both the new widget and all pushed-down widgets
- [x] 3.3 Verify the persisted positions match the in-memory `layout.value` after the round-trip resolves

## 4. Vitest unit coverage

- [x] 4.1 `placeNewWidget` auto-positions into empty space when GridStack finds a slot (no pushes)
- [x] 4.2 Push-down fallback runs when the top region is full — overlapping widgets gain `gridY = newH`
- [x] 4.3 Non-overlapping widgets are unchanged after fallback runs
- [x] 4.4 Default size `(4, 4)` is applied when `spec.w` / `spec.h` are omitted
- [x] 4.5 Pushed widgets keep their `gridX` and `gridW` (only `gridY` changes)

## 5. Playwright e2e

- [ ] 5.1 Add 5 widgets in sequence to a 12-col empty dashboard; verify visually-correct positions — DEFERRED: no Playwright infra in this repo yet; covered by Vitest unit coverage in section 4
- [ ] 5.2 Add a 6th widget to a top-full dashboard; verify the push-down fallback shifts overlappers down — DEFERRED: same reason
- [ ] 5.3 Verify position writes survive a page reload (REQ-GRID-005 persistence) — DEFERRED: same reason

## 6. Architectural enforcement

- [x] 6.1 Add a grep test (Vitest or CI script) asserting `grid.addWidget` only appears inside `useGridManager.js` and its test file
- [x] 6.2 Document the rule in the composable file's header comment so reviewers see it during code review

## 7. Quality gates

- [x] 7.1 ESLint clean on touched JS / Vue files
- [x] 7.2 No new PHPCS/PHPMD/PHPStan/Psalm regressions — N/A, frontend-only change (no PHP files touched); composer not run in this worktree
- [x] 7.3 i18n review — no new user-facing strings expected; if any are introduced, add them to both `nl` and `en` per the i18n requirement
