# Design — Links Widget

## Context

Intranet dashboards frequently need a curated, multi-section list of links — think
"Finance", "HR", and "IT" each with five to ten destination URLs. This is distinct from
the single-button `link-button-widget` and from `quicklinks-widget` (a flat icon grid).
The links widget groups links under labelled sections and supports multiple columns so
the full set fits without excessive vertical scrolling.

Section headings give the grid semantic structure that benefits both sighted users
(scannable at a glance) and screen reader users (can jump by heading). Each link carries
an optional icon, a label, and a destination URL.

The widget is editor-managed: admins build and reorder sections and individual links via
an inline drag-and-drop interface. No backend API is involved at read time — the full
data set is stored in the widget config and rendered client-side.

## Goals / Non-Goals

**Goals:**
- Render N sections, each with a heading and a list of labelled links.
- Support 1–4 column layouts with responsive CSS grid collapse.
- Provide drag-to-reorder for both sections and individual links.
- Sanitise all URLs before storage and render.

**Non-Goals:**
- Dynamic link sources (RSS, search results, external directory).
- Per-user personalisation of link visibility.
- Nested sub-sections.

## Decisions

### D1: Data shape
**Decision:** `sections: [{ heading: string, links: [{ label, url, icon? }] }]` stored
in widget config JSON.
**Alternatives considered:** Flat array with a `section` foreign-key field.
**Rationale:** Nested structure mirrors the render tree, simplifies drag-to-reorder
scope, and is the natural shape for JSON config storage.

### D2: Column count (`cols: 1|2|3|4`)
**Decision:** Admin sets `cols`; CSS grid wraps sections into that many columns. On
narrow viewports (< 600 px), always collapse to 1 column.
**Alternatives considered:** `auto` flow (sections fill naturally without a hard count).
**Rationale:** Explicit column count gives the admin predictable layout control; auto
flow can produce uneven last rows that look unintentional.

### D3: Section ordering via drag-to-reorder
**Decision:** Sections and their links are each reorderable via drag handles in the
editor; final order is the array order persisted to config.
**Alternatives considered:** Alphabetical sort; fixed order with manual index fields.
**Rationale:** Array-order is the simplest representation for arbitrary sort; drag
handles are the established pattern in the widget editor for reordering items.

### D4: URL sanitisation
**Decision:** On save, reject any URL whose scheme is not `http`, `https`, `mailto`, or
`tel`. Store a validation error on the offending link; block save until resolved.
**Alternatives considered:** Client-side warn but allow save; server-side only.
**Rationale:** `javascript:` and `data:` URIs are XSS vectors. Blocking at save (both
client and server) is the correct defence-in-depth approach.

### D5: Edit UX — inline per-section editor with drag handles
**Decision:** Each section expands to an inline editor showing heading input and a
draggable link list; a "+ Add section" button appends a new section at the bottom.
**Alternatives considered:** Modal editor per section; side-panel editor.
**Rationale:** Inline editing keeps context visible; admins can see the full layout
while editing any section, reducing layout surprises.

## Risks / Trade-offs

- Large link sets (10+ sections × 10 links) will produce a long editor panel — consider
  a collapse-all/expand-all affordance in a follow-up.
- Drag-to-reorder requires accessible keyboard alternatives (arrow-key reorder) to meet
  WCAG 2.1 AA; this must be included in implementation, not deferred.

## Open follow-ups

- Add a "link count badge" on collapsed sections in the editor for at-a-glance density.
- Consider a dynamic source mode (URL returning JSON link list) once the links widget
  reaches steady adoption.
