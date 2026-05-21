# Design — Quicklinks Widget

## Context

MyDash dashboard admins have two existing tools for adding URL shortcuts: the link-button widget (one per placement) and the links widget (grouped sections). The link-button widget is verbose for bulk scenarios (8+ shortcuts = 8+ placements), while the links widget spreads related URLs across columns and lacks the fine-grained visual control (icon size, label position, hover style) that modern app launchers provide. This change introduces a third option optimised for the most common case: a single placement containing a flat, dense grid of 8–40 shortcuts with admin-configurable appearance and layout.

REQ-GRID-001 already declares the intent ("multiple widgets types with flexible layouts"), but does not specify the quicklinks type's configuration shape, icon rendering modes, label behaviour, or layout algorithm. This change formalises those decisions so admins can author app-launcher dashboards with a single widget placement and deterministic visual and interactive behaviour.

## Goals / Non-Goals

**Goals:**

- Enable a single-placement app launcher that accommodates 8–40 shortcuts without becoming verbose (unlike link-button-per-URL).
- Provide fine-grained admin control over appearance (icon size/shape, label position, tile background, hover effect) so quicklinks can adapt to any dashboard aesthetic.
- Support bulk CSV import so admins can move shortcuts from a spreadsheet in seconds rather than typing each URL by hand.
- Ensure external URLs open in a new tab by default, internal URLs in the same tab, with explicit `openInNewTab` override available.
- Implement client-side and server-side URL validation to block XSS-like attacks (dangerous protocols).
- Render a helpful empty state so new dashboards do not appear broken when `links: []`.
- Maintain full keyboard and screen-reader accessibility so the widget is usable by all dashboard admins and viewers.

**Non-Goals:**

- Drag-to-reorder links within the widget. Link order is static after save. Reordering requires re-opening the edit form.
- Link grouping / collapsible sections. That is the purpose of the links widget; quicklinks stays flat.
- Link search / filtering. Out of scope; can be a follow-up enhancement if usage data warrants.
- Preview of target URL on hover (e.g., tooltip with hostname). Out of scope; would require async metadata fetching.
- Per-icon colour customization in the CSV import UI. The colour field is optional in the data model but form UX focuses on the six main config fields.
- Animated transitions when links are added/removed via edit. Out of scope; can be a follow-up purely-cosmetic change.

## Decisions

### D1: Single placement + icon/label array, not one placement per shortcut

**Decision**: Quicklinks uses a single placement containing an array of `{label, url, icon, color?, openInNewTab?}` link objects, with eight configuration fields controlling layout and appearance.

**Alternatives considered:**

- One placement per link (matching link-button widget). Rejected because that creates dashboard clutter and config verbosity; an 8-link app launcher would require 8 placements and 8 edits.
- Multiple groups with collapsible sections (matching links widget). Rejected because the most common use case is a flat, unsorted list of frequently used apps. Grouping is a separate concern and the links widget already handles it.

**Rationale**: A single placement with an array of links balances admin ergonomics (one click to edit all shortcuts) against user usability (all apps in one visually coherent widget).

### D2: Flex-wrap (auto) + fixed-grid (columns: N) layout modes, with smart fallback

**Decision**: The `columns` field supports two modes: `'auto'` (default, CSS Flexbox with wrap) and a number `1..12` (CSS Grid with fixed columns). Invalid values (e.g., `0`, `13`, `'invalid'`) fall back to `'auto'`.

**Alternatives considered:**

- Always fixed grid (e.g., always 4 columns). Rejected because different dashboard widths and icon sizes should flow naturally; fixed grid is less flexible.
- Always flex-wrap. Rejected because some admins want predictable 3-column layouts across all widths.
- Responsive breakpoints (e.g., `auto` on mobile, fixed on desktop). Rejected as out of scope; can be a follow-up enhancement if needed.

**Rationale**: Supporting both modes allows admins to choose: responsive (auto) for casual dashboards, fixed grid for structured "app launcher" layouts where every row has exactly N items.

### D3: Label position: below (default) or overlay on hover

**Decision**: The `labelPosition` field has two values: `'below'` (labels always visible, below icons) and `'overlay'` (labels appear on hover/focus only, centred over the icon). No other label positions (above, inline) are supported.

**Alternatives considered:**

- Four positions: above, below, left, right. Rejected — increases UX complexity (form sprawl) and CSS layout complexity for minimal benefit.
- Tooltip on hover (separate DOM element outside icon). Rejected — harder to position reliably and less accessible (native `<a>` aria-label is better).

**Rationale**: Below is the most common pattern (visible for understanding at a glance); overlay saves vertical space and works well with small icons. These two cover the primary use cases without layout complexity.

### D4: Icon resolution: custom URL or MDI name, with fallback

**Decision**: Each link's `icon` field can be a custom URL (starting with `/` or `http(s)://`), a bare MDI name (e.g., `folder`, `mail`), or empty (falls back to MDI `link`). No other formats are supported.

**Alternatives considered:**

- SVG inline (embed `<svg>` in the URL). Rejected — inconsistent with link-button widget and adds DOM parsing complexity.
- Emoji. Rejected — not all emoji are accessible via screen readers and icon size control would not apply.
- URL-encoded data: URIs. Rejected — discouraged for performance and security reasons.

**Rationale**: This dual-mode convention (custom URL or bare MDI name) is already used by link-button-widget (REQ-LBN-002). Consistency reduces cognitive load and allows icon picker code reuse.

### D5: Hover effects per-link, not widget-wide

**Decision**: Hover effects (`lift`, `fade`, `border`, `none`) apply only to the hovered link. The `fade` effect reduces other links' opacity while the hovered link remains fully opaque; other effects (lift, border) apply only to the hovered link itself.

**Alternatives considered:**

- Entire widget darkens on hover (less granular feedback). Rejected — users lose focus on individual shortcuts.
- Scale the entire grid (too disruptive). Rejected — makes the widget jump around.

**Rationale**: Per-link feedback is more precise and matches the interaction pattern of app launchers (click one app, not the whole grid).

### D6: Empty state message + gear icon, not blank white space

**Decision**: When `links: []`, the widget renders a centred message (`t('No quicklinks yet — click the gear icon to add some.')`) with a gear icon. Clicking the icon opens the edit form.

**Alternatives considered:**

- Blank widget with only a "+" button in the corner. Rejected — less discoverable for new users.
- No empty state, just blank space. Rejected — admins might think the widget is broken.
- Placeholder links (e.g., "Link 1", "Link 2"). Rejected — confusing and untidy.

**Rationale**: An explicit empty state with affordance (clickable gear icon) guides admins to next steps and signals that the widget is ready to use.

### D7: Client-side + server-side URL validation, blocking dangerous protocols

**Decision**: The form validates URLs on the client (inline feedback, red border on invalid field). On server save, the system re-validates to reject dangerous protocols (`javascript:`, `data:`, `vbscript:`) and enforce a 2048-character limit. Server rejection returns HTTP 400 with error details; the form displays a toast.

**Alternatives considered:**

- Client-side only. Rejected — attackers can bypass client validation via `curl` or the browser console.
- Server-side only. Rejected — users prefer immediate feedback without a round-trip.
- Whitelist allowed protocols (only `http`, `https`, `/`). Rejected — more restrictive than necessary; internal app URLs might use other schemes.

**Rationale**: Defence in depth. Client-side validation improves UX; server-side validation prevents bypass attacks. Blocking dangerous protocols is sufficient; URL parsing (missing host, malformed query string) is not required at this layer.

### D8: Auto-detect `openInNewTab` based on URL scheme

**Decision**: If a link's `openInNewTab` field is omitted or null, the renderer auto-detects: external URLs (`http(s)://`) default to `true` (open in new tab); internal URLs (`/`) default to `false` (open in same tab). Explicit values always override auto-detect.

**Alternatives considered:**

- All external URLs always open in new tab, no option to override. Rejected — some admins want to control internal URL behaviour.
- Admin-wide setting (all external URLs or all same-tab). Rejected — no per-dashboard granularity.

**Rationale**: Auto-detect provides sensible defaults (external = new tab for safety, internal = same tab for navigation flow) while allowing per-link overrides for power users.

### D9: CSV import with optional icon and colour fields

**Decision**: The form's CSV paste handler expects lines of `label,url,icon[,color]`. Icon and colour are optional. Lines with fewer than 2 fields are skipped with a warning. The handler automatically assigns `openInNewTab: true` to external URLs and `false` to internal ones.

**Alternatives considered:**

- Custom CSV columns picker (UI to map columns). Rejected — adds UI complexity; fixed order is fine for this first version.
- Only label + URL (no icon). Rejected — bulk imports lose icon information, requiring manual re-entry.
- Require all fields. Rejected — CSV files from external sources often omit optional fields; flexibility is better.

**Rationale**: Fixed column order is simple and matches common spreadsheet export patterns (name, link, icon). Optional fields let users paste minimal data without error messages.

### D10: Accessibility: always `<a>` elements with proper attributes

**Decision**: Every link renders as an HTML `<a>` element with `href`, `aria-label`, and `target`/`rel` attributes as needed. Tab navigation cycles through links in source order. Focus indicators meet WCAG AA contrast requirements.

**Alternatives considered:**

- `<button>` elements with click handlers. Rejected — `<a>` semantics are better for links; buttons are for actions.
- Keyboard shortcut numbers (1–9 to jump to link). Rejected — conflicts with browser shortcuts and is non-standard.

**Rationale**: Semantic HTML (`<a>` for navigation) is more accessible and requires less custom ARIA. Proper `href` allows right-click → open in new tab, which is standard user behaviour.
