# Spec: mydash-enterprise-security-access

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, admin-roles, admin-settings, role-based-content, permissions; cross-app runtime sources: openregister (RBAC + audit-trail-immutable via GraphQL, read-only), Nextcloud user_saml provider info, Nextcloud TOTP/WebAuthn provider state

## Purpose

Surface enterprise security and access posture on a mydash dashboard
through a read-only widget (`mydash_security_access`). Three card
surfaces compose the widget:

- **Role assignments** — read over OR's RBAC abstraction (ADR-022
  table row "Authorization RBAC") via GraphQL.
- **SSO status** — read from Nextcloud's `user_saml` provider info.
- **MFA enforcement** — read from Nextcloud's TOTP / WebAuthn
  provider state.

The widget MUST be **read-only**. A "view as another role" preview
(Specter source) is permitted as an admin-side capability but every
mutation MUST happen in OR's RBAC admin UI; the widget surfaces
state and audits the impersonation event. mydash MUST NOT define a
local roles/permissions table or post writes to OR's RBAC.

Sourced from Specter draft `enterprise-security-access` (2 features:
enterprise guest/role perms + view-as-another-role).

## ADDED Requirements

### REQ-ESA-001: The system SHALL register a `mydash_security_access` widget type

The widget MUST appear in `src/constants/widgetRegistry.js` and the
unified Add Widget modal. The registry entry MUST carry the standard
fields and a soft
`requires.graphql: ['openregister.roles', 'openregister.auditTrail']`
declaration. The widget MUST be addable only by admins (mydash
admin permission per `admin-roles`).

#### Scenario: Widget registered

- **GIVEN** the registry completeness test
- **WHEN** it runs
- **THEN** `security-access` MUST appear in EXPECTED_TYPES

#### Scenario: Non-admin cannot add the widget

- **GIVEN** a viewer without the mydash admin permission
- **WHEN** they open the Add Widget modal type picker
- **THEN** `Security & access` MUST NOT appear in the list
- **AND** the gate MUST be enforced by `admin-roles` (server-side
  on the placement POST)

### REQ-ESA-002: The widget content shape SHALL describe scope + which cards to render

The placement persists `{type: 'security-access', content: {...}}` with:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `scope` | enum | Yes | `'tenant'` | `'tenant' \| 'group' \| 'self'` — bounds the queries |
| `groupIds` | string[] | When `scope === 'group'` | `[]` | Group filter |
| `showRolesCard` | boolean | No | `true` | Role-assignment card |
| `showSsoCard` | boolean | No | `true` | SSO status card |
| `showMfaCard` | boolean | No | `true` | MFA enforcement card |
| `viewAsEnabled` | boolean | No | `false` | Whether the "view as another role" preview is offered |

#### Scenario: Default placement validates

- **GIVEN** the content shape
- **WHEN** `{type: 'security-access', content: {scope: 'tenant'}}` is saved
- **THEN** validation MUST pass

#### Scenario: Group scope requires `groupIds`

- **GIVEN** `scope === 'group'` AND `groupIds = []`
- **WHEN** `validate()` runs
- **THEN** an error MUST surface ("Select at least one group")

### REQ-ESA-003: The role-assignments card SHALL consume OR's RBAC abstraction via GraphQL — never mirror a local role table

The card MUST query OR's `/graphql` for
`roles { name members { id displayName } scope }` filtered by
`scope` / `groupIds`. mydash MUST NOT define a local roles table,
MUST NOT issue write calls to OR's RBAC endpoints (anti-pattern per
ADR-022 "App-local RBAC on OR objects"), and MUST surface a link
to OR's RBAC admin UI for any mutation.

#### Scenario: Role overview renders from OR (Specter source)

- **GIVEN** an IT admin has assigned guest users to a workspace AND
  defined a custom role
- **WHEN** the widget renders
- **THEN** the card MUST display each role with its current member
  count and named scope
- **AND** the data MUST come from OR's `/graphql`

#### Scenario: Revocation reflected on next reload (Specter source)

- **GIVEN** a role's permissions are revoked in OR's admin UI
- **WHEN** the viewer reloads the dashboard
- **THEN** the card MUST reflect the revocation (the role no
  longer shows the revoked scope)

#### Scenario: No write to OR RBAC

- **GIVEN** the security-access widget source files
- **WHEN** scanned for HTTP `POST` / `PUT` / `DELETE` targeting
  `/apps/openregister/.*role` or `/apps/openregister/.*permission`
- **THEN** zero matches MUST exist

### REQ-ESA-004: The SSO status card SHALL consume Nextcloud's `user_saml` (or successor) provider info — read-only

The card MUST issue OCS calls (or use the public PHP API in the
mydash backend) to read SAML / OIDC provider state from
`user_saml` (or its successor app). The card surfaces:

- Provider name + protocol (SAML 2.0 / OIDC)
- Connected status (active / misconfigured / disabled)
- Last successful login timestamp (if available)

The widget MUST NOT define a local SSO settings table. If
`user_saml` is absent, the card MUST render an empty-state naming
the missing app.

#### Scenario: SSO card shows configured provider

- **GIVEN** `user_saml` is installed with one active SAML 2.0
  provider
- **WHEN** the card renders
- **THEN** it MUST display the provider name + "SAML 2.0" + active
  status

#### Scenario: SSO app absent

- **GIVEN** `user_saml` is not installed
- **WHEN** the card renders
- **THEN** it MUST display
  `t('mydash', 'SSO unavailable — user_saml not installed')`

### REQ-ESA-005: The MFA enforcement card SHALL consume Nextcloud's TOTP / WebAuthn provider state — read-only

The card MUST read the enabled second-factor providers (twofactor_totp,
twofactor_webauthn, twofactor_email) from Nextcloud's twofactor
infrastructure. It surfaces:

- Which providers are enabled
- Whether MFA enforcement is on (per Nextcloud admin settings)
- Per-group enforcement summary if `scope === 'group'`

The widget MUST NOT mutate twofactor settings; it links to
Nextcloud's `Settings → Security` UI for changes.

#### Scenario: MFA card shows providers + enforcement

- **GIVEN** `twofactor_totp` enabled AND MFA enforcement is on
  for group `admins`
- **WHEN** the card renders with `scope === 'tenant'`
- **THEN** the card MUST show `TOTP enabled` and
  `Enforcement: on for 1 group (admins)`

#### Scenario: No MFA providers

- **GIVEN** no twofactor app is enabled
- **WHEN** the card renders
- **THEN** the card MUST display the empty-state and the link to
  Nextcloud's Security settings

### REQ-ESA-006: "View as another role" SHALL be a read-only preview, audited via OR's audit-trail-immutable (Specter source)

When `viewAsEnabled === true`, an admin viewer MAY pick a target
role from a "View as" control. The dashboard MUST re-render the
current page applying the target role's `role-based-content`
conditional rules (per the existing `role-based-content` spec). A
persistent banner MUST show the assumed role with a one-click "Exit
view" control. The impersonation event (start and exit) MUST be
recorded in OR's audit-trail-immutable (sibling-app-side, via the
existing audit hooks). mydash MUST NOT define a local impersonation
event table.

#### Scenario: Role preview re-renders (Specter source)

- **GIVEN** an IT admin AND `viewAsEnabled === true`
- **WHEN** they select a target role from "View as"
- **THEN** the dashboard MUST re-render showing only widgets +
  data the target role can see (per `role-based-content`)

#### Scenario: Persistent banner with exit control (Specter source)

- **GIVEN** a "View as" preview is active
- **WHEN** the page renders
- **THEN** a persistent banner MUST display the assumed role with
  a one-click "Exit view" control

#### Scenario: Impersonation audited (Specter source)

- **GIVEN** the admin starts then exits a "View as" preview
- **WHEN** the events are inspected
- **THEN** both start + exit events MUST be present in OR's
  audit-trail (recorded via OR's audit hooks at the moment the
  RBAC context switches, NOT by a mydash-local writer)

#### Scenario: Session end auto-exits the preview (Specter source)

- **GIVEN** a "View as" preview is active
- **WHEN** the session ends (logout or timeout)
- **THEN** the dashboard MUST revert to the admin's own role on
  next sign-in (no preview persists across sessions)

## Non-Functional Requirements

- **Performance:** Each card MUST query independently; one slow
  card MUST NOT block siblings. Role counts SHOULD render within
  1.5 s on typical organisation sizes (<1000 roles).
- **Accessibility:** The "View as" banner MUST carry `role="status"`
  and adequate contrast; the Exit control MUST be keyboard
  reachable. Card data MUST be screen-reader navigable.
- **Localisation:** All labels in Dutch + English.
- **Privacy:** Member lists in the roles card MUST defer to OR's
  RBAC — a viewer who cannot see role membership in OR MUST NOT
  see it in the widget.

## Reuses (mydash)

- `widgets`, `widget-add-edit-modal`
- `admin-roles` — widget addability gate
- `role-based-content` — "View as" preview re-renders against
  this spec's existing conditional engine
- `permissions`, `conditional-visibility`

## Standards & References

- ADR-022 — OR abstractions consumed: Authorization RBAC (table
  row), Audit trail (immutable). Anti-pattern reference:
  "App-local RBAC on OR objects" (this widget MUST NOT violate it).
- ADR-023 — action-level authorisation (the widget surfaces the
  state of mappings defined there, never edits them).
- ADR-024 — manifest widget entry.
- `feedback_mydash-no-or-dependency.md`.
- Nextcloud Authentication apps: `user_saml`, `twofactor_totp`,
  `twofactor_webauthn`.
- WCAG 2.1 AA.
