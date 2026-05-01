# Tasks — custom-icon-upload-pattern

## 1. Discriminator module

- [ ] 1.1 Add `isCustomIconUrl(name)` export to `src/constants/dashboardIcons.js` returning `true` only for non-null strings beginning with `'/'` or `'http'`
- [ ] 1.2 Update `getIconComponent(name)` to return `null` when `isCustomIconUrl(name)` is true (must NOT fall back to `DEFAULT_ICON` for URLs)
- [ ] 1.3 Add Vitest covering the truth table: URL prefixes (`/apps/...`, `http://`, `https://`), registry names (`Star`, `ViewDashboard`), and falsy inputs (`null`, `undefined`, `''`)
- [ ] 1.4 Add Vitest asserting `getIconComponent` returns `null` for a URL input AND returns `DEFAULT_ICON` for an unknown registry name (REQ-ICON-001 still holds)

## 2. IconRenderer component

- [ ] 2.1 Create `src/components/Dashboard/IconRenderer.vue` accepting `name`, `alt`, and `size` props
- [ ] 2.2 Branch internally: `<img :src="name" :alt="alt">` when `isCustomIconUrl(name)`, else `<component :is="getIconComponent(name)" :size="size">`
- [ ] 2.3 Default `alt` to a non-empty string (consumer-supplied label, falling back to dashboard/widget name)
- [ ] 2.4 Vitest: rendering branches by input type (built-in name → svg, URL → img, null → default svg)
- [ ] 2.5 Vitest: `alt` prop is propagated to the rendered `<img>` for URL inputs

## 3. IconPicker component

- [ ] 3.1 Create `src/components/Dashboard/IconPicker.vue` with both a `<select>` of registry names AND a file-upload input visible at the same time
- [ ] 3.2 On select change: emit/update `v-model` with the chosen option string
- [ ] 3.3 On file select: POST the file to the `resource-uploads` endpoint, then update `v-model` with the returned URL string
- [ ] 3.4 Render a 24×24 live preview of the current value via `IconRenderer`
- [ ] 3.5 Surface loading and error states for the upload (spinner during POST, visible error when the request fails or the response is non-2xx)
- [ ] 3.6 On upload error: leave the previous `v-model` value unchanged (do not clobber)

## 4. Refactor existing call sites

- [ ] 4.1 Replace ad-hoc icon-or-image branches in `DashboardSwitcher` with `<IconRenderer>`
- [ ] 4.2 Replace branches in the admin dashboard list / CRUD UI with `<IconRenderer>` and use `<IconPicker>` in the create/edit forms
- [ ] 4.3 Replace branches in the link-button widget icon and the tile editor with `<IconRenderer>` and `<IconPicker>`
- [ ] 4.4 Grep test: no remaining `v-if="iconUrl"` / inline `isCustomIconUrl` branches outside `IconRenderer.vue` and `IconPicker.vue`

## 5. Documentation

- [ ] 5.1 Update the `icon` field docblock on `lib/Db/Dashboard.php` to state that the column may hold either a registry name, a `/apps/mydash/resource/...` URL, or NULL
- [ ] 5.2 Update the `tileIcon` field docblock on `lib/Db/WidgetPlacement.php` with the same convention

## 6. End-to-end tests

- [ ] 6.1 Playwright: open dashboard editor, switch from a built-in icon to an uploaded one, verify the preview swaps from `<svg>` to `<img>` and the value persists after save
- [ ] 6.2 Playwright: switch back from an uploaded icon to a built-in one, verify the preview swaps back and the value persists
- [ ] 6.3 Playwright: render a workspace where multiple dashboards mix built-in and uploaded icons, confirm all render correctly with no console errors

## 7. Quality

- [ ] 7.1 ESLint clean on all changed `.vue` and `.js` files
- [ ] 7.2 `composer check:strict` clean for the touched PHP entity docblock changes (PHPCS, PHPMD, Psalm, PHPStan)
