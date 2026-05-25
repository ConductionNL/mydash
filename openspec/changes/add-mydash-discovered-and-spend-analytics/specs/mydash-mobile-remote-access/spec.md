# Spec: mydash-mobile-remote-access

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, responsive-grid-breakpoints, runtime-shell, permissions, role-based-content, initial-state-contract; no cross-app runtime sources (this is a manifest-declaration capability)

## Purpose

Define the **manifest contract** for declaring widget mobile-readiness
so the mydash workspace can render a coherent mobile + remote
experience. The existing `responsive-grid-breakpoints` spec already
handles the grid-side breakpoint engine; this spec adds the **per-widget
declaration surface** in the app manifest (per ADR-024) and the
fallback behaviour when no widget on a dashboard declares itself
mobile-ready.

Three Specter acceptance themes resolve here:

- **Remote / mobile access** — surface decisions about which widgets
  render on small viewports (responsive declaration).
- **Session timeout** — surface the established Nextcloud session
  timeout state in the workspace shell, never a mydash-local
  timer.
- **Role-gated app & news access** — already covered by
  `role-based-content`; this spec adds the manifest declaration
  that flags mobile-ready widgets and the empty-state when none
  are present.

This is a **declarative capability** — no runtime data sources, no
new endpoints. PWA / offline-storage / service-worker work is OUT
of scope (deferred to a future capability).

Sourced from Specter draft `mobile-remote-access` (2 features:
remote/mobile access + curated app + news access).

## ADDED Requirements

### REQ-MRA-001: Every widget registry entry SHALL declare a `mobileReady` boolean

`src/constants/widgetRegistry.js` entries MUST extend the existing
registry contract (REQ-WDG-014) with a required `mobileReady: boolean`
field. The registry completeness test (REQ-WDG-023) MUST be updated
to assert every entry carries the field. Widgets that legitimately
cannot render on mobile (e.g. complex multi-column tables) MUST set
`mobileReady: false` explicitly — defaulting is not permitted.

#### Scenario: Registry entry missing the field fails the completeness test

- **GIVEN** a registry entry omits `mobileReady`
- **WHEN** `npm test` runs the completeness test
- **THEN** the test MUST fail with a message naming the type
  + the missing `mobileReady` field

#### Scenario: Every shipped widget declares the field

- **GIVEN** the canonical widget set (`calendar`, `container`,
  `divider`, `files`, `header`, `image`, `label`, `link`, `links`,
  `menu`, `nc-widget`, `news`, `people`, `quicklinks`, `text`, `tile`,
  `video`) plus the seven net-new widgets from this change set
- **WHEN** the completeness test inspects each entry
- **THEN** every entry MUST carry an explicit `mobileReady` boolean

### REQ-MRA-002: The `src/manifest.json` `widgets[]` array SHALL mirror the registry's `mobileReady` declaration

Every entry in `manifest.widgets[]` MUST carry `mobileReady: boolean`
matching the registry entry. The `npm run check:manifest` script
(per ADR-024 §5) MUST fail when the manifest and registry disagree
on a widget's `mobileReady` value. This keeps the manifest as the
admin-visible contract (admin tools read the manifest, not the
registry — admins can hide a widget per app per role per ADR-024
extension).

#### Scenario: Manifest declaration matches registry

- **GIVEN** the registry sets `tile.mobileReady = true`
- **WHEN** `npm run check:manifest` runs
- **THEN** the manifest entry for `tile` MUST also set
  `mobileReady: true`

#### Scenario: Mismatch fails the check

- **GIVEN** the registry sets `compliance-audit.mobileReady = false`
- **AND** the manifest sets `compliance-audit.mobileReady = true`
- **WHEN** `npm run check:manifest` runs
- **THEN** the check MUST fail with a diff message naming the
  conflicting type

### REQ-MRA-003: On the mobile breakpoint, widgets with `mobileReady: false` SHALL be hidden — not auto-stacked

When the viewport drops below the mobile breakpoint defined by
`responsive-grid-breakpoints` (currently 480 px), the runtime shell
MUST filter out widget placements whose registry entry has
`mobileReady: false`. The hidden widgets MUST NOT auto-stack — they
simply don't render. A small inline notice MUST appear at the top
of the workspace listing the count of hidden widgets and offering
a "view desktop layout" link (which the viewer can use to force
the desktop breakpoint at the cost of horizontal scroll).

#### Scenario: Mobile viewport hides non-ready widgets

- **GIVEN** a dashboard with 6 placements (4 mobile-ready, 2 not)
- **WHEN** the viewport is 375 px (mobile)
- **THEN** only the 4 mobile-ready widget DOM nodes MUST render
- **AND** the notice MUST display `2 widgets hidden on mobile`

#### Scenario: Desktop viewport renders all

- **GIVEN** the same dashboard
- **WHEN** the viewport is 1280 px
- **THEN** all 6 widgets MUST render normally
- **AND** the mobile notice MUST NOT appear

#### Scenario: Force desktop layout link

- **GIVEN** the mobile notice is visible
- **WHEN** the viewer clicks "view desktop layout"
- **THEN** the workspace MUST switch to the desktop grid layout
  (overriding the breakpoint) for the current session
- **AND** the hidden widgets MUST render

### REQ-MRA-004: When zero widgets on a dashboard are mobile-ready, the workspace SHALL render an empty-state — not a blank page

When every placement on the active dashboard has `mobileReady: false`
AND the viewport is mobile, the workspace MUST render an empty-state
naming the situation and pointing the viewer at the desktop-layout
override. The empty-state MUST NOT block the sidebar, dashboard
switcher, or any non-grid affordance.

#### Scenario: All non-mobile dashboard shows empty-state

- **GIVEN** a dashboard where all 4 placements have
  `mobileReady: false`
- **WHEN** the viewport is 375 px
- **THEN** the grid area MUST display the empty-state text
  `t('mydash', 'This dashboard has no mobile-ready widgets')`
  with a "view desktop layout" CTA
- **AND** the sidebar MUST remain usable

#### Scenario: Other affordances stay live

- **GIVEN** the empty-state is rendered
- **WHEN** the viewer opens the dashboard switcher
- **THEN** the switcher MUST function normally
- **AND** the viewer MAY navigate to another dashboard that has
  mobile-ready widgets

### REQ-MRA-005: The workspace shell SHALL surface the Nextcloud session timeout state — never a mydash-local timer (Specter acceptance)

The runtime shell (per `runtime-shell` REQ-SHELL-001) MUST observe
Nextcloud's session lifetime via the standard Nextcloud session
heartbeat. mydash MUST NOT implement an idle-timeout timer of its
own (Nextcloud already does this — implementing a parallel timer
would create competing logout behaviour). When the heartbeat
detects an expired session, the shell MUST surface Nextcloud's
re-authentication overlay; on successful re-auth the workspace
MUST resume without losing in-progress widget state.

#### Scenario: Session expires (Specter source)

- **GIVEN** a remote employee authenticated AND the session is
  idle past the Nextcloud-configured timeout
- **WHEN** the next heartbeat fires
- **THEN** Nextcloud's re-auth overlay MUST surface
- **AND** the mydash workspace MUST NOT auto-redirect to its own
  login route

#### Scenario: No mydash-local timer

- **GIVEN** the workspace shell source files
- **WHEN** scanned for `setTimeout` / `setInterval` calls that
  trigger logout, redirect, or session-end
- **THEN** zero matches MUST exist (session lifecycle stays with
  Nextcloud)

#### Scenario: Re-auth preserves in-progress state

- **GIVEN** a viewer is editing a widget's config when the session
  expires
- **WHEN** the re-auth overlay completes successfully
- **THEN** the widget edit modal MUST remain open with its
  in-progress fields preserved

### REQ-MRA-006: The curated-app + news surfaces SHALL be role-gated via `role-based-content` — never via a mydash-local policy table (Specter acceptance)

The Specter draft includes "curated list of relevant applications"
and "news feed" features for the mobile shell. These MUST be
satisfied by composing existing `role-based-content` rules with
existing widget types (`links-widget` / `quicklinks-widget` for
the app list; `news-widget` / `dashboard-rss-feeds` for news). mydash
MUST NOT define a separate "mobile app policy" or "mobile news
policy" table — the per-role visibility engine already exists.

#### Scenario: Curated app list comes from a configured widget (Specter source)

- **GIVEN** an admin has placed a `quicklinks-widget` on the
  mobile-default dashboard with `mobileReady: true` AND a
  `role-based-content` rule scoping it to the viewer's role
- **WHEN** the authenticated employee opens mydash on mobile
- **THEN** the curated app list MUST render via that widget

#### Scenario: News feed via existing news capability (Specter source)

- **GIVEN** a `news-widget` is placed AND scoped per role
- **WHEN** the employee opens the dashboard
- **THEN** the news feed MUST render via that widget — no new
  news plumbing in this capability

#### Scenario: No mydash-local mobile-policy table

- **GIVEN** the mydash migrations after this widget ships
- **WHEN** inspected
- **THEN** no migration introducing a "mobile policy" /
  "mobile app whitelist" / "mobile news policy" table MUST exist

## Non-Functional Requirements

- **Performance:** Breakpoint switches MUST re-render the grid
  within 200 ms (the GridStack engine handles this; the
  `mobileReady` filter is a single Array.filter() on render).
- **Accessibility:** The empty-state (REQ-MRA-004) MUST be
  screen-reader announced via `aria-live="polite"`. The "view
  desktop layout" CTA MUST carry an `aria-label` clarifying the
  consequence (horizontal scroll).
- **Localisation:** English + Dutch.
- **Touch:** Per Specter source — every mobile-ready widget MUST
  satisfy touch-target sizing per WCAG 2.5.5 (≥44 × 44 px).
  Enforcement happens widget-side via the existing
  `responsive-grid-breakpoints` mobile cell minimums; this spec
  does not duplicate the requirement.

## Out of scope

- **PWA / service worker** — installable web app, offline cache,
  push notifications. Deferred to a future capability; tracked
  separately.
- **Native mobile app** — Nextcloud's iOS / Android apps are
  out-of-scope; this capability targets the mobile web browser
  surface.
- **Offline-capable widgets** — any widget that wants to render
  without network MUST declare that separately in a future
  `mobileOffline` extension of this contract.

## Reuses (mydash)

- `widgets` (REQ-WDG-014, REQ-WDG-023 — registry contract +
  completeness test) — extended with `mobileReady`
- `responsive-grid-breakpoints` — owns the breakpoint engine
- `runtime-shell` — surfaces the session re-auth flow
- `role-based-content` — gates curated app + news per role
- `links-widget` / `quicklinks-widget` / `news-widget` /
  `dashboard-rss-feeds` — concrete widgets the role-based
  composition uses

## Standards & References

- ADR-024 — manifest declarations (this spec extends the
  `manifest.widgets[]` schema with `mobileReady`).
- ADR-007 + ADR-025 — i18n (every visible string keyed,
  available in nl + en).
- `feedback_mydash-no-or-dependency.md` — manifest-only spec, no
  runtime cross-app data; nothing to gate runtime against.
- Nextcloud session lifecycle — the authoritative session-timeout
  + heartbeat surface mydash defers to.
- WCAG 2.1 AA — `aria-live`, touch targets (2.5.5), focus order.
