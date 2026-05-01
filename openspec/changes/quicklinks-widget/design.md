# Design — Quicklinks Widget

## Context

A flat grid of icon-and-label shortcuts is the most common widget pattern on intranet
home pages. Unlike `links-widget` (which groups links into labelled sections) and the
existing `tiles` capability (one tile per grid placement), the quicklinks widget is a
single widget owning a self-contained icon grid. An admin configures all shortcuts in
one place; the widget renders them as a responsive tile mosaic.

Icon quality and consistent sizing matter more than prose — the primary use case is
"top 8 shortcuts for all staff": dense, scannable, low-click. Bulk-add via CSV paste is
first-class for admins migrating dozens of links from a spreadsheet.

## Goals / Non-Goals

**Goals:**
- Render a flat grid of icon+label shortcuts from widget config.
- Support icon size and shape configuration.
- Allow fixed or auto column count.
- Sanitise destination URLs.
- Provide bulk-add via CSV paste.

**Non-Goals:**
- Section groupings (use `links-widget` instead).
- Per-user personalisation or hiding of tiles.
- Drag-to-reorder in read mode (edit mode only).

## Decisions

### D1: Icon source — platform icon name OR URL
**Decision:** Each link accepts either a named icon from the platform icon set or a
fully-qualified image URL. Named icons are rendered via the existing icon component;
URL icons are rendered as `<img>` with the same allow-list pattern as `link-button-widget`.
**Alternatives considered:** URL-only (simpler); icon name only (requires hosted images
for branded shortcuts).
**Rationale:** Named icons are zero-maintenance for common destinations; URL icons let
admins use product logos without needing icon-set contributions.

### D2: Icon size enum (`small | medium | large | xlarge` → 32/48/64/96 px)
**Decision:** Single widget-level size setting applies to all icons in the grid.
**Alternatives considered:** Per-icon size; continuous slider.
**Rationale:** Uniform size preserves visual rhythm in the grid. Four T-shirt sizes
cover the range from dense information panels to hero-style shortcuts.

### D3: Icon shape enum (`square | rounded | circle` → 0 / 8 px / 50% border-radius)
**Decision:** Widget-level shape applies to all icon containers.
**Alternatives considered:** Per-icon shape; free border-radius input.
**Rationale:** Consistent shape across the grid is a design constraint, not a
per-link choice. Three named shapes map to recognisable UI conventions and require no
custom rendering.

### D4: Columns — `auto` flex-wrap OR `1..12` fixed CSS grid
**Decision:** Default `auto` uses `flex-wrap` with a minimum tile width of 80 px so
icons fill the available width naturally. Fixed values `1`–`12` switch to CSS grid with
that column count; the grid collapses by one column at each defined breakpoint below
768 px.
**Alternatives considered:** Fixed count only (less flexible); auto only (admin cannot
guarantee a 4-across layout on large screens).
**Rationale:** `auto` is the right default for unknown content counts; fixed count is
needed when the admin wants precise alignment with adjacent widgets.

### D5: URL sanitisation
**Decision:** On save, reject any URL whose scheme is not `http`, `https`, `mailto`, or
`tel`. Validation runs client-side on blur and server-side on config save.
**Alternatives considered:** Warn but allow; server-side only.
**Rationale:** Consistent with the `links-widget` policy; `javascript:` and `data:` URIs
are XSS vectors and must not be stored in widget config.

### D6: Bulk-add via CSV paste
**Decision:** An "Add multiple" button opens a textarea accepting `label,url` CSV (one
row per link, header row optional). Rows are parsed, validated, and appended; malformed
rows are highlighted; valid rows are added immediately.
**Alternatives considered:** File upload (requires multipart handling); no bulk mode.
**Rationale:** Paste is zero-friction — admins can paste directly from a spreadsheet.
Inline validation feedback before commit prevents silent data loss.

## Risks / Trade-offs

- URL icons from external hosts degrade the widget if a host is slow or unavailable;
  implement a fallback icon for failed image loads.
- CSV parsing must handle quoted fields with commas — use a proper tokeniser, not a
  naive comma split.

## Open follow-ups

- Drag-to-reorder in the editor (consistent with `links-widget`).
- Dynamic source mode: import from a URL returning a JSON link list.
- Fallback icon rendering when an image URL returns a non-2xx response.
