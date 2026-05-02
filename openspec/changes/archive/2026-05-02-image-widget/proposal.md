# Image widget

## Why

MyDash today has no first-class way to put a single image on a dashboard. Users currently jam logos, screenshots, branding, and decorative imagery into the markdown widget via `<img>` tags or rely on the iframe widget pointing at an external image URL — both workarounds. Neither path supports proper `object-fit` control, broken-image fallback, click-through to a target URL, or the upload-a-file UX users expect from a dashboard product. Competitor dashboards (Grafana, Microsoft Power BI tiles, and similar) all ship a dedicated image widget. We need parity, with the small UX upgrade that the cell only looks clickable when there is actually a link to click.

## What Changes

- Add a new widget type `image` rendered via `src/components/Widgets/Renderers/ImageWidget.vue`.
- Persisted shape: `{type: 'image', content: {url, alt, link, fit}}`. `fit` defaults to `'cover'` and is restricted to `'cover' | 'contain' | 'fill' | 'none'` by a Vue prop validator (with fallback to `'cover'` on unknown input).
- The form (`src/components/Widgets/Forms/ImageForm.vue`) offers two ways to set `url`: file upload (handed to the resource-uploads endpoint) OR direct URL string. It also exposes `alt`, `link`, `fit`, and a live preview thumbnail.
- Click-through: when `link` is non-empty, clicking the cell opens the link via `window.open(link, '_blank', 'noopener,noreferrer')`. When `link` is empty there is no navigation AND `cursor` stays default (deliberate UX choice — no misleading clickable affordance when there is nothing to click).
- Empty-URL placeholder: 48 px camera icon + `t('No image')` centred, in `var(--color-text-maxcontrast)`.
- Broken-image fallback: `<img @error>` swaps in the same placeholder plus the annotation `t('Image failed to load')`. Must not crash the surrounding GridStack grid.
- Register the new type in `src/constants/widgetRegistry.js` with defaults `{url:'', alt:'', link:'', fit:'cover'}`.

## Capabilities

### New Capabilities

- `image-widget`: adds REQ-IMG-001 (render with object-fit), REQ-IMG-002 (empty-URL placeholder), REQ-IMG-003 (click-through link behaviour), REQ-IMG-004 (broken-image fallback), REQ-IMG-005 (add/edit form).

### Modified Capabilities

(none — this is a self-contained renderer + form + registry entry; existing widget capabilities are untouched.)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/ImageWidget.vue` — new renderer (props: `content`, `placement`)
- `src/components/Widgets/Forms/ImageForm.vue` — new form sub-component for `AddWidgetModal`
- `src/constants/widgetRegistry.js` — register `type: 'image'` with defaults
- Translation entries: `Image`, `No image`, `Image failed to load`, `Upload Image`, `Or enter Image URL`, `Alt Text`, `Link (optional)`, `Fit`, `Cover`, `Contain`, `Fill`, `None`, `Failed to upload image`, `Image URL is required` (both `nl` and `en` per the i18n requirement)

**Affected APIs:**

- No new MyDash backend routes. The form POSTs to the resource-uploads endpoint owned by the `resource-uploads` capability — see Dependencies below.

**Dependencies:**

- `resource-uploads` capability — owns the `/api/resources` upload endpoint that the form's file-input branch calls. The image-widget change MUST land after `resource-uploads` is in place, OR ship behind a feature flag that hides the file-input control until the endpoint exists (URL input still works either way).
- No new composer or npm dependencies. The camera icon comes from the existing `@mdi/svg` icon set already bundled.

**Migration:**

- No database migration. Widget content is stored in the existing `oc_mydash_widget_placements.content` JSON blob. Old placements without `type: 'image'` are unaffected.

**Out of scope:**

- Lightbox / image-zoom on click (a future change can layer this onto `link === ''` cells).
- Gallery mode (multiple images cycled in one cell).
- Persisting the uploaded resource alongside the placement — only the URL is persisted. Resource lifecycle (orphan GC, quota accounting) is owned by `resource-uploads` and a future GC change.
