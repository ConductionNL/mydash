# Tasks — custom-icon-upload-pattern

## 1. Discriminator module

- [x] 1.1 Add `isCustomIconUrl(name)` export to `src/constants/dashboardIcons.js` returning `true` only for non-null strings beginning with `'/'` or `'http'` (already in place from PR #58 — `dashboard-icons` introduced the helper alongside the registry; this change locks the contract via REQ-ICON-005).
- [x] 1.2 Update `getIconComponent(name)` to return `null` when `isCustomIconUrl(name)` is true (must NOT fall back to `DEFAULT_ICON` for URLs)
- [x] 1.3 Add Vitest covering the truth table: URL prefixes (`/apps/...`, `http://`, `https://`), registry names (`Star`, `ViewDashboard`), and falsy inputs (`null`, `undefined`, `''`)
- [x] 1.4 Add Vitest asserting `getIconComponent` returns `null` for a URL input AND returns `DEFAULT_ICON` for an unknown registry name (REQ-ICON-001 still holds)

## 2. IconRenderer component

- [x] 2.1 Create `src/components/Dashboard/IconRenderer.vue` accepting `name`, `alt`, and `size` props (already in place from PR #58 — extended here with the `alt` prop required by REQ-ICON-007)
- [x] 2.2 Branch internally: `<img :src="name" :alt="alt">` when `isCustomIconUrl(name)`, else `<component :is="getIconComponent(name)" :size="size">`
- [x] 2.3 Default `alt` to a non-empty string (consumer-supplied label, falling back to `'icon'`)
- [x] 2.4 Vitest: rendering branches by input type (built-in name → svg, URL → img, null → default svg)
- [x] 2.5 Vitest: `alt` prop is propagated to the rendered `<img>` for URL inputs

## 3. IconPicker component

- [x] 3.1 Create `src/components/Dashboard/IconPicker.vue` with both a `<select>` of registry names AND a file-upload input visible at the same time
- [x] 3.2 On select change: emit/update `v-model` with the chosen option string
- [x] 3.3 On file select: POST the file to the `resource-uploads` endpoint (via `uploadDataUrl` from the new `src/services/resourceService.js` JS wrapper), then update `v-model` with the returned URL string
- [x] 3.4 Render a 24×24 live preview of the current value via `IconRenderer`
- [x] 3.5 Surface loading and error states for the upload (button label flips to `Uploading…` during POST, inline `<p role="alert">` shown on failure with the wrapper's display message)
- [x] 3.6 On upload error: leave the previous `v-model` value unchanged (do not clobber)

## 4. Refactor existing call sites

- [x] 4.1 Replace ad-hoc icon-or-image branches in `DashboardSwitcher` with `<IconRenderer>` — INSPECTED, no change needed: `DashboardSwitcher` is a label-only `NcSelect` with no icon column today; once `dashboard-icons` (commit 8d3dcde, PR #58) ships the `Dashboard.icon` field on `main`, the switcher integration follows from that proposal, not this one.
- [ ] 4.2 Replace branches in the admin dashboard list / CRUD UI with `<IconRenderer>` and use `<IconPicker>` in the create/edit forms — DEFERRED: depends on the parallel `dashboard-icons` proposal landing the `Dashboard.icon` column AND on `multi-scope-dashboards` re-introducing the dashboard CRUD admin UI; the existing `AdminSettings` only manages templates, not per-dashboard icons. `IconPicker` is exported and ready for that integration.
- [ ] 4.3 Replace branches in the link-button widget icon and the tile editor with `<IconRenderer>` and `<IconPicker>` — DEFERRED (link-button widget): owned by the `link-button-widget` proposal, not yet implemented. `TileEditor.vue` uses a separate `mdi*` SVG-path icon system (not the registry/URL convention) — out of scope for this proposal.
- [x] 4.4 Grep test: no remaining `v-if="iconUrl"` / inline `isCustomIconUrl` branches outside `IconRenderer.vue` and `IconPicker.vue` — VERIFIED via `grep -rn "isCustomIconUrl" src --include="*.vue"`; the only `widget.iconUrl` references are in `WidgetPicker(Modal)`/`WidgetWrapper`/`WidgetRenderer` which read the WIDGET CATALOG metadata field, not the dashboards.icon convention this proposal owns.

## 5. Documentation

- [ ] 5.1 Update the `icon` field docblock on `lib/Db/Dashboard.php` to state that the column may hold either a registry name, a `/apps/mydash/resource/...` URL, or NULL — DEFERRED: the `Dashboard::$icon` field itself is added by the parallel `dashboard-icons` change (commit 8d3dcde, not yet on the parent branch). This task will follow the docblock template from REQ-ICON-009 once that change is archived; the spec contract is locked here so the convention is unambiguous.
- [x] 5.2 Update the `tileIcon` field docblock on `lib/Db/WidgetPlacement.php` with the same convention

## 6. End-to-end tests

- [ ] 6.1 Playwright: open dashboard editor, switch from a built-in icon to an uploaded one, verify the preview swaps from `<svg>` to `<img>` and the value persists after save — DEFERRED: requires the `dashboard-icons` `Dashboard.icon` column AND the `resource-uploads` endpoint to be wired into the dev environment; both are upstream prerequisites. Vitest covers the component contract end-to-end.
- [ ] 6.2 Playwright: switch back from an uploaded icon to a built-in one, verify the preview swaps back and the value persists — DEFERRED (same prerequisite as 6.1)
- [ ] 6.3 Playwright: render a workspace where multiple dashboards mix built-in and uploaded icons, confirm all render correctly with no console errors — DEFERRED (same prerequisite as 6.1)

## 7. Quality

- [x] 7.1 ESLint clean on all changed `.vue` and `.js` files (only pre-existing `widgetBridge.js` JSDoc warnings remain; not introduced here)
- [x] 7.2 `composer check:strict` clean for the touched PHP entity docblock changes (PHPCS, PHPMD, Psalm, PHPStan) — `ALL CHECKS PASSED`; the 16 PHPUnit errors in `DashboardShareServiceFollowupsTest` are pre-existing (`Doctrine\DBAL\ParameterType` not found) and documented in commit `de061cd`.
