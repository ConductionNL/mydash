---
status: implemented
---

# Image Widget Specification

## Purpose

The image widget is a built-in MyDash widget type that lets dashboard authors place a single image — logo, screenshot, branding, or decorative imagery — onto a dashboard cell with proper `object-fit` control, broken-image fallback, optional click-through, and a first-class file-upload UX. It replaces the prior workarounds where users jammed `<img>` tags into the markdown widget or pointed an iframe widget at an image URL.

The capability is one widget type, one renderer, one sub-form, one registry entry, and a thin client wrapper around the resource-uploads endpoint owned by the `resource-uploads` capability — small enough to be evolved or deprecated independently of the broader widget-rendering machinery.

## Data Model

Image placements use the existing `oc_mydash_widget_placements.content` JSON column with the discriminated shape `{type: 'image', content: {...}}`. No schema migration is required.

The `content` object carries four fields:

- **url** (string, required) — the image URL; either a local resource path returned by the resource-uploads endpoint (e.g. `/apps/mydash/resource/abc.png`) or an external `http(s)` URL
- **alt** (string, default `''`) — accessible alt text passed to `<img alt>`
- **link** (string, default `''`) — optional click-through URL; when non-empty the cell becomes clickable
- **fit** (string, default `'cover'`) — one of `cover`, `contain`, `fill`, `none`; drives the CSS `object-fit` on the rendered `<img>`

Resource lifecycle (orphan GC, quota accounting) is owned by the `resource-uploads` capability and a future GC change. Only the URL is persisted alongside the placement.

## Requirements

### Requirement: REQ-IMG-001 Render image with object-fit

The renderer MUST output an `<img :src="url" :alt="alt">` whose CSS `object-fit` MUST equal the `fit` field of the persisted content. The `<img>` MUST fill the cell with `width: 100%; height: 100%`. The cell wrapper MUST set `overflow: hidden` so over-fit images do not bleed out of the grid cell. The persisted content shape is `{type: 'image', content: {url, alt, link, fit}}` where `fit` is one of `'cover' | 'contain' | 'fill' | 'none'` (default `'cover'`). The Vue prop validator on `fit` MUST restrict the value to that enum and fall back to `'cover'` when an unknown value is passed.

#### Scenario: Cover fit fills the cell

- GIVEN content `{url: '/apps/mydash/resource/x.png', fit: 'cover'}`
- WHEN the widget renders
- THEN the `<img>` MUST have inline style `object-fit: cover`
- AND its width and height MUST be `100%`
- AND the cell wrapper MUST have `overflow: hidden`

#### Scenario: Contain fit preserves aspect ratio

- GIVEN content `{url: 'https://example.com/wide.png', fit: 'contain'}`
- WHEN the widget renders
- THEN the `<img>` MUST have `object-fit: contain`
- AND letterboxing MUST be visible if the image's aspect ratio differs from the cell's

#### Scenario: Invalid fit value falls back to cover

- GIVEN a developer mounts the widget with `fit: 'stretch'`
- WHEN the Vue prop validator runs
- THEN it MUST log a Vue prop validator warning
- AND the renderer MUST apply `object-fit: cover`

#### Scenario: Default fit is cover

- GIVEN content `{url: '/apps/mydash/resource/x.png'}` with no `fit` field
- WHEN the widget renders
- THEN the `<img>` MUST have `object-fit: cover`

### Requirement: REQ-IMG-002 Empty-URL placeholder

When `url` is empty or null, the renderer MUST display a placeholder consisting of a 48 px camera icon plus the translated string `t('No image')`, centred, in `var(--color-text-maxcontrast)`. The placeholder MUST occupy the full cell. The DOM MUST NOT contain an `<img>` element while the placeholder is active.

#### Scenario: Empty url shows placeholder

- GIVEN content `{url: ''}`
- WHEN the widget renders
- THEN the DOM MUST NOT contain an `<img>` element
- AND it MUST contain a 48 px camera SVG
- AND it MUST contain the text `'No image'`

#### Scenario: Null url shows placeholder

- GIVEN content `{url: null}`
- WHEN the widget renders
- THEN the DOM MUST NOT contain an `<img>` element
- AND the placeholder MUST be visible

#### Scenario: Placeholder uses maxcontrast token

- GIVEN the empty-URL placeholder is rendered
- WHEN inspected
- THEN its colour MUST be `var(--color-text-maxcontrast)`
- AND it MUST be centred horizontally and vertically inside the cell

### Requirement: REQ-IMG-003 Click-through link behaviour

When the persisted `link` field is non-empty, a click on the cell MUST open `link` in a new tab via `window.open(link, '_blank', 'noopener,noreferrer')`. The cell wrapper MUST set `cursor: pointer` only when `link` is non-empty — when there is no link the cursor MUST remain default so users are not given a misleading clickable affordance.

#### Scenario: Click opens link in new tab

- GIVEN content `{url: '/img/x.png', link: 'https://example.com'}`
- WHEN the user clicks the cell
- THEN the system MUST call `window.open('https://example.com', '_blank', 'noopener,noreferrer')`

#### Scenario: No link, no click, no pointer

- GIVEN content `{url: '/img/x.png', link: ''}`
- WHEN the user clicks the cell
- THEN no navigation MUST occur
- AND the cell wrapper's `cursor` MUST be the default (not `pointer`)

#### Scenario: Pointer cursor only with link

- GIVEN content `{url: '/img/x.png', link: 'https://example.com'}`
- WHEN the cell renders
- THEN the cell wrapper's CSS `cursor` MUST equal `pointer`

### Requirement: REQ-IMG-004 Broken-image fallback

The `<img>` MUST handle the DOM `error` event by replacing itself with the empty-URL placeholder (as defined in REQ-IMG-002) plus the translated annotation `t('Image failed to load')`. The fallback MUST NOT crash, unmount, or otherwise disturb the surrounding GridStack grid.

#### Scenario: Broken external URL falls back

- GIVEN content `{url: 'https://example.com/missing.png'}` and the URL returns 404
- WHEN the browser fires the `<img>` `error` event
- THEN the renderer MUST swap to the placeholder
- AND the placeholder MUST include the text `'Image failed to load'`
- AND the surrounding GridStack grid MUST remain operational (other widgets still render and respond to events)

#### Scenario: Broken URL does not crash grid

- GIVEN any cell renders an image with a URL that triggers the `error` event
- WHEN the error fires
- THEN no uncaught exception MUST be raised
- AND the dashboard MUST remain interactive (drag, resize, edit on other cells still works)

### Requirement: REQ-IMG-005 Add/edit form

The image sub-form for `AddWidgetModal` MUST expose the following controls: a file `<input type="file" accept="image/*">` (Upload), a text `<input>` for `url` (also written by the upload pipeline), a text `<input>` for `alt`, a text `<input>` for `link`, and a `<select>` for `fit` with options `cover`, `contain`, `fill`, `none`. Only `url` is required for save. The form MUST display a live preview thumbnail (`<img :src="url">`) below the URL input whenever `url` is non-empty. The upload pipeline MUST be: file → `FileReader.readAsDataURL` → POST to the resource-uploads endpoint (`/api/resources`) → on success, set `form.url` from the response `{url}`. The form's `validate()` MUST return `[t('Image URL is required')]` when `url.trim() === ''`. Upload errors MUST be surfaced inline under the upload input via the translated message `t('Failed to upload image')`, and `form.url` MUST remain unchanged on upload failure.

#### Scenario: Upload populates URL and preview

- GIVEN the user selects an image file in the file input
- WHEN the upload POST to `/api/resources` succeeds with response `{url: '/apps/mydash/resource/abc.png'}`
- THEN `form.url` MUST become `/apps/mydash/resource/abc.png`
- AND the preview thumbnail `<img>` MUST become visible with that `src`

#### Scenario: Direct URL string is also accepted

- GIVEN the user types `https://example.com/x.png` directly into the URL input
- WHEN the input blurs
- THEN `form.url` MUST equal `'https://example.com/x.png'`
- AND the preview thumbnail MUST attempt to load that URL

#### Scenario: Upload error surfaces to user

- GIVEN the upload POST returns HTTP 400 (e.g. file too large)
- WHEN the form catches the error
- THEN it MUST display the inline error message `t('Failed to upload image')` under the upload input
- AND `form.url` MUST remain unchanged

#### Scenario: Empty URL fails validation

- GIVEN the user submits the form with `form.url = ''`
- WHEN `validate()` runs
- THEN it MUST return `[t('Image URL is required')]`
- AND the modal MUST NOT close

#### Scenario: All allowed fit values selectable

- GIVEN the form is open
- WHEN the user inspects the `fit` `<select>`
- THEN it MUST contain exactly the four options `cover`, `contain`, `fill`, `none`
- AND the default selected value MUST be `cover` for a new placement
