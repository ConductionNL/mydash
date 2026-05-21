# Design — text-display-widget

## Context

MyDash widget placements today are bound to widgets discovered via Nextcloud's `IManager::getWidgets()` (see `widgets` capability) — every placement points at a registered Nextcloud dashboard widget by id and renders whatever that widget callback emits. There is no first-class concept of an "annotation" widget: a static, user-authored block of text the user can drop on a dashboard to label a section, leave instructions, list contact details, or call out an important note.

Customers have asked for this repeatedly. Workarounds (using a tile with a long title, or a custom HTML widget shipped from another app) are awkward and don't survive theming. The right primitive is a built-in MyDash widget type whose entire content lives in the placement record's `styleConfig` JSON, with no external widget callback.

This change introduces that primitive as a new widget type `text` and a new capability `text-display-widget`. The capability intentionally supports dual content modes:

- **HTML mode** — for users who author HTML directly and for backward compatibility with widgets created before Markdown support was added.
- **Markdown mode** — the shipped default for new widgets, more accessible for most users who are familiar with `**bold**`, `*italic*`, `[links](url)`, and `# headings`.

Both modes apply the same XSS sanitisation (DOMPurify) before rendering. The capability is intentionally narrow — one widget type, one renderer, one sub-form, one registry entry — so it can be evolved or deprecated independently of the broader widget-rendering machinery.

## Goals / Non-Goals

**Goals:**

- Ship a `text` widget type that renders multi-line user-authored text with either HTML or CommonMark Markdown formatting.
- Support two content modes — `'html'` (for backward compatibility with existing widgets and users who prefer raw HTML) and `'markdown'` (shipped as the default for new widgets, more accessible for most users).
- Sanitise rendered HTML via DOMPurify in both modes so authors can safely use formatting without opening an XSS hole.
- Provide inline style controls (font size, text colour, background colour, alignment) so the widget integrates visually with surrounding theming.
- Default to theme-aware values (`var(--color-main-text)`, transparent background) so the widget looks correct out-of-the-box in light, dark, and admin-themed Nextcloud instances.
- Keep the widget type self-contained — no new database tables, no new backend services, no new API endpoints. The persisted content lives in the existing `oc_mydash_widget_placements.styleConfig` JSON column.
- Preserve backward compatibility — existing widgets with no explicit `contentMode` render as HTML; only new widgets receive the Markdown default.

**Non-Goals:**

- A WYSIWYG / rich-text editor — the textarea accepts raw HTML or raw Markdown; a rich editor is a possible later enhancement but out of scope here.
- Server-side sanitisation — sanitisation is client-side at render time. Backend stores whatever the user submitted (after the existing styleConfig validation). Rationale: the same content might be rendered in other contexts later (export, print) where the sanitisation rules differ; storing raw lets the renderer decide.
- In-place table editing UI — tables are parsed in Markdown mode but see the limitation in REQ-TXMD-002. Full table-editing support is deferred to the separate `text-widget-tables` capability.
- A typed font-size picker (rem/em/px dropdown) — the free-form text input intentionally allows any CSS length, including `1.2em`, `clamp(0.8rem, 2vw, 2rem)`, etc.
- Cross-widget templating, variable interpolation, or live data binding — text is static.
- Per-locale text variants — the user authors one body of text; localisation is the user's responsibility.

## Decisions

### D1: Use `v-html` with DOMPurify rather than a sandboxed iframe

**Decision**: Render via `<div v-html="DOMPurify.sanitize(text)">` directly inside the widget cell.

**Alternatives considered:**

- Render the user content inside a sandboxed `<iframe sandbox>`. Rejected because (a) iframes break theme variable inheritance — the user's text would not pick up `var(--color-main-text)` etc. unless we manually rehydrate every CSS variable, and (b) iframes add layout overhead (separate document, scrollbar, focus trap) that fights GridStack's resize behaviour.
- Render only as escaped plain text (no HTML at all). Rejected because it removes the most-requested affordance — the ability to bold a word or add a link inside an annotation.
- Render via a markdown library. Rejected per Non-Goals — would force a parallel pipeline and a markdown ↔ HTML round-trip whenever the user edits.

**Rationale**: DOMPurify is the de facto sanitiser for client-side HTML in browsers — Mozilla Observatory recommends it, the OpenCatalogi app already uses it for catalog descriptions, and it ships a single ~20KB minified bundle. The trade-off (deliberate use of `v-html`) is acknowledged in the proposal and validated by the renderer test that asserts `<script>` and `on*` are stripped.

### D2: Persist content in the existing `styleConfig` JSON column

**Decision**: The widget's content lives at `oc_mydash_widget_placements.styleConfig` as `{type: 'text', content: {...}}`. No schema migration.

**Alternatives considered:**

- Add a dedicated `oc_mydash_text_widgets` table keyed by placement id. Rejected because it (a) requires a new mapper, service, and JOIN on every placement read, and (b) hardcodes one widget type into the schema — inconsistent with how other potential built-in widget types (e.g. spacer, separator, image) would land later.
- Add a generic `content TEXT NULL` column to `widget_placements`. Rejected because `styleConfig` already exists as the catch-all per-widget-type JSON blob (see `widgets` capability data model). Adding a parallel column would be redundant.

**Rationale**: `styleConfig` is the right place — it is already the per-placement extension point, already JSON-serialisable, already round-trips through the existing CRUD endpoints. Other built-in widget types added later can use the same `{type, content}` envelope to discriminate.

### D3: Free-form `fontSize` text input rather than a typed picker

**Decision**: Render `<input type="text" placeholder="14px">` for fontSize; pass the value through verbatim into the inline style.

**Alternatives considered:**

- Number input + unit dropdown (px/em/rem). Rejected because it cannot express `clamp(...)` or `min(...)` and forces a discrete-unit model that's poorer than CSS.
- Slider 8–48 px. Rejected for the same reason — discrete, px-locked.

**Rationale**: Power-users want CSS expressiveness; novice users see the placeholder `14px` and can type `16px` or `large`. The inline style passes any string the browser will accept. The renderer falls back to `14px` when the field is empty, so a blank value never produces a broken widget.

### D4: Default colours via Nextcloud theme variables

**Decision**: When `color` or `backgroundColor` is empty, use `var(--color-main-text)` and `transparent` respectively. Never hardcode `#000000` or `#ffffff`.

**Alternatives considered:**

- Hardcode black-on-white defaults. Rejected because the widget would look wrong in the dark theme and in admin-themed instances.

**Rationale**: Theme-aware defaults make the widget invisible-by-design when the user adds it without configuring colours — it inherits the surrounding dashboard look. Explicit colour values still win when the user picks them, so customisation works the same.

### D5: Empty `text` shows a localised italic placeholder, not a hidden widget

**Decision**: When `text.trim() === ''`, render an italic `t('mydash', 'No text content')` in `var(--color-text-maxcontrast)`. The wrapper still occupies 100% of the cell.

**Alternatives considered:**

- Render nothing (empty cell). Rejected because the widget is then invisible — users who add it then forget to fill it in have no signal that anything is there, and the cell is not a drop target hint.
- Show a default "Edit me" string. Rejected because that string would persist into screenshots and exports if the user never edits — a placeholder must look obviously like a placeholder.

**Rationale**: Italic + low-contrast colour reads instantly as "empty state", matches the convention used elsewhere in MyDash (empty-tile placeholder), and keeps the cell as a valid GridStack drop target.

### D6: Sub-form `validate()` returns array of errors, modal-controlled disabling

**Decision**: The sub-form exposes a `validate(): string[]` method matching the existing AddWidgetModal contract — returns `[]` when valid, otherwise an array of localised error strings. The modal uses array length to enable/disable its primary button.

**Alternatives considered:**

- Boolean `isValid()`. Rejected because the modal cannot then surface the specific reason ("Text is required") to the user.
- Vuelidate / Vee-Validate. Rejected because the rest of the codebase uses the simple array-of-strings pattern; a new validation library would be inconsistent.

**Rationale**: Matches the existing pattern (REQ-WDG-010 / REQ-WDG-012 in the `widgets` capability). One method, one return shape, easy to test in isolation.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| `v-html` is a Vue anti-pattern in general — reviewers may flag it | Sanitisation is mandatory and tested; the use is documented in the spec scenarios; a non-sanitising fallback is impossible to ship because a Vitest test asserts script stripping |
| DOMPurify version drift could regress sanitisation (rare but happened in 2024 with ALLOWED_URI_REGEXP changes) | Pin to `^3.x` in package.json; tests assert specific dangerous patterns (`<script>`, `onclick`, `javascript:`) are stripped — a regression breaks CI |
| Free-form fontSize lets users type bad CSS (`fontsize: 99999px`, `red`) | Browser silently ignores invalid values; worst case is the wrapper renders at the default — no security or layout-corruption risk |
| Bundle size: DOMPurify adds ~20KB minified | Acceptable — only loads when at least one text widget is on the dashboard; lazy-loaded with the renderer chunk |
| Free-form HTML invites users to paste arbitrary `<style>` or `<link>` tags that could affect dashboard layout | DOMPurify default config strips `<style>` and `<link>` — confirmed in the test suite |

## Migration

No data migration. The `styleConfig` column already exists; existing placements continue to work unchanged. New `text`-type placements simply use a new shape inside that JSON column.

### D7: Dual content modes (HTML and Markdown) with Markdown as the shipped default

**Decision**: Support two distinct `contentMode` values — `'html'` and `'markdown'`. New widgets default to `'markdown'`; existing widgets (or explicitly created HTML-mode widgets) use `'html'`. The renderer branches on `contentMode` and applies the appropriate parsing pipeline.

**Alternatives considered:**

- Single markdown-only mode. Rejected because existing HTML-mode content would break on upgrade; backward compatibility is a hard requirement per REQ-TXMD-006.
- Always render as HTML (current state). Rejected because markdown is more accessible for ad-hoc content authoring — most users are familiar with `**bold**` and `[link](url)` syntax.

**Rationale**: Two modes maximize user choice and preserve content. The shipped default (Markdown) is the safer bet for new users; existing content remains untouched. The form's mode toggle lets users switch between modes (content is preserved, only parsing changes) or explicitly choose at creation time.

### D8: CommonMark compliance with strict sanitisation

**Decision**: The markdown parser MUST be CommonMark-compliant (headings, emphasis, code, links, lists, blockquotes, tables). Parser output MUST be passed through the same DOMPurify sanitiser used by HTML mode. Relative links are allowed; `javascript:` and `data:` URLs are stripped.

**Alternatives considered:**

- Markdown + custom allowlist (not using the existing DOMPurify config). Rejected because code duplication is error-prone; one sanitiser = one place to fix if a bypass is found.
- Markdown without sanitisation. Rejected — critical security issue (user can embed `<script>` in fenced code blocks or tables).

**Rationale**: CommonMark is the standard; compliance ensures portability and user expectations. Reusing the DOMPurify sanitiser keeps the security posture simple and testable.

### D9: Mode toggle does NOT lose content when switching

**Decision**: Switching `contentMode` from HTML to Markdown (or vice versa) preserves the `text` field unchanged. Only the rendering/parsing behavior changes — the user's raw text content stays intact.

**Alternatives considered:**

- Attempt "smart" conversion (HTML ↔ Markdown). Rejected because many HTML snippets (e.g. `<p>`, nested lists) have no direct markdown equivalent, and conversion can lose information or produce unexpected results.

**Rationale**: Preserving content lets users experiment with mode toggles without fear of data loss. If the text happens to be valid Markdown when rendered as HTML, the user sees literal markdown syntax (`# Heading` instead of a heading) — which is a signal to switch modes, not a silent corruption.

### D10: Backward compatibility — undefined `contentMode` defaults to HTML mode

**Decision**: When rendering a widget with `contentMode === undefined` or `contentMode === null`, treat it as `'html'`. Only render as Markdown when `contentMode === 'markdown'` explicitly.

**Alternatives considered:**

- Default undefined `contentMode` to Markdown. Rejected because existing widgets created before markdown support was added were authored as HTML and would break on upgrade.

**Rationale**: Preserves visual fidelity of existing deployments. New widgets in the registry default to `'markdown'`, so all freshly-created content uses the modern mode; old content stays backward-compatible.

## Open Questions

- Should the renderer support a target=_blank affordance for links? DOMPurify's default config preserves `<a target>` but the surrounding modal does not yet have a "open links in new tab" toggle. Out of scope here; can land in a follow-up.
- Should the sub-form offer a small set of preset themes (e.g. "Warning", "Info", "Success") that prefill colour + background combos? Out of scope; user demand will tell us.
- A future admin setting (`mydash.text_widget_default_mode`) may allow changing the registry default from Markdown to HTML globally. Deferred to the `admin-text-widget-settings` capability.
