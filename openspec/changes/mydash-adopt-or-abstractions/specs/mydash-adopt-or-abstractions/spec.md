---
status: draft
---

# MyDash — adopt OR abstractions

## Purpose

Specify the requirements for MyDash's adoption of the
`@conduction/nextcloud-vue` app-manifest contract and the
runtime-only consumption pattern for OpenRegister data, while
explicitly forbidding install-time OR / OC dependencies.

## ADDED Requirements

### Requirement: MyDash MUST ship an architectural manifest at `src/manifest.json`

MyDash MUST add `src/manifest.json` conforming to the JSON Schema
published by `@conduction/nextcloud-vue` at
`src/schemas/app-manifest.schema.json`. The manifest MUST be loaded
via `useAppManifest('mydash', bundledManifest)` in `src/main.js`.

The manifest MUST set:
- `$schema` to the published nc-vue schema URL (for editor
  validation)
- `version` to a semver string, bumped on shape change
- `dependencies: []` (explicit empty array)
- a `menu` array with at least the Dashboards entry
- a `pages` array including the per-dashboard page
  (`type: "dashboard"`)

#### Scenario: Manifest loads on app boot

- GIVEN MyDash is installed and enabled
- AND a user navigates to `/index.php/apps/mydash`
- WHEN `src/main.js` runs
- THEN `useAppManifest('mydash', bundledManifest)` MUST be called
  before the vue-router instance mounts
- AND the bundled manifest MUST be the immediate value of the
  reactive `manifest` ref returned by the composable
- AND the loader MUST attempt an async fetch of
  `/index.php/apps/mydash/api/manifest`
- AND on any non-200 response the loader MUST silently fall back to
  the bundled value with a `console.warn` log entry

#### Scenario: Manifest validation fails build

- GIVEN a developer edits `src/manifest.json` and introduces an
  unknown `type` value (e.g. `type: "kanban"`)
- WHEN the developer runs `npm run check:manifest`
- THEN the script MUST exit non-zero
- AND the error MUST identify the offending JSON path
- AND CI MUST fail on the same script

#### Scenario: Manifest declares no install-time OR dependency

- GIVEN the file `src/manifest.json`
- WHEN `manifest.dependencies` is read
- THEN it MUST be an empty array `[]`
- AND no review MAY add `"openregister"`, `"openconnector"`, or any
  other Conduction app ID to the array

### Requirement: MyDash MUST NOT declare an install-time dependency on OpenRegister or OpenConnector

This is a structural policy. MyDash MUST be installable and runnable
on a Nextcloud instance that has neither OR nor OC enabled.

#### Scenario: Install on OR-less Nextcloud

- GIVEN a Nextcloud install with OR not present and OC not present
- WHEN an admin enables MyDash via the apps page
- THEN the enable action MUST succeed
- AND the MyDash menu entry MUST appear
- AND opening MyDash MUST render the dashboards index without error
- AND any pre-installed dashboards that reference OR data MUST
  render with a documented empty state (per the runtime consumption
  requirement below)

#### Scenario: appinfo.xml dependency check

- GIVEN `appinfo/info.xml`
- WHEN reading `<dependencies>` and `<types>` blocks
- THEN no `<dependencies><app>openregister</app>...</app>` or
  `openconnector` MUST be present
- AND no soft-fail "recommended app" string MUST imply that OR is
  required

#### Scenario: Composer dependency check

- GIVEN `composer.json`
- WHEN reading `require` and `require-dev`
- THEN no `conduction/openregister*` package MUST be required
- AND no transitive PHP class import path MUST resolve through OR's
  namespace (`OCA\OpenRegister\...`) outside of optional runtime
  service-locator lookups

### Requirement: MyDash widgets that surface OR data MUST feature-detect OR at runtime

Any MyDash widget that reads or writes OR objects MUST guard the
call with `useOrFeatureDetect()` and render a documented empty
state when OR is absent or returns 5xx.

#### Scenario: OR-backed widget on OR-less install

- GIVEN OR is not installed on the Nextcloud instance
- AND an operator has placed an OR-backed widget on a dashboard
- WHEN the dashboard renders
- THEN `useOrFeatureDetect()` MUST return `enabled.value === false`
- AND the widget MUST render the empty state defined in
  `docs/widgets/or-data.md`
- AND no network call to `/index.php/apps/openregister/api/...`
  MUST be issued
- AND no console error MUST be raised

#### Scenario: OR returns 5xx

- GIVEN OR is installed and enabled
- AND a transient OR error causes
  `GET /index.php/apps/openregister/api/objects/{r}/{s}` to return
  503
- WHEN an OR-backed widget polls
- THEN the widget MUST render the empty state with an
  `aria-live="polite"` "Temporarily unavailable" message
- AND MUST retry on the next poll interval (default 60 s)
- AND MUST NOT crash the dashboard

### Requirement: MyDash widgets MUST pass `?_lang=` when fetching translatable OR data

When a widget fetches an OR object that exposes translatable
properties, the request URL MUST include the `?_lang={BCP47}` query
parameter set to the user's current Nextcloud locale.

This is a downstream consumer of
`openregister/openspec/changes/i18n-api-language-negotiation/`.

#### Scenario: Locale stamping on OR fetch

- GIVEN the user's Nextcloud locale is `en_GB`
- AND a widget fetches OR object `register=cases, schema=melding,
  uuid=abc`
- WHEN the request is built
- THEN the URL MUST be
  `/index.php/apps/openregister/api/objects/cases/melding/abc?_lang=en`
- AND no `Accept-Language` header MUST be relied upon as the sole
  signal

#### Scenario: Translation target on OR write

- GIVEN the user is editing an OR object's `title` property in
  English from a MyDash dashboard
- WHEN the widget PATCHes the object
- THEN the request MUST include the header
  `X-Translation-Target-Language: en`
- AND the body MUST send the English string under the `title` key
  without language wrapping

### Requirement: MyDash widgets MUST consume `useTenantContext()` from nc-vue when surfacing tenant-scoped OR data

Once the `multi-tenancy-context` change in
`nextcloud-vue/openspec/changes/` is released in a versioned
package, MyDash widgets MUST adopt the composable to refetch on
tenant switch and to stamp `X-OpenRegister-Organisation` on writes.

#### Scenario: Tenant switch refetches widget data

- GIVEN a dashboard with two OR-backed widgets and the user is
  active in tenant A
- WHEN the user switches to tenant B via the nc-vue tenant switcher
- THEN both widgets MUST detect the change via
  `useTenantContext().activeOrganisationUuid`
- AND clear their cached collections
- AND refetch their data using B's session

#### Scenario: Pre-release fallback

- GIVEN nc-vue does not yet export `useTenantContext`
- WHEN MyDash imports the composable
- THEN the import MUST be guarded with try/catch (or feature
  detection) so that absence does not crash the app
- AND the widget MUST behave as if a single-tenant install (no
  refetch on switch, no header stamping)

### Requirement: Dashboard sharing MUST keep the local permission model

MyDash dashboard sharing MUST continue to use the
`oc_mydash_dashboards.permissions` column with values
`view_only`, `add_only`, `full`. Sharing MUST work without OR.

OR per-object RBAC delegation MAY be wired as an OPTIONAL runtime
delegation when a dashboard's `permissions.delegate` field
references an OR object UUID and OR is enabled.

#### Scenario: Sharing on OR-less install

- GIVEN OR is not installed
- AND a dashboard owner shares with permission level `add_only` to
  group `kcc-team`
- WHEN a `kcc-team` member opens the dashboard
- THEN they MUST see the dashboard
- AND they MUST be able to add new widgets
- AND they MUST NOT be able to delete the dashboard or change its
  permissions

#### Scenario: Optional delegation when OR enabled

- GIVEN OR is enabled
- AND a dashboard's `permissions.delegate.objectUuid = "abc-123"`
  (referencing an OR object)
- WHEN a member of group `kcc-team` opens the dashboard
- THEN MyDash MUST call
  `GET /index.php/apps/openregister/api/objects/abc-123/can?action=read`
- AND MUST AND the OR result with the local `view_only` check
- AND MUST render the dashboard only if both pass
- AND if the OR call fails, MUST fall back to the local check
  silently

### Requirement: Admin templates MUST persist in `oc_mydash_admin_settings`

Admin dashboard templates (the named, exportable dashboard
configurations admins distribute to groups) MUST persist in the
local `oc_mydash_admin_settings` table.

Admin templates MUST NOT be persisted in OR.

#### Scenario: Template persistence

- GIVEN an admin creates a template `Service Desk default v2`
- WHEN the admin clicks save
- THEN the template MUST insert one row into
  `oc_mydash_admin_settings` with `key = "template:service-desk-default-v2"`
  and a JSON blob in `value`
- AND no row MUST be inserted into any OR table
- AND no call to OR's `/api/objects/...` MUST be issued

#### Scenario: Template export filename

- GIVEN an admin exports the template `Service Desk default v2`
- WHEN the file is generated
- THEN the filename MUST match
  `lib/Service/FileService.php::FILENAME_PATTERN`
- AND the file MUST be a JSON document with the template body
- AND on import, a filename that does not match the pattern MUST
  be rejected with a 400 response

### Requirement: ColumnTypeRegistry constants document their non-OR scope

`lib/Db/ColumnTypeRegistry.php` MUST carry a class-level docblock
explaining that its `TYPE_INTEGER`, `TYPE_BOOLEAN`, `TYPE_STRING`
constants model UI rendering affordances and are deliberately
independent of JSON-schema `type` semantics.

#### Scenario: Future audit silenced

- GIVEN a future OR-abstraction audit
- WHEN it scans `lib/Db/ColumnTypeRegistry.php` for "hardcoded
  constants"
- THEN the docblock MUST satisfy the audit's "documented rationale"
  exemption
- AND the audit MUST NOT re-flag the constants

### Requirement: AdminSetting keys MUST live under a single typed list

The eight `KEY_*` admin-config keys in `lib/Db/AdminSetting.php`
MUST be collected into a single `AdminSettingKey` const-list (or
PHP 8.1 `enum`). Existing constants MAY be kept as aliases for BC.

#### Scenario: Key list is single-source-of-truth

- GIVEN the `AdminSettingKey` const-list
- WHEN a new key is needed
- THEN it MUST be added to the list and to the docblock
- AND no other file in `lib/` MUST define a duplicate string literal
  for the same logical key

### Requirement: FILENAME_PATTERN regex MUST be a named class constant with a unit test

`lib/Service/FileService.php::FILENAME_PATTERN` MUST be:

- a named class constant (not an inline regex literal at line 62)
- documented in the docblock with the accepted/rejected sets
- covered by a unit test pinning at least:
  - allowed: `dashboard-export.json`, `template_v2.json`,
    `template-with-dashes.json`
  - rejected: `../etc/passwd`, `name.with.two.dots.json`,
    paths containing slashes (`a/b.json`), paths starting with `.`

#### Scenario: Path traversal rejected

- GIVEN `FILENAME_PATTERN`
- WHEN the regex matches `../etc/passwd`
- THEN the result MUST be no match
- AND the unit test MUST assert `false`

#### Scenario: Allowed filename

- GIVEN `FILENAME_PATTERN`
- WHEN the regex matches `template_v2.json`
- THEN the result MUST be a match
- AND the unit test MUST assert `true`
