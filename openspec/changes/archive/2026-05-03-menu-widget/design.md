# Design — Menu Widget

## Context

Larger intranet dashboards increasingly need in-page navigation between sections or
sub-pages. The application sidebar handles app-level navigation; the menu widget handles
page-level or section-level navigation that is relevant only within a specific dashboard.
These two roles must remain clearly separated — the widget renders its own nav tree,
not a mirror of the sidebar.

The widget supports up to three levels of hierarchy. Beyond three levels, navigation
trees become cognitively expensive and collapse poorly on mobile. Depth is capped at the
data layer, not merely the UI, so the limit is enforced regardless of how the config is
loaded.

Keyboard navigation is a first-class concern: users navigating entirely by keyboard must
be able to open, traverse, and close every level without reaching for a pointer.

## Goals / Non-Goals

**Goals:**
- Render a hierarchical navigation tree up to 3 levels deep.
- Support three open-styles (flyout, dropdown, accordion).
- Open external URLs in a new tab; keep internal/relative URLs in-tab.
- Meet WCAG 2.1 AA keyboard navigation requirements.

**Non-Goals:**
- Mega-menu layouts (marketing-style full-width panels).
- Dynamic population from a sitemap API.
- Per-user item visibility based on group membership.

## Decisions

### D1: Tree depth cap at 3 levels
**Decision:** Enforce a maximum depth of 3 in the widget config schema; a 4th level is
rejected with a validation error.
**Alternatives considered:** Cap at 2 (insufficient for observed IA depths); unlimited
depth with a UX warning.
**Rationale:** 3 levels is the practical maximum before usability degrades sharply.
Schema-level enforcement prevents data drift that would break render components.

### D2: Data shape
**Decision:** `items: [{ label, url, icon?, children?: [...] }]` nested up to 2 child
levels (3 total), stored in widget config JSON.
**Alternatives considered:** Adjacency-list with parent_id (harder to render and reorder).
**Rationale:** Nested arrays match the render tree, simplify recursive components, and
terminate naturally at the depth limit.

### D3: Open-style enum (`flyout | dropdown | accordion`)
**Decision:** Admin selects one open-style. `flyout` opens on hover to the right;
`dropdown` opens on click below the parent; `accordion` expands inline (mobile-friendly).
**Alternatives considered:** Auto-select based on viewport (makes admin preview unreliable).
**Rationale:** Explicit choice keeps admin preview consistent with production. Responsive
adaptation within each style can be added as a follow-up without changing the data model.

### D4: External vs. internal URL detection
**Decision:** A URL is external if it has a scheme and its host differs from `window.location.host`.
External links open with `target="_blank" rel="noopener noreferrer"`. All other URLs
(relative, same-host absolute) open in the same tab.
**Alternatives considered:** Always open in same tab; always open in new tab; admin
toggles per link.
**Rationale:** Automatic detection matches user expectation with zero per-link config.
`rel="noopener noreferrer"` is the current best practice for new-tab links.

### D5: Keyboard navigation
**Decision:** Tab moves focus between top-level items. Enter/Space opens a sub-menu.
Arrow keys navigate within it. Escape collapses the nearest open level; focus returns to
the triggering item.
**Alternatives considered:** Tab through all items including children (too many Tab
presses to exit a long tree).
**Rationale:** Tab-to-top-item + arrow-within-submenu is the ARIA Authoring Practices
Guide pattern for navigation menus; familiar to AT users and keeps tab order manageable.

## Risks / Trade-offs

- Flyout style is pointer-dependent by default; a companion keyboard trigger (Enter to
  open flyout) is required for WCAG compliance and must not be deferred.
- Depth-cap enforcement at schema write time means existing configs with greater depth
  (imported from another system) will be rejected — a migration note is needed.

## Open follow-ups

- Responsive fallback: auto-switch to accordion below a configurable breakpoint.
- Per-group item visibility once the roles/permissions spec is stabilised.
- Icon picker integration consistent with `link-button-widget` icon source pattern.
