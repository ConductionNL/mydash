# Design — Text Widget Markdown

## Context

The text-display-widget capability stores and renders pre-authored HTML content. Authors who want headings, lists, or links must write HTML directly, which is a barrier for non-technical users. This change extends the widget with an alternative `inputMode: 'markdown'` that converts CommonMark-compliant markdown to HTML server-side before saving. The converted HTML then travels through the same sanitisation pipeline that already handles the `inputMode: 'html'` path, so both modes share a single trust boundary.

This is an extension of the `text-display-widget` capability (currently in `openspec/changes/`, not yet promoted). It does not change the rendering contract — widgets still persist and display HTML. What changes is how that HTML is produced when the author writes markdown. The sibling `text-widget-tables` spec covers the structured table editor; this spec only owns the markdown parser and heading-downscale rule.

Existing placements are untouched. The `inputMode` field defaults to `'html'` so every widget created before this change continues to work without any migration.

## Goals / Non-Goals

**Goals:**
- Add `inputMode: 'markdown'` as a recognised value on text-widget placements.
- Convert CommonMark markdown to HTML server-side using the library already in the vendor tree.
- Pass the resulting HTML through the existing HTML sanitiser without a separate sanitisation path.
- Downscale H1 headings in markdown output to H2 to protect the dashboard's document outline.
- Default `inputMode` to `'html'` so existing widgets are unaffected.

**Non-Goals:**
- A rich in-widget markdown editor or preview (edit box is a plain textarea; rendering happens on save).
- Client-side markdown parsing (server-side conversion is the only supported path in this spec).
- Table-insertion UX — that is the `text-widget-tables` spec.
- Changing the sanitiser allow-list (reuse the existing one).

## Decisions

### D1: Markdown library selection
**Decision**: Use the CommonMark library already present in the vendor tree; enable the GFM tables extension.
**Alternatives considered**:
- A lightweight custom regex parser — rejected: brittle and not CommonMark-spec-compliant.
- A different third-party library — rejected: adds a new dependency when a compliant one already exists.
**Rationale**: Reusing an already-vendored library adds zero installation cost and guarantees spec-compliant parsing. The GFM tables extension is needed for the sibling `text-widget-tables` spec to share the same renderer.

### D2: Conversion timing — server-side before save
**Decision**: Markdown is converted to HTML on the server when the placement is saved, not at render time.
**Alternatives considered**:
- Convert at render time on every page load — rejected: wastes CPU per-request and complicates caching.
- Convert client-side in the browser — rejected: introduces a JS parser dependency and breaks server-side render paths.
**Rationale**: Converting once on save means the stored value is always HTML, so the renderer has no awareness of the original mode. This minimises blast radius if the markdown library is ever swapped out.

### D3: Sanitisation pipeline — single shared path
**Decision**: Markdown-derived HTML and author-supplied HTML both pass through the same sanitiser; no second pipeline.
**Alternatives considered**: none
**Rationale**: A second sanitiser would require its own allow-list maintenance and could drift from the primary. Sharing the pipeline guarantees that markdown cannot introduce tags or attributes that raw HTML cannot.

### D4: Heading downscale rule
**Decision**: `# Heading` in markdown produces `<h2>`, `## Heading` produces `<h3>`, and so on; no `<h1>` ever appears in rendered output.
**Alternatives considered**:
- Allow `<h1>` and rely on CSS to suppress it — rejected: produces invalid document outlines and fails WCAG 1.3.1.
- Strip all headings — rejected: removes author intent entirely.
**Rationale**: A dashboard page always has exactly one `<h1>` (the page title). Widget content is subordinate and must start at `<h2>`.

### D5: Backward compatibility via `inputMode` default
**Decision**: `inputMode` is absent on all existing placements; the renderer treats absence as `'html'`.
**Alternatives considered**: none
**Rationale**: A zero-migration approach eliminates risk to existing content and allows the feature to ship without a database migration step.

### D6: Table syntax parsing scope
**Decision**: This spec enables `<table>` output when the author writes GFM table syntax; the structured table editor is deferred to `text-widget-tables`.
**Alternatives considered**:
- Disable table parsing in this spec and add it with `text-widget-tables` — rejected: the GFM extension is already present once the library is enabled; artificially blocking it would require extra code to strip tables.
**Rationale**: Parsing and editing are separate concerns. The parser can produce tables even before the editor UX exists; authors can hand-write markdown table syntax in the textarea.

### D7: Admin default for new widgets
**Decision**: An admin setting `mydash.text_widget_default_mode` (`'html'` or `'markdown'`, default `'markdown'`) controls which mode is pre-selected when a new text widget is added.
**Alternatives considered**:
- Hard-code the default to `'markdown'` — rejected: organisations already using markdown-aware tools may want `'html'` as the default.
**Rationale**: Gives administrators control without exposing per-user preferences.

## Risks / Trade-offs

- **Markdown with embedded HTML** → The author may write raw `<script>` inside a markdown block. The sanitiser strips it, but authors may be confused. Mitigation: UI copy explains that output is sanitised.
- **Heading shift surprises** → Authors writing `# My Title` expecting a large heading will see `<h2>`. Mitigation: the edit form shows a note explaining the downscale rule.

## Open follow-ups

- Decide whether the edit form should show a live preview of the rendered markdown.
- Define whether `inputMode` should be editable after initial creation (changing from markdown to html after conversion would require re-saving the original markdown source, which is not retained).
- Confirm that the sanitiser allow-list covers `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>` before shipping.
