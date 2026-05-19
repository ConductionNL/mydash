# Design — Header Widget

## Context

Many intranet dashboards open with a branded banner: an organisation name, a welcome
headline, an optional background image, and a primary call-to-action button. Today this
is approximated by combining an image widget with a label widget, but the two can drift
out of alignment and neither carries the correct heading semantics.

The header widget is a full-width banner component. It owns title, optional image, and
optional CTA button in a single cohesive unit. Three layout modes cover the main
compositional patterns: text-only, image-beside-text, and image-as-background with
text overlay.

Because a page may already carry an `<h1>` from the application chrome, the widget must
let the admin choose the heading level for the title so they can avoid duplicate `<h1>`
landmarks — a WCAG 2.1 AA requirement.

## Goals / Non-Goals

**Goals:**
- Render a full-width banner with title, optional image, and optional CTA button.
- Support three layout modes with a single config toggle.
- Allow the admin to choose heading level (h1/h2/h3).
- Enforce an image URL allow-list configurable by the administrator.

**Non-Goals:**
- Files-picker integration (deferred — reuse `image-widget-media-picker` pattern later).
- CreateFile or action-ID CTA types (deferred to keep initial scope tight).
- Animated or video backgrounds.

## Decisions

### D1: Image source — URL only
**Decision:** Image source is a plain URL field. The allow-list of permitted hosts is
controlled by an admin setting (`mydash.header_widget_allowed_image_hosts`); empty list
means all hosts are permitted.
**Alternatives considered:** Files picker (deferred); data-URL inline upload.
**Rationale:** URL-only keeps the widget stateless and avoids file-storage coupling in
the first iteration. The allow-list prevents admins from inadvertently embedding tracking
pixels from arbitrary third-party hosts.

### D2: CTA action types — URL and internal route only
**Decision:** CTA button supports a URL (external, opens new tab) or an internal route
string (same-tab navigation within the app). No action_id or createFile hooks.
**Alternatives considered:** Full action registry (createFile, share, etc.).
**Rationale:** URL + internal route covers 90% of observed use-cases. Action registry
adds significant complexity and should be designed holistically across all widgets that
need it, not first in the header widget.

### D3: Layout modes (`image-bg-text-overlay | image-side-text-side | text-only`)
**Decision:** Expose three layout modes; default to `text-only` so the widget is
usable without an image URL configured.
**Alternatives considered:** Two modes only (overlay and side-by-side); free CSS input.
**Rationale:** Three named modes map to distinct CSS templates and cover the cases
present in existing intranet implementations. Free CSS input is a maintenance hazard.

### D4: Heading level enum (h1/h2/h3)
**Decision:** Admin selects heading level from a dropdown; rendered as the corresponding
HTML element. Default is `h2`.
**Alternatives considered:** Always `h1`; always `div` with font styling only.
**Rationale:** Allowing `h1` is necessary for dashboards where the banner IS the page
title; `h2` is the correct default for dashboards that already have an application-level
`h1`. Hardcoding either breaks one scenario. `div` with font styling breaks heading
navigation for AT users.

### D5: Background image allow-list admin setting
**Decision:** Admin config key `mydash.header_widget_allowed_image_hosts` holds a
newline-separated list of permitted hostnames. Empty = all permitted. Checked server-side
on config save.
**Alternatives considered:** Same-origin only; no allow-list (open).
**Rationale:** Open default matches existing image-widget behaviour. The allow-list is
opt-in restriction, not default restriction — the practical balance for on-premises.

## Risks / Trade-offs

- Text overlay requires sufficient contrast; editor should warn when the combination is
  likely to fail AA.
- Deferring the Files picker means admins must host images on an accessible URL.

## Open follow-ups

- Files picker integration reusing `image-widget-media-picker` allow-list pattern.
- Contrast checker in editor for `image-bg-text-overlay` mode.
- Extend CTA types to action_id once the cross-widget action registry is designed.
