# Tasks — image-widget

## Tasks

- [ ] Task 1: Create `src/components/Widgets/Renderers/ImageWidget.vue` with props `content` + `placement`, persisted shape `{url, alt, link, fit, sourceType, fileId, filePath}`, and a Vue prop validator on `fit` restricting to `['cover','contain','fill','none']` with fallback to `'cover'` on unknown input (REQ-IMG-001)
- [ ] Task 2: Render `<img :src="url" :alt="alt">` at `width:100%`/`height:100%` with inline `object-fit` bound to `fit` (REQ-IMG-001); cell wrapper has `overflow:hidden` so over-fit images don't bleed
- [ ] Task 3: Empty-URL placeholder — 48px CameraIcon + `t('No image')`, centred, in `var(--color-text-maxcontrast)` (REQ-IMG-002)
- [ ] Task 4: `@error="onImageError"` on `<img>` swaps to placeholder + `t('Image failed to load')` and ensures no exception bubbles to the GridStack grid (REQ-IMG-004, REQ-IMP-005)
- [ ] Task 5: Bind `cursor:pointer` on the cell wrapper only when `link` is non-empty (REQ-IMG-003); click handler calls `window.open(link, '_blank', 'noopener,noreferrer')` when non-empty, no-op otherwise
- [ ] Task 6: For SVG files (detected by sourceType='files' + file MIME type detection), route to the `svg-sanitisation` capability instead of rendering raw `<img>` (REQ-IMP-008); implement handoff point for svg-sanitisation capability to take over
- [ ] Task 7: Build `src/components/Widgets/Forms/ImageForm.vue` — file input, URL input, alt input, link input, fit `<select>` (4 options, default `cover` for new placements); sourceType radio group with three branches ("URL/Link", "Upload", "Pick from Files") controlling form visibility; live preview `<img :src="url">` thumbnail below the URL input whenever `url` is non-empty (REQ-IMG-005, REQ-IMP-007)
- [ ] Task 8: Upload pipeline for 'upload' branch — file → `FileReader.readAsDataURL` → `POST /api/resources` → on success set `form.url` from response `{url}`; on failure surface inline `t('Failed to upload image')` under the upload input and leave `form.url` unchanged (REQ-IMG-005)
- [ ] Task 9: File picker invocation for 'files' branch — invoke Nextcloud's file picker from `@nextcloud/dialogs` with MIME type filtering to image types only (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`); single-file selection only; on file selection populate `form.fileId`, `form.filePath`, and auto-generate preview URL via `IURLGenerator::linkToRoute()` (REQ-IMP-002, REQ-IMP-003, REQ-IMP-004)
- [ ] Task 10: Form state management — component state retains values for all three sourceType branches; switching branches does NOT erase values for other branches; pre-fill all fields from `editingWidget.content` on mount (REQ-IMP-007)
- [ ] Task 11: Form `validate()` returns `[t('Image URL is required')]` when `form.url.trim() === ''` and sourceType is 'url' or 'upload'; returns `[]` otherwise; validation skips URL field for 'files' sourceType (user picks file instead)
- [ ] Task 12: Register `image` in `src/constants/widgetRegistry.js` with defaults `{url:'', alt:'', link:'', fit:'cover', sourceType:'url', fileId:null, filePath:''}`, mapped to the new renderer + form and a label `t('Image')`
- [ ] Task 13: Verify that AddWidgetModal's type picker surfaces the image widget and that the sub-form's three sourceType branches are mutually exclusive and properly switch visibility
- [ ] Task 14: Vitest renderer — `object-fit` inline style equals `fit`; prop validator falls back from unknown values (e.g. `'stretch'`); cell `cursor` toggles correctly with `link`; `<img>` error event swaps in the placeholder; empty/null url shows camera placeholder; SVG files route to svg-sanitisation (mock the capability's endpoint)
- [ ] Task 15: Vitest form — `validate()` reports the required-URL message on empty/whitespace for url/upload sources; upload-error path surfaces the inline message and leaves `form.url` untouched; file picker integration test (mock Nextcloud dialogs); state retention across sourceType switches; pre-fill all fields from placement on edit
- [ ] Task 16: Playwright — upload an image → preview appears → save → reload → image still visible on the cell; external URL with click-through opens in a new tab with `noopener,noreferrer`; empty-URL cell shows the camera placeholder and does NOT respond to clicks; file picker filters to images; deleted file shows fallback placeholder; permission-denied file shows fallback
- [ ] Task 17: Preview URL generation (server-side) — implement helper method that calls `IURLGenerator::linkToRoute('core.preview.getPreview', ['fileId' => $fileId, ...])` and returns null on missing file or permission error (REQ-IMP-004); integrate into placement serialization so Files-mode widgets get a computed URL
- [ ] Task 18: sourceType enum validation — add validation on placement save to reject invalid sourceType values (only 'url', 'upload', 'files' allowed); add migration/backward-compatibility so existing placements default to 'url' (REQ-IMP-001)
- [ ] Task 19: fileId + filePath persistence — ensure both fields are persisted in the placement record and survive edits to widget title, grid position, etc. (REQ-IMP-003); add filePath max-length validation (2048 bytes)
- [ ] Task 20: Quality + i18n — ESLint + Stylelint clean; SPDX headers on every new file; `npm run build` succeeds with no new warnings; `nl_NL` + `en_US` translations for all form labels and messages (Image, No image, Image failed to load, Upload Image, Or enter Image URL, Alt Text, Link (optional), Fit, Cover, Contain, Fill, None, Failed to upload image, Image URL is required, URL/Link, Upload, Pick from Files, Image source, Image path)

## Verification

`openspec validate` exits clean. Renderer + form round-trip through edit mode with persistence; all three sourceType branches work independently; broken image URLs degrade to the placeholder without GridStack errors; Files-mode widgets respect file ACLs and show fallback when file is deleted or inaccessible.

## Tests (company-wide ADR-009)

Vitest per Tasks 14–15; Playwright per Task 16. Preview URL generation tested via server-side unit test (Task 17). No new backend routes beyond `resource-uploads` (which owns `/api/resources`).

## Documentation (company-wide ADR-010)

Changelog entry covering the new widget type and the three source modes; user-guide screenshot showing the sourceType radio group and the three form branches; screenshot showing object-fit options (cover, contain, fill, none).

## i18n (company-wide ADR-007)

`nl_NL` + `en_US` per Task 20. All form labels, buttons, error messages, and placeholders translated.
