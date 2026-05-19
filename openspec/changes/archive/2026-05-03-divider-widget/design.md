# Design — Divider Widget

## Context

Dashboard layouts benefit from clear visual separation between content areas. Without a
dedicated separator primitive, admins resort to workarounds such as empty label widgets
or image placeholders — all of which carry unnecessary semantic weight and break
accessibility trees.

The divider widget is a pure-presentation element: a horizontal rule rendered with
configurable thickness, style, and spacing. It carries no data-fetch lifecycle, no
reactive state, and no backend calls. Its implementation surface is therefore tiny —
one Vue component, one set of widget-config fields.

Because it is structural rather than informational, special care must be taken to mark
it correctly for assistive technology so screen readers skip over it as a visual artifact
rather than treating it as meaningful content.

## Goals / Non-Goals

**Goals:**
- Render a horizontal separator with configurable visual properties.
- Expose thickness, line-style, spacing, and color in the widget editor.
- Satisfy WCAG 2.1 AA for decorative separators.

**Non-Goals:**
- Vertical orientation (out of scope; layouts handle column separation).
- Animated or gradient dividers.
- Responsive thickness changes.

## Decisions

### D1: Thickness enum (`thin | medium | thick`)
**Decision:** Map to 1 px / 2 px / 4 px via CSS custom property on the element.
**Alternatives considered:** Free-entry number input (0–20 px).
**Rationale:** Enum constrains choices to values that look intentional at common screen
densities; free entry produces one-off values that break visual rhythm across dashboards.

### D2: Line-style enum (`solid | dashed | dotted`)
**Decision:** Expose all three `border-style` values; default to `solid`.
**Alternatives considered:** Only `solid` (simpler but limits expressiveness for
section-break vs. soft-group semantics that admins already request).
**Rationale:** Three options are universally understood and map 1-to-1 to CSS — zero
custom rendering logic required.

### D3: Spacing presets (`compact | normal | spacious` → 8/16/32 px)
**Decision:** Apply chosen value as equal top and bottom margin on the host element.
**Alternatives considered:** Separate top/bottom inputs; T-shirt sizes but asymmetric.
**Rationale:** Equal margins keep the divider optically centred between flanking widgets.
Asymmetric spacing can always be achieved by stacking two dividers.

### D4: Color (custom hex OR theme variable)
**Decision:** Default to `var(--color-border-dark)`; editor offers a color picker that
writes a raw hex value when used, or a "use theme" toggle that writes the variable name.
**Alternatives considered:** Hard-code theme variable only (no custom color).
**Rationale:** Theme variable is correct for most dashboards; custom hex allows branded
portals to match a specific brand guideline without overriding global theme tokens.

### D5: Accessibility markup
**Decision:** Render as `<hr role="separator" aria-orientation="horizontal" aria-hidden="true">`.
**Alternatives considered:** `<div>` with `role="separator"` (avoids double-role on `<hr>`).
**Rationale:** `<hr>` has native separator semantics; `aria-hidden="true"` suppresses it
from the accessibility tree so screen readers treat it as decorative, matching intent.

## Risks / Trade-offs

- `aria-hidden` hides the separator from all AT; if future use-cases need a labelled
  section boundary, a different widget (e.g. header-widget) is the right tool.
- Custom hex color bypasses theme contrast guarantees — low risk since it is decorative,
  but worth noting in editor copy.

## Open follow-ups

- Consider a vertical orientation variant once the grid layout supports column gutters
  as a placement area.
- Evaluate whether spacing presets should account for grid row-gap so the divider
  does not double up whitespace.
