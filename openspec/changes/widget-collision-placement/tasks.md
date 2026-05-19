# Tasks — widget-collision-placement

## Tasks

- [ ] Task 1: Export `placeNewWidget(spec): {x, y, w, h}` from `src/composables/useGridManager.js` with `DEFAULT_W = 4` + `DEFAULT_H = 4` constants applied when the caller omits `w`/`h`, and an inline comment linking REQ-GRID-006 + REQ-GRID-014 + the "top-left + push-down" rationale from design.md
- [ ] Task 2: Step 1 — `placeNewWidget` tries `grid.addWidget({autoPosition: true, ...spec})` and captures the resulting position
- [ ] Task 3: Step 2 — when step 1 returns no slot OR the slot is below `viewportRows`, fall back to push-down: iterate `layout.value`, compute overlap with `[0..newW] × [0..newH]`, set `widget.gridY = newH` for overlappers, call `grid.update(el, {y})` for each; non-overlapping widgets stay untouched and pushed widgets keep their `gridX` + `gridW`
- [ ] Task 4: Refactor `src/components/AddWidgetModal.vue` submit handler to call `placeNewWidget(spec)` only; remove any direct `grid.addWidget(...)` calls from component templates; confirm toolbar dropdown + keyboard shortcut + drag-from-picker code paths all funnel through the helper
- [ ] Task 5: Trigger the batch placement-update API after placement (debounced 300ms — reuse the REQ-WDG-008 pattern) in a single round-trip carrying both the new widget and all pushed-down widgets; verify the persisted positions match the in-memory `layout.value` after the round-trip resolves
- [ ] Task 6: Vitest — `placeNewWidget` auto-positions into empty space when GridStack finds a slot (no pushes); push-down fallback runs when the top region is full and overlappers gain `gridY = newH`; non-overlapping widgets unchanged
- [ ] Task 7: Vitest — default size `(4, 4)` is applied when `spec.w`/`spec.h` are omitted; pushed widgets keep their `gridX` + `gridW` (only `gridY` changes)
- [ ] Task 8: Playwright — add 5 widgets in sequence to a 12-col empty dashboard with visually-correct positions; add a 6th widget to a top-full dashboard and confirm the push-down fallback shifts overlappers down; position writes survive page reload (REQ-GRID-005)
- [ ] Task 9: Architectural enforcement — add a grep test (Vitest or CI script) asserting `grid.addWidget` only appears inside `useGridManager.js` + its test file; document the rule in the composable file's header comment so reviewers see it during code review
- [ ] Task 10: Quality — ESLint clean on touched JS/Vue files; no new PHPCS/PHPMD/PHPStan/Psalm regressions (frontend-only change, but run `composer check:strict` to confirm); i18n review — no new user-facing strings expected; if any are introduced, add them to both `nl` and `en`

## Verification

`openspec validate` exits clean. New widgets always land in the top-left valid slot with deterministic push-down behaviour and the grep guard prevents future drift back to ad-hoc `grid.addWidget` calls.

## Tests (company-wide ADR-009)

Vitest per Tasks 6–7; Playwright per Task 8. No new backend surface.

## Documentation (company-wide ADR-010)

Inline composable comment per Task 9; changelog entry covering the unified placement helper.

## i18n (company-wide ADR-005)

No user-facing strings expected; parity in `nl`+`en` only if Task 10 surfaces any.
