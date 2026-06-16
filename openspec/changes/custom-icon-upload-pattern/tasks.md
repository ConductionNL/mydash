# Tasks — custom-icon-upload-pattern

## Tasks

- [ ] Task 1: Add `isCustomIconUrl(name)` to `src/constants/dashboardIcons.js` returning `true` only for non-null strings beginning with `/` or `http`; update `getIconComponent(name)` to return `null` for URL inputs (must NOT fall back to `DEFAULT_ICON` for URLs)
- [ ] Task 2: Vitest discriminator coverage — URL prefixes (`/apps/...`, `http://`, `https://`), registry names (`Star`, `ViewDashboard`), falsy inputs (`null`, `undefined`, `''`); `getIconComponent` returns `null` for URL input AND `DEFAULT_ICON` for unknown registry name (REQ-ICON-001 still holds)
- [ ] Task 3: Build `src/components/Dashboard/IconRenderer.vue` accepting `name`, `alt`, `size` — branches `<img :src="name" :alt="alt">` when `isCustomIconUrl(name)`, else `<component :is="getIconComponent(name)" :size="size">`; default `alt` falls back to consumer-supplied label (dashboard/widget name)
- [ ] Task 4: Vitest renderer coverage — rendering branches by input type (built-in name → svg, URL → img, null → default svg); `alt` prop propagated to the rendered `<img>` for URL inputs
- [ ] Task 5: Build `src/components/Dashboard/IconPicker.vue` with both a `<select>` of registry names AND a file-upload input visible at once; on select-change update `v-model` with the option string; on file-select POST to the `resource-uploads` endpoint then update `v-model` with the returned URL
- [ ] Task 6: Picker UX — render a 24×24 live preview via `IconRenderer`; surface loading + error states for upload (spinner during POST, visible error on non-2xx); on upload error leave the previous `v-model` value unchanged (no clobber)
- [ ] Task 7: Refactor call sites — `DashboardSwitcher`, admin dashboard list/CRUD, link-button widget icon, tile editor — replace ad-hoc icon-or-image branches with `<IconRenderer>` and use `<IconPicker>` in the create/edit forms
- [ ] Task 8: Grep verification — no remaining `v-if="iconUrl"` / inline `isCustomIconUrl` branches outside `IconRenderer.vue` and `IconPicker.vue`
- [ ] Task 9: Update the `icon` field docblock on `lib/Db/Dashboard.php` AND the `tileIcon` field docblock on `lib/Db/WidgetPlacement.php` to state the column may hold a registry name, a `/apps/mydash/resource/...` URL, or NULL
- [ ] Task 10: Playwright — switch from built-in to uploaded icon, preview swaps `<svg>` → `<img>`, value persists after save; reverse swap also persists; workspace mixing both kinds of icons across dashboards renders cleanly with no console errors
- [ ] Task 11: Quality gates — ESLint clean on changed `.vue`/`.js`; `composer check:strict` clean for the touched PHP docblock changes
- [ ] Task 12: Stylelint clean on any new component `<style>` blocks; `npm run build` produces no new warnings

## Verification

`openspec validate` exits clean. No legacy inline branches survive the refactor (per Task 8); editor flows round-trip both icon kinds.

## Tests (company-wide ADR-009)

Vitest per Tasks 2 + 4; Playwright per Task 10. No new backend surface.

## Documentation (company-wide ADR-010)

PHP docblock updates per Task 9; changelog entry covering the unified renderer/picker pattern.

## i18n (company-wide ADR-005)

No user-facing strings added — picker surfaces existing labels via consumer-supplied props.
