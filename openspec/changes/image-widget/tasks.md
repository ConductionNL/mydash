# Tasks — image-widget

## Tasks

- [ ] Task 1: Create `src/components/Widgets/Renderers/ImageWidget.vue` with props `content` + `placement`, persisted shape `{url, alt, link, fit}`, and a Vue prop validator on `fit` restricting to `['cover','contain','fill','none']` with fallback to `'cover'` on unknown input
- [ ] Task 2: Render `<img :src="url" :alt="alt">` at `width:100%`/`height:100%` with inline `object-fit` bound to `fit` (REQ-IMG-001); cell wrapper has `overflow:hidden` so over-fit images don't bleed
- [ ] Task 3: Empty-URL placeholder — 48px CameraIcon + `t('No image')`, centred, in `var(--color-text-maxcontrast)` (REQ-IMG-002)
- [ ] Task 4: `@error="onImageError"` on `<img>` swaps to placeholder + `t('Image failed to load')` and ensures no exception bubbles to the GridStack grid (REQ-IMG-004)
- [ ] Task 5: Bind `cursor:pointer` on the cell wrapper only when `link` is non-empty (REQ-IMG-003); click handler calls `window.open(link, '_blank', 'noopener,noreferrer')` when non-empty, no-op otherwise
- [ ] Task 6: Build `src/components/Widgets/Forms/ImageForm.vue` — file input, URL input, alt input, link input, fit `<select>` (4 options, default `cover` for new placements); live preview `<img :src="url">` thumbnail below the URL input whenever `url` is non-empty
- [ ] Task 7: Upload pipeline — file → `FileReader.readAsDataURL` → `POST /api/resources` → on success set `form.url` from response `{url}`; on failure surface inline `t('Failed to upload image')` under the upload input and leave `form.url` unchanged (REQ-IMG-005)
- [ ] Task 8: Form `validate()` returns `[t('Image URL is required')]` when `form.url.trim() === ''`
- [ ] Task 9: Register `image` in `src/constants/widgetRegistry.js` with defaults `{url:'', alt:'', link:'', fit:'cover'}`, mapped to the new renderer + form and a label `t('Image')`
- [ ] Task 10: Vitest renderer — `object-fit` inline style equals `fit`; prop validator falls back from unknown values (e.g. `'stretch'`); cell `cursor` toggles correctly with `link`; `<img>` error event swaps in the placeholder
- [ ] Task 11: Vitest form — `validate()` reports the required-URL message on empty/whitespace; upload-error path surfaces the inline message and leaves `form.url` untouched
- [ ] Task 12: Playwright — upload an image → preview appears → save → reload → image still visible on the cell; external URL with click-through opens in a new tab with `noopener,noreferrer`; empty-URL cell shows the camera placeholder and does NOT respond to clicks
- [ ] Task 13: Quality + i18n — ESLint + Stylelint clean; SPDX-in-docblock on every new file; `nl_NL` + `en_US` translations for `Image`, `No image`, `Image failed to load`, `Upload Image`, `Or enter Image URL`, `Alt Text`, `Link (optional)`, `Fit`, `Cover`, `Contain`, `Fill`, `None`, `Failed to upload image`, `Image URL is required`; confirm no backend route beyond `resource-uploads`

## Verification

`openspec validate` exits clean. Renderer + form round-trip through edit mode with persistence, and broken image URLs degrade to the placeholder without GridStack errors.

## Tests (company-wide ADR-009)

Vitest per Tasks 10–11; Playwright per Task 12. No new backend surface.

## Documentation (company-wide ADR-010)

Changelog entry covering the new widget type; user-guide screenshot of the image-fit options.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 13.
