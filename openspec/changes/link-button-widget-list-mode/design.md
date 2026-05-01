# Design — Link Button Widget List Mode

## Context

The link-button-widget capability (currently in `openspec/changes/`, not yet promoted) renders a single styled button that opens a URL, invokes a named internal action, or creates a file. Dashboard authors who want a navigation panel or a set of related quick-links currently place multiple single-link widgets side by side, consuming a proportionally large number of grid cells. This change extends the widget with a `displayMode: 'list'` that consolidates multiple links into one cell, rendered either as a vertical stack or as horizontal pill buttons.

The design preserves full backward compatibility: existing placements have no `displayMode` field, which the renderer treats as `'button'` (the original single-link mode). The `links` array is only populated in `'list'` mode; the single-link fields (`url`, `label`, `icon`, `actionType`, `backgroundColor`, `textColor`) remain the source of truth in `'button'` mode. Each entry in the `links` array follows the same per-link schema as the parent `link-button-widget` single-link config.

This spec depends on `link-button-widget` being in place. It does not modify the single-link rendering path.

## Goals / Non-Goals

**Goals:**
- Add `displayMode: 'button' | 'list'` to the placement config, defaulting to `'button'`.
- In `'list'` mode, render a sequence of links from a `links: array` field using the parent widget's per-link schema.
- Support `orientation: 'vertical' | 'horizontal'` for the list layout.
- Reuse the parent's URL sanitisation (reject `javascript:` and `data:`) on every list entry.
- Provide a Vue list-editor component with drag-to-reorder and per-row edit using the parent's single-link form.

**Non-Goals:**
- Replacing the existing single-link button with the list mode (both coexist under `displayMode`).
- Nested lists or grouped sections within a single list widget.
- Per-link background colour in `'list'` mode (the widget background applies to the whole cell).
- A maximum link count beyond the grid cell's natural height limit.

## Decisions

### D1: Schema additivity — `displayMode` defaults to `'button'`
**Decision**: `displayMode` is absent on all existing placements; the renderer treats absence as `'button'` and uses the single-link fields, unchanged.
**Alternatives considered**:
- Migrate existing placements to explicit `displayMode: 'button'` — rejected: unnecessary database churn with no user-visible benefit.
**Rationale**: Zero-migration additivity is the lowest-risk extension path. All existing widget behaviour is preserved without a data migration.

### D2: Per-link schema — reuse parent's single-link config shape
**Decision**: Each entry in `links[]` carries `{label, url, icon, actionType}` — the same fields as the parent's single-link config minus the widget-level `backgroundColor` and `textColor`.
**Alternatives considered**:
- A simplified per-link schema with only `label` and `url` — rejected: authors who want an internal action or createFile entry in a list cannot do so.
- Include `backgroundColor` and `textColor` per link — rejected: inconsistent visual rhythm within the list; widget-level colour applies uniformly.
**Rationale**: Reusing the parent schema means the per-row edit form is the parent's `LinkButtonForm.vue` with the colour fields hidden, reducing code duplication.

### D3: Per-link icon resolution — same dual-mode as parent REQ-LBN-002
**Decision**: Each list entry resolves its `icon` using the same MDI-name / uploaded-resource-URL dual-mode as the parent widget's `IconRenderer`.
**Alternatives considered**:
- Icon-only for list entries — rejected: some entries need text labels without icons; some need both.
- No icons in list mode — rejected: unnecessarily restricts functionality that already exists.
**Rationale**: Reusing the existing `IconRenderer` keeps both modes visually consistent and avoids a separate icon-resolution code path.

### D4: List orientation — `vertical` flex-column vs `horizontal` flex-row pills
**Decision**: `orientation: 'vertical' | 'horizontal'` on the list config; `'vertical'` renders a `flex-column` stack, `'horizontal'` renders `flex-row` pills with wrapping.
**Alternatives considered**:
- Grid layout — rejected: overkill for a one-dimensional link list.
- Orientation inferred from the grid cell aspect ratio — rejected: auto-inference would surprise authors when they resize the cell.
**Rationale**: An explicit boolean-equivalent choice is the simplest UX. Authors pick the orientation once when configuring the widget and it does not change unless they edit it.

### D5: List editor UX — drag-to-reorder with per-row edit panel
**Decision**: A `LinkListEditorForm.vue` component shows a draggable list of entries; clicking a row opens the parent's `LinkButtonForm.vue` as an inline panel with colour fields hidden.
**Alternatives considered**:
- Inline editing directly in the list row — rejected: insufficient horizontal space for six fields in a single row.
- A separate modal per link — rejected: too many modal layers given the list editor is already inside the widget edit modal.
**Rationale**: An inline slide-down panel provides enough space for all fields without additional modal nesting, and reuses the existing form without modification.

### D6: URL sanitisation applied per list entry
**Decision**: The same `javascript:` and `data:` scheme rejection applied to the single-link widget's `url` field is applied to each `links[].url` on save.
**Alternatives considered**:
- Sanitise only at render time (omit the link if the scheme is rejected) — rejected: silent data loss on save is worse than a validation error.
**Rationale**: Validation at save time returns a clear error to the author; render-time silencing produces invisible broken links.

### D7: List entry count — no hard limit beyond HTTP payload limits
**Decision**: No explicit maximum number of list entries is enforced at the API level; the editor soft-caps at 20 entries via a disabled "Add" button.
**Alternatives considered**:
- Hard cap of 10 entries — rejected: some navigation panels legitimately exceed 10 items.
- No soft cap in the editor — rejected: an unbounded list editor degrades usability for very long lists.
**Rationale**: The dashboard grid cell provides a natural visual limit. A soft cap at 20 covers the vast majority of use cases; authors who need more can split across multiple list widgets.

## Risks / Trade-offs

- **Reorder state loss** → If the author drags entries and then navigates away without saving, the reorder is lost. Mitigation: the edit modal already has an unsaved-changes guard from the parent widget infrastructure.
- **Horizontal overflow** → In `'horizontal'` orientation with many long labels, pills may overflow the cell. Mitigation: `flex-wrap` is enabled; overflowing pills wrap to the next row rather than clipping.

## Open follow-ups

- Decide whether `displayMode` should be togglable after creation without losing the `links` array when switching back to `'button'` (preserve `links` in storage but ignore it in `'button'` mode).
- Determine whether the per-link `actionType: 'createFile'` makes sense inside a list (each click would prompt for a filename separately); consider restricting `actionType` to `'external' | 'internal'` in list mode.
- Confirm drag-to-reorder library availability — check whether the existing Vue dependency set already includes a sortable list component before adding a new one.
