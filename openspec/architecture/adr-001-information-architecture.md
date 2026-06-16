# ADR-001: Information Architecture

**Status:** accepted
**Date:** 2026-05-23

## Context

mydash is Nextcloud's organisation-dashboard surface for end users. The
backlog already covers 88 specs (widget types, governance toggles, AI
assistant, scheduled exports, BBV templates, ops tooling, compliance
panels, mobile policy, etc.) and grows each sprint. Without an explicit
information-architecture rule, every new spec turns into pressure to add a
top-level menu item — which would erode the canvas-first experience and
leave end users navigating a flat list of features instead of a small set
of destinations.

mydash also has two very different audiences sharing the same app:
end users who live on the dashboard canvas, and tenant admins who govern
sharing, routing, retention, compliance, and ops. Both groups need access
without bleeding into each other's surface.

Sister ADRs in the openregister fleet face the analogous problem on the
developer/admin side. This ADR captures the IA discipline for mydash so
that future specs (and the OpenSpec changes that propose them) land in a
known place by construction.

The full per-spec mapping (placement, parent, rationale) is maintained in
`/tmp/ia-mydash-openregister.md` (mydash section, table D). This ADR
captures the rules that produced that mapping so the same logic can be
re-applied to new specs without re-deriving it.

## Decision

mydash adopts a fixed top-level navigation of **seven destinations** and
applies the rules below to every new spec. Specs do not get menu items —
they get placements inside this fixed structure.

### Top-level navigation (7 items)

1. **Dashboards** — the canvas, the switcher, the only place end users
   spend time
2. **Templates** — BBV programma templates, demo showcases, starter
   layouts admins can fork
3. **Reports** — scheduled exports, view analytics, embedded analytics,
   AI-driven reporting surface
4. **Comments** — cross-dashboard activity stream (comments, reactions,
   alerting feed)
5. **Beheer** — admin console with ~9 governance tabs
6. **Catalog** — read-only browser of every registered widget type
7. **Instellingen** — personal preferences first; admin settings as a
   second tab when the user has rights

The AI assistant is **not** a menu item. It is a global launcher
(sidebar pill) reachable from every page, matching Nextcloud's unified
search pattern. Saved AI outputs flow into Reports.

### Design rules (apply to every new spec)

1. **Widget types are catalog entries, not menu items.** Any spec named
   `*-widget` (calendar, files, news, people, quicklinks, links,
   link-button, image, video, text-display, label, header, divider,
   container, menu, RSS, tiles, larping-skill, files-access,
   meeting-calendar, nc-dashboard-widget-proxy, etc.) registers in the
   **Catalog** and is instantiated on the **Dashboards** canvas via the
   widget-add-edit-modal. Widgets never get their own top-level slot.

2. **Catalog vs Picker are distinct surfaces.** The Catalog menu is a
   read-only **reference** browser (preview, configuration schema, sample
   data, spec link, "use this widget" button) for discovery and
   developer reference. The widget **picker** is the in-canvas
   add-widget modal. They share the underlying registry but are not the
   same surface — specs must declare which one they extend.

3. **Per-dashboard features live on canvas chrome; aggregations live in
   Comments or Reports.** Specs like `dashboard-comments`,
   `dashboard-reactions`, and `widget-alerting` always have two homes:
   the per-dashboard interaction surface (top bar, side panel, widget
   context menu) and the cross-dashboard inbox (Comments tab,
   Reactions tab, Alerting tab). This keeps the canvas clean while
   giving users one place to triage activity across all their
   dashboards.

4. **Governance is grouped by topic in Beheer, not by spec.** Beheer
   uses ~9 tabs organised by the question an admin is asking
   (Navigation, Groups & Routing, Roles & Permissions, Sharing &
   Publication, Bulk, Versioning & Audit, Compliance & Security,
   Mobile, Collaboration, Operations) — not by spec name. A new
   compliance spec joins the Compliance & Security tab; it does **not**
   promote to a top-level menu. Tab count is the budget: if a topic
   does not fit an existing tab, the proposal must justify a new tab
   before adding it.

5. **The AI assistant is a global launcher, not a menu.** mydash AI
   capabilities (`mydash-ai-dashboard-assistant`,
   `nc-unified-search-integration`) attach to a sidebar pill that is
   present on every page. Saved outputs (summaries, scheduled AI
   reports) flow into the **Reports** menu so users can find them
   later, but the assistant entry-point itself is never promoted to a
   navigation slot.

6. **Personal vs admin settings split inside Instellingen.** A user
   always lands on a Personal tab (theme, default dashboard, language,
   notification prefs, resource-uploads). Admin settings
   (`runtime-shell`, `initial-state-contract`, `resource-serving`,
   `active-dashboard-resolution` overrides) appear as a second tab
   **only when the viewing user has the right permission level** —
   never as a separate top-level menu. This keeps Instellingen as one
   coherent destination per user role.

7. **Developer/ops surface is inside Beheer, not its own menu.** Unlike
   the openregister fleet (where API & Docs is a separate top-level
   menu for integrators), mydash has no integrator audience: ops
   tooling (`prometheus-metrics`, `cli-commands`,
   `newman-integration-suite`, `setup-wizard`, `spec-annotation-pass`,
   `legacy-widget-bridge` configuration) lives on the Beheer
   **Operations** tab. The developer-facing reference for widget types
   is the Catalog menu; everything else collapses into Beheer.

### Process implications

- Every OpenSpec change proposal must declare placement (menu + tab/
  parent) and rationale, mirroring the mapping table convention in
  `/tmp/ia-mydash-openregister.md` section D.
- A proposal that requires a **new top-level menu** must be argued as
  an ADR amendment (this ADR), not slipped into a feature change.
- A proposal that requires a **new Beheer tab** must justify the tab
  budget (current ~9; new tab needs a topic that genuinely does not
  fit existing ones).
- A proposal that adds a widget type must extend the Catalog entry and
  the picker registration in the same change, never just one.

## Consequences

- Top-level navigation stays at 7 destinations with headroom — adding
  features does not erode the canvas-first experience.
- The Beheer tab budget (~9) becomes the natural shock-absorber for
  governance specs; admins find policies by topic instead of hunting
  spec names.
- End users (Dashboards / Catalog / Comments / Instellingen Personal)
  and admins (Beheer / Templates / Reports / Instellingen Admin tab)
  share the same navigation without bleeding into each other.
- The AI assistant can grow capabilities (summaries, exports, scheduled
  reports) without ever needing its own menu — saved artefacts flow
  into Reports.
- Catalog and Picker stay decoupled: the Catalog can carry richer
  reference content (specs, previews, sample data) without bloating
  the in-canvas picker.
- Per-dashboard vs aggregate-inbox duality is enforced for any spec
  about user-to-user interaction (comments, reactions, alerting), so
  triage UX is consistent across feature types.
- Future specs that try to introduce a new top-level menu or a new
  Beheer tab require explicit ADR-level review, which is the friction
  this ADR is meant to create.
- A separate developer-surface menu (the openregister "API & Docs"
  pattern) is **rejected** for mydash. If integrator demand ever
  emerges, that decision is re-opened as an ADR amendment.
