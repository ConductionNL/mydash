# Tasks — image-widget

## 1. Renderer

- [x] 1.1 Create `src/components/Widgets/Renderers/ImageWidget.vue` with props `content` and `placement` and the persisted shape `{url, alt, link, fit}`
- [x] 1.2 Add a Vue prop validator on `fit` restricting values to `['cover','contain','fill','none']` with fallback to `'cover'` on unknown input (warns via `console.warn` per Vue's standard validator behaviour)
- [x] 1.3 Render `<img :src="url" :alt="alt">` with `width: 100%`, `height: 100%`, and inline `object-fit` bound to `fit` (REQ-IMG-001)
- [x] 1.4 Set `overflow: hidden` on the cell wrapper so over-fit images do not bleed out
- [x] 1.5 Implement empty-URL placeholder: 48 px CameraIcon + `t('No image')`, centred, in `var(--color-text-maxcontrast)` (REQ-IMG-002)
- [x] 1.6 Add `@error="onImageError"` on `<img>` that swaps to placeholder + `t('Image failed to load')` and ensures no exception bubbles to the GridStack grid (REQ-IMG-004)
- [x] 1.7 Bind `cursor: pointer` on the cell wrapper only when `link` is non-empty (REQ-IMG-003)
- [x] 1.8 Wire click handler: `window.open(link, '_blank', 'noopener,noreferrer')` when `link` is non-empty, no-op otherwise (REQ-IMG-003)

## 2. Form

- [x] 2.1 Create `src/components/Widgets/Forms/ImageForm.vue` with file input, URL input, alt input, link input, and fit select
- [x] 2.2 Implement upload pipeline: file → `FileReader.readAsDataURL` → POST `/api/resources` (resource-uploads) → on success set `form.url` from response `{url}` (REQ-IMG-005)
- [x] 2.3 Display live preview `<img :src="url">` thumbnail below the URL input whenever `url` is non-empty
- [x] 2.4 Implement `validate()` returning `[t('Image URL is required')]` when `form.url.trim() === ''`
- [x] 2.5 Display inline error `t('Failed to upload image')` under the upload input when the resource-uploads POST fails; leave `form.url` unchanged
- [x] 2.6 Wire fit `<select>` with the four options `cover, contain, fill, none` and default `cover` for new placements

## 3. Registry

- [x] 3.1 Add `image` entry to `src/constants/widgetRegistry.js` with defaults `{url:'', alt:'', link:'', fit:'cover'}`
- [x] 3.2 Map the entry to the new renderer + form components and a label string `t('Image')`

## 4. Tests

- [x] 4.1 Vitest: `object-fit` inline style equals the `fit` prop value (cover, contain, fill, none)
- [x] 4.2 Vitest: prop validator rejects unknown fit values (e.g. `'stretch'`) and falls back to `'cover'`
- [x] 4.3 Vitest: cell wrapper `cursor` toggles `pointer` ↔ default based on `link` non-empty / empty
- [x] 4.4 Vitest: `<img>` `error` event swaps in the placeholder with `'Image failed to load'`
- [x] 4.5 Vitest: form `validate()` returns `[t('Image URL is required')]` when URL is empty/whitespace
- [x] 4.6 Vitest: form upload-error path surfaces the inline error and leaves `form.url` untouched
- [x] 4.7 Playwright: upload an image → preview appears → save → reload page → image still visible on the cell
- [x] 4.8 Playwright: external URL with click-through opens in a new tab with `noopener,noreferrer`
- [x] 4.9 Playwright: empty-URL cell shows the camera placeholder and does not respond to clicks

## 5. Quality

- [x] 5.1 ESLint + Stylelint clean on all new Vue/JS files
- [x] 5.2 Add translation entries (both `nl` and `en` per the i18n requirement): `Image`, `No image`, `Image failed to load`, `Upload Image`, `Or enter Image URL`, `Alt Text`, `Link (optional)`, `Fit`, `Cover`, `Contain`, `Fill`, `None`, `Failed to upload image`, `Image URL is required`
- [x] 5.3 SPDX headers in the file docblock on every new file (per the SPDX-in-docblock convention)
- [x] 5.4 Run all relevant `hydra-gates` locally before opening the PR (no PHP touched, but JS gates and forbidden-patterns still apply)
- [x] 5.5 Confirm the change does NOT depend on any backend route beyond what `resource-uploads` already ships
