# Design — Label Widget

## Overview

The dashboard `text` widget today supports full HTML content via `v-html`, is multi-line, and scrolls on overflow. This breadth works well for content blocks, but creates unnecessary complexity and security surface for the most common authoring use case: a short, single-line, plain-text heading that titles a row of widgets or marks a dashboard zone. Folding heading-style behaviour into the existing `text` widget would either compromise its content-block semantics or bury an extra "is this a heading?" toggle in its form.

The `label` widget is a dedicated, lightweight type that renders plain text only (via Vue interpolation, never `v-html`), ships sensible heading-style defaults (`16px` bold centred), and eliminates the XSS surface entirely. It is intentionally narrow — one renderer, one form, one registry entry — so it can evolve or be deprecated independently of the broader widget machinery.

## Goals

- Provide a simple, plain-text widget type with no XSS surface (no `v-html`, no HTML injection)
- Ship heading-style defaults so a freshly added label looks correct without styling input
- Keep the implementation small and self-contained so it is testable, auditable, and independent
- Allow future heading semantics (`<h3>`, `aria-level`) to be added without entangling the `text` widget

## Non-Goals

- Full HTML support (that is the `text` widget's domain)
- Markdown or templating syntax
- Multi-line text wrapping with scroll bars (single line, overflow-wrap only)
- Per-label translations or i18n beyond the empty-state fallback
- Integration with dashboard-level styling inheritance (each label owns its own inline styles)

## Architecture

### Data Model

Label placements use the existing `oc_mydash_widget_placements.styleConfig` JSON column with the discriminated shape:

```json
{
  "type": "label",
  "content": {
    "text": "string (required)",
    "fontSize": "string (default: 16px)",
    "color": "string (default: var(--color-main-text))",
    "backgroundColor": "string (default: transparent)",
    "fontWeight": "string (default: bold)",
    "textAlign": "string (default: center)"
  }
}
```

No schema migration is required — the column already stores arbitrary JSON objects keyed by widget type.

### Component Structure

```
src/components/Widgets/
├─ Renderers/
│  └─ LabelWidget.vue          (new: ~80 lines)
│     ├─ Props: {content, placement}
│     ├─ Computed: displayText, wrapperStyle, spanStyle
│     └─ Template: <div :style="wrapperStyle"><span :style="spanStyle">{{ displayText }}</span></div>
│
└─ Forms/
   └─ LabelForm.vue             (new: ~120 lines)
      ├─ Props: {editingWidget}
      ├─ Emits: update:content
      ├─ Data: six controls (text input, fontSize text, color picker, backgroundColor picker, fontWeight select, textAlign select)
      └─ Method: validate() → errors[]
```

### Registry Integration

```javascript
// src/constants/widgetRegistry.js

const widgets = {
  label: {
    renderer: LabelWidget,
    form: LabelForm,
    defaultContent: {
      text: '',
      fontSize: '16px',
      color: '',
      backgroundColor: '',
      fontWeight: 'bold',
      textAlign: 'center'
    },
    icon: 'icon-document-alt',
    displayName: t('mydash', 'Label')
  },
  // ... other widgets
}
```

### Rendering Pipeline

1. Dashboard loads `oc_mydash_widget_placements` rows; each row has `{type, content, ...}`.
2. Dashboard renderer looks up `widgets[type]` in the registry.
3. For `type: 'label'`, renderer imports `LabelWidget.vue` and passes `content` as a prop.
4. `LabelWidget.vue` computes `displayText` (fallback to `t('Label')` if empty), applies default styles, and renders via Vue interpolation.

### i18n Keys

| Key | EN | NL |
|---|---|---|
| `Label` | Label | Label |
| `Label text is required` | Label text is required | Labeltekst is verplicht |
| `Font Weight` | Font Weight | Letterdikte |
| `Alignment` | Alignment | Uitlijning |

All keys are namespaced as `t('mydash', 'Key')` and live in `src/l10n/en.json` and `src/l10n/nl.json`.

## Design Decisions

### D1: Plain-text only via Vue interpolation, never `v-html`

**Decision**: Render `text` via `{{ text }}` (Vue interpolation). Do not use `v-html` or `innerHTML` under any circumstances.

**Alternatives considered:**

- Render with `v-html` and sanitise the input. Rejected: sanitisation is an additional dependency, adds runtime overhead, and shifting the responsibility to the form validator creates a false sense of safety. Escaping the input entirely is simpler.
- Render as an `<input readonly>`. Rejected: not semantically correct and complicates styling.

**Rationale**: Vue interpolation automatically escapes HTML, so `<b>test</b>` renders as the literal string on screen. This is the safest option and requires no additional validation or sanitisation.

### D2: Default styling as a centred, bold, 16px heading

**Decision**: When form fields are absent, empty, or null, apply defaults: `fontSize='16px'`, `color='var(--color-main-text)'`, `backgroundColor='transparent'`, `fontWeight='bold'`, `textAlign='center'`.

**Alternatives considered:**

- Use CSS class defaults and let the form override inline styles. Rejected: mixing class and inline styles is fragile and harder to test. Inline styles are explicit.
- Per-theme defaults (light vs. dark). Rejected: CSS variable references like `var(--color-main-text)` already handle theming; resolving at build time would lose that flexibility.

**Rationale**: Most dashboard labels are headings. Shipping heading-style defaults means a new label looks correct immediately; users who want different styling can override. CSS variables for colour preserve light/dark theme switching without re-saving.

### D3: Empty-content placeholder `t('Label')` for discoverability during editing

**Decision**: When `text` is empty, whitespace-only, or undefined, render the translated literal `t('mydash', 'Label')` as a placeholder.

**Alternatives considered:**

- Render nothing (empty cell). Rejected: during editing, an invisible widget is indistinguishable from a deletion; users lose track of where the label is.
- Render `"(empty)"` as a hardcoded string. Rejected: users in non-English locales see English UI; unprofessional.
- Render the placeholder only in edit mode, hide it in view mode. Rejected: adds complexity and hides a broken widget from viewers.

**Rationale**: The placeholder is visible to both authors and viewers, serving as a gentle nudge to fill in the text while keeping the widget discoverable on the grid.

### D4: Separate widget type, not a toggle on `text` widget

**Decision**: Introduce `label` as a distinct widget type, not a mode of the existing `text` widget.

**Alternatives considered:**

- Add an `isHeading` boolean toggle to the `text` widget. Rejected: couples the two types forever, complicates the `text` form and renderer, and makes it impossible to deprecate one without the other.
- Combine at form level only, use the same renderer. Rejected: the security posture (no `v-html`) and semantics (single line) are incompatible with `text`'s multi-line HTML support.

**Rationale**: Small, focused components are easier to test, audit, and evolve. Future heading semantics (`<h3>`, `aria-level`) can be added without touching `text`. If the dashboard ever gets a different heading paradigm, `label` can migrate or deprecate independently.

### D5: `overflow-wrap: break-word` for long single words

**Decision**: Apply `overflow-wrap: break-word` on the `<span>` so long single words wrap to a new visual line rather than overflowing the cell horizontally or creating a scrollbar.

**Alternatives considered:**

- `word-break: break-all` (break every N characters). Rejected: breaks normal multi-word text at awkward positions.
- `white-space: nowrap` with horizontal scrollbar. Rejected: scrollbars inside a widget are confusing.
- Truncate with `text-overflow: ellipsis`. Rejected: words are lost silently; users don't know they were cut.

**Rationale**: `overflow-wrap: break-word` is the CSS standard for this use case. It preserves normal word boundaries for readable text while gracefully handling pathological input like `Pneumonoultramicroscopicsilicovolcanoconiosis`.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| XSS via `text` field injection (e.g., `<img src=x onerror=alert(1)>`) | Vue interpolation escapes all HTML; no `v-html` anywhere in the component. Unit tests verify literal HTML appears as text, not DOM elements. |
| Developer accidentally uses `v-html` in a future edit | Vitest assertion: if `v-html` appears in `LabelWidget.vue`, the test fails. Code review. |
| Users expect HTML support and are confused | Proposal and user guide clarify: "Label is for plain text; use Text widget for HTML content." |
| User saves a label with empty text; it disappears during editing | Empty text shows the fallback `t('Label')`; the widget is always visible. |
| Theme colour not resolved in dark mode | Use CSS variable `var(--color-main-text)`, not a hardcoded colour. Tested in both light and dark themes. |
| User enters a non-CSS value for `fontSize` (e.g., `"huge"`) | Browser ignores invalid CSS values; the renderer falls back to the default. No form validation of CSS syntax (user takes responsibility). |

## Seed Data

This change introduces no new OpenRegister schemas. The `label` widget type coexists with all other widget types in the existing `oc_mydash_widget_placements.styleConfig` JSON column. No migration is required; existing dashboards continue to work unchanged.

The label widget renders data that is authored through its own form; there are no external data sources to seed. The i18n keys (`Label`, `Label text is required`, etc.) are seeded by the localization files (`src/l10n/en.json`, `src/l10n/nl.json`).

No `_registers.json` entry is required for this change.

## Test Strategy

### Unit Tests (Vitest)

**`src/components/Widgets/Renderers/__tests__/LabelWidget.spec.js`**

- Render `{text: 'Hello'}` → visible text is `"Hello"`
- Render `{text: '<b>HTML</b>'}` → visible text is literal `"<b>HTML</b>"`, no `<b>` element in DOM (REQ-LBL-001)
- Render `{text: '<script>alert(1)</script>'}` → visible text is literal, script does not execute (REQ-LBL-001)
- Render `{text: 'Test'}` (no other fields) → computed style includes `font-size: 16px`, `font-weight: bold`, `text-align: center` (REQ-LBL-002)
- Render `{text: 'X', fontSize: '32px', fontWeight: 'normal', textAlign: 'left'}` → style includes `font-size: 32px; font-weight: normal; text-align: left`; `backgroundColor` defaults to transparent (REQ-LBL-002)
- Render `{text: ''}` → visible text is the translation of `'Label'` (REQ-LBL-004)
- Render `{text: '   '}` → visible text is the translation of `'Label'` (REQ-LBL-004)
- Render in a 2-column-wide narrow cell with `text: "Pneumonoultramicroscopicsilicovolcanoconiosis"` → text wraps within cell bounds, no horizontal scrollbar (REQ-LBL-003)
- Wrapper has `width: 100%; height: 100%; padding: 12px; display: flex; align-items: center; justify-content: center;` (REQ-LBL-006)

**`src/components/Widgets/Forms/__tests__/LabelForm.spec.js`**

- Form has six controls: text input (required), fontSize input (placeholder `16px`), color picker, backgroundColor picker, fontWeight select, textAlign select
- `validate()` returns `[t('Label text is required')]` when `text.trim() === ''`, otherwise `[]` (REQ-LBL-005)
- Pre-fill: open in edit mode with `editingWidget.content = {text: 'Hi', fontSize: '20px', color: '#ff0000', backgroundColor: '#ffffff', fontWeight: '700', textAlign: 'right'}` → all six controls display their values (REQ-LBL-005)
- Emit `update:content` on each input change (text, fontSize, color pickers, selects)

**`src/constants/__tests__/widgetRegistry.spec.js`**

- Registry exports a `label` entry (REQ-LBL-007)
- `label.renderer` is `LabelWidget`, `label.form` is `LabelForm`
- `label.defaultContent` equals `{text: '', fontSize: '16px', color: '', backgroundColor: '', fontWeight: 'bold', textAlign: 'center'}` (REQ-LBL-007)
- Adding a new label via `AddWidgetModal` with defaults persists `{type: 'label', content: {fontSize: '16px', fontWeight: 'bold', textAlign: 'center', ...}}` (REQ-LBL-007)

### End-to-End Tests (Playwright)

**`tests/e2e/label-widget.spec.ts`**

- Open Add Widget modal → select Label → fill text field with `"Dashboard Title"` → change fontSize to `"24px"` → pick a custom color → save → label appears on dashboard with the correct styling (REQ-LBL-002, REQ-LBL-006)
- Open the same label in edit mode → confirm all six form fields are pre-filled with the saved values → clear the text field → attempt to save → validation error appears (REQ-LBL-005)
- Paste `<b>Sales Q4</b>` into text field → save → on the dashboard, the visible text is literal `"<b>Sales Q4</b>"`, no `<b>` element rendered (REQ-LBL-001)
- Create a label with very long text in a narrow cell → text wraps within cell, no horizontal scrollbar (REQ-LBL-003)

### Quality Checks

- **ESLint**: `npm run lint` passes on `LabelWidget.vue`, `LabelForm.vue`, and modified `widgetRegistry.js`
- **Build**: `npm run build` completes with no warnings
- **Console**: No new browser console errors on render, edit, or remove
- **Theming**: Manual smoke test in both `nldesign` light and dark themes confirms colour defaults resolve correctly
- **Grep lint** (optional, enforced by CI if added): no direct calls to renderer or form outside the registry; no hardcoded widget type strings in tests

### Integration Assumptions

- The existing `oc_mydash_widget_placements` table and its JSON column are already schema-valid
- `AddWidgetModal` correctly routes all widget types through the registry
- The i18n loading mechanism correctly picks up new keys from `src/l10n/` files
- CSS variables like `var(--color-main-text)` are available in the rendering context (confirmed by `text` widget)
