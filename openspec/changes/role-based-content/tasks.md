> **Stage 1-3 complete on build/role-based-content (PR #95).** Native mydash
> persistence used in place of OpenRegister-based design (see PR description).
> Tasks 0.x, 4.2-4.3, 8.2, 9.3, 11.2-11.3, 12.x-14.x, 15.2, 16.x deferred to
> Stage 4 / follow-up commits.

# Tasks — role-based-content

## 0. Deduplication check

- [ ] 0.1 Search `openspec/specs/` for any existing capability covering widget-level role
      filtering (distinct from `permissions` which covers per-dashboard edit rights and
      `admin-templates` which covers dashboard distribution). Document findings here.
- [ ] 0.2 Grep `openregister/lib/Service/` and `lib/Service/` for any existing
      `getAllowedWidgetIds`, `widgetPermission`, or `roleFilter` methods — confirm none exist.
- [ ] 0.3 Verify `@conduction/nextcloud-vue` does not already expose a role-filtered widget
      picker component that would make `RolePermissionsSection.vue` redundant.
- [ ] 0.4 Record findings (even "no overlap found") in a comment block at the top of
      `RoleFeaturePermissionService.php`.

## 1. OpenRegister schemas and seed data

- [x] 1.1 Add `RoleFeaturePermission` schema definition to `lib/Settings/mydash_register.json`
      (schema key `role-feature-permission`, register key `mydash`) with all properties from
      design.md: `name`, `description`, `groupId`, `allowedWidgets`, `deniedWidgets`,
      `priorityWeights`. Mark `name`, `groupId`, `allowedWidgets` as required. Use schema.org
      vocabulary per ADR-011.
- [x] 1.2 Add `RoleLayoutDefault` schema definition to `lib/Settings/mydash_register.json`
      (schema key `role-layout-default`) with properties: `name`, `groupId`, `widgetId`,
      `gridX`, `gridY`, `gridWidth`, `gridHeight`, `sortOrder`, `isCompulsory`, `description`.
      Mark `name`, `groupId`, `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`,
      `sortOrder` as required.
- [ ] 1.3 Add the 5 RoleFeaturePermission seed objects from design.md to
      `lib/Settings/mydash_register.json` under `components.objects[]` using the `@self`
      envelope (register, schema, slug per design.md).
- [ ] 1.4 Add the 5 RoleLayoutDefault seed objects from design.md to
      `lib/Settings/mydash_register.json` using the `@self` envelope.
- [ ] 1.5 Verify idempotency: re-running `ConfigurationService::importFromApp()` with
      `force: false` MUST NOT create duplicate objects (matching by slug).

## 2. Backend service

- [x] 2.1 Create `lib/Service/RoleFeaturePermissionService.php` with:
      - `@spec openspec/changes/role-based-content/tasks.md#task-2`
      - Constructor injection: `ObjectService $objectService`,
        `AdminSettingsService $adminSettingsService`, `IGroupManager $groupManager`,
        `LoggerInterface $logger`
      - `getAllowedWidgetIds(string $userId): ?array` — returns null (unconfigured) or the
        effective allowed-widget ID array (REQ-RFP-009 backwards-compat, REQ-RFP-005
        multi-group algorithm from design.md)
      - `isWidgetAllowed(string $userId, string $widgetId): bool` — convenience wrapper
      - `seedLayoutFromRoleDefaults(string $userId, object $dashboard): void` — reads
        RoleLayoutDefault objects for primary group, creates WidgetPlacement records
        (REQ-RFP-002)
      - `authorizeAdminObject(IUser $user): void` — throws `OCSForbiddenException` if not
        admin (ADR-005 pattern)
      - All methods MUST be stateless (no instance state between requests, ADR-003)
- [x] 2.2 Implement multi-group resolution algorithm per design.md §"Multi-group Resolution
      Algorithm": walk `group_order`, base set from first matching group, union additional
      matches, deny-wins rule (REQ-RFP-005, REQ-RFP-006).
- [x] 2.3 Implement fallback to `'default'` RoleFeaturePermission when no `group_order` match
      found, and null fallback (return all widgets) when no `'default'` object exists
      (REQ-RFP-009).
- [x] 2.4 In `seedLayoutFromRoleDefaults()`: only call when the dashboard has zero existing
      placements (guard against overwriting personal customisations, REQ-RFP-002 scenario 3).

## 3. Backend controller

- [x] 3.1 Create `lib/Controller/RoleFeaturePermissionController.php` with:
      - `@spec openspec/changes/role-based-content/tasks.md#task-3`
      - All methods annotated `#[AuthorizedAdminSetting(Application::APP_ID)]`
      - `listPermissions(): JSONResponse` — `GET /api/role-feature-permissions`
      - `savePermission(Request $request): JSONResponse` — `POST /api/role-feature-permissions`
      - `listLayoutDefaults(): JSONResponse` — `GET /api/role-layout-defaults`
      - `saveLayoutDefault(Request $request): JSONResponse` — `POST /api/role-layout-defaults`
      - Each method: thin (<10 lines), calls service, returns JSONResponse (ADR-003)
      - Error responses: static generic messages only — NEVER `$e->getMessage()` (ADR-015)
- [x] 3.2 Register all four routes in `appinfo/routes.php` before any wildcard `{slug}` route:
      - `GET  /api/role-feature-permissions`
      - `POST /api/role-feature-permissions`
      - `GET  /api/role-layout-defaults`
      - `POST /api/role-layout-defaults`

## 4. Extend existing widget controller

- [x] 4.1 In `lib/Controller/WidgetController.php` `list()` method: inject
      `RoleFeaturePermissionService` and call `getAllowedWidgetIds($userId)`; if the result is
      not null, filter the widget array to only those with IDs in the allowed set (REQ-RFP-001,
      REQ-RFP-003).
- [ ] 4.2 In `WidgetController` method(s) that serve widget feature content (e.g. `getItems()`):
      call `isWidgetAllowed($userId, $widgetId)` before delegating to the widget loader; return
      HTTP 403 with `{"message": "Not authorized"}` and write an audit entry if denied
      (REQ-RFP-001 scenario 3, REQ-RFP-006 scenario 2).
- [ ] 4.3 Audit entry format: use `AuditTrailService` (OpenRegister), record
      `$user->getUID()` (NOT display name, ADR-005), `widgetId`, ISO timestamp, reason string
      `"role_permission_denied"` or `"interest_without_role"` as applicable.

## 5. Extend dashboard resolver

- [x] 5.1 In `lib/Service/DashboardResolver.php` (or equivalent) `tryCreateFromTemplate()`:
      after all admin-template matching fails, call
      `RoleFeaturePermissionService::seedLayoutFromRoleDefaults()` if RoleLayoutDefault
      objects exist for the user's primary group (REQ-RFP-002).
- [x] 5.2 Verify the guard: `seedLayoutFromRoleDefaults()` MUST only run when the new
      dashboard has zero placements — assert this in the unit test (REQ-RFP-002 scenario 3).

## 6. Initial-state payload

- [x] 6.1 In the settings/initial-state controller (e.g. `SettingsController`): call
      `RoleFeaturePermissionService::getAllowedWidgetIds()` and include the result as
      `allowedWidgets` in the JSON response (REQ-RFP-010). Return `null` when unconfigured.
- [x] 6.2 Ensure the initial-state type definition / PHP doc reflects the new field so Psalm
      does not flag it as undeclared.

## 7. Frontend — store

- [x] 7.1 Create `src/store/modules/roleFeaturePermission.js`:
      `createObjectStore('role-feature-permission')` with `auditTrailsPlugin` (Pinia pattern,
      ADR-004). Register in `src/store/store.js` via `registerObjectType`.
- [x] 7.2 Create `src/store/modules/roleLayoutDefault.js`:
      `createObjectStore('role-layout-default')`. Register in `src/store/store.js`.
- [x] 7.3 Extend settings store: add `allowedWidgets: null` field, populated from the
      initial-state payload (REQ-RFP-010).

## 8. Frontend — card library filtering

- [x] 8.1 In the widget card library / picker component: read `allowedWidgets` from the
      settings store; if non-null, filter the widget list before rendering so that
      disallowed widgets are absent from the DOM entirely (not hidden via CSS) (REQ-RFP-003,
      non-functional accessibility requirement).
- [ ] 8.2 Wrap the store action call in `try/catch` with user-facing error feedback (ADR-004
      rule: EVERY `await store.action()` MUST be in try/catch).

## 9. Frontend — admin UI

- [x] 9.1 Create `src/components/RolePermissionsSection.vue` (scoped style, EUPL header):
      - Lists existing RoleFeaturePermission objects in a `CnDataTable`
      - Add button opens `CnFormDialog` (schema-driven form for RoleFeaturePermission)
      - Edit opens `CnFormDialog` pre-populated
      - Delete opens `CnDeleteDialog`
      - All user-visible strings via `t(appName, 'key')` — no hardcoded strings (ADR-007)
- [x] 9.2 Add `RolePermissionsSection` to `src/views/AdminApp.vue` beneath existing admin
      sections. Register the component in `components: {}` (ADR-004: every component used
      in template MUST be imported AND registered).
- [ ] 9.3 Add a RoleLayoutDefault section (`CnDataTable` + `CnFormDialog` + `CnDeleteDialog`)
      to the admin UI — either as a second tab within `RolePermissionsSection` or as a
      separate `RoleLayoutDefaultsSection.vue` component.

## 10. i18n

- [x] 10.1 Add English translation keys to `l10n/en.json` for all new user-facing strings:
      admin section titles, column headers, form labels, error messages (ADR-007 sentence case).
- [x] 10.2 Add Dutch (`nl`) translations to `l10n/nl.json` for every key added in 10.1.
      Both files MUST contain exactly the same keys with zero gaps.

## 11. Unit tests (PHPUnit)

- [x] 11.1 `tests/Unit/Service/RoleFeaturePermissionServiceTest.php` — table-driven tests
      covering every REQ-RFP scenario:
      - single group, allowed widget in list → allowed
      - single group, widget not in allowed list → denied
      - multi-group, first-match wins (REQ-RFP-005 scenario 1)
      - multi-group, deny-wins rule (REQ-RFP-005 scenario 2)
      - no RoleFeaturePermission exists → returns null (REQ-RFP-009)
      - group not in group_order → falls back to `'default'` group
      - no `'default'` group → returns null
      - `seedLayoutFromRoleDefaults()` with zero existing placements → creates placements
      - `seedLayoutFromRoleDefaults()` with existing placements → no-op (REQ-RFP-002 s.3)
- [ ] 11.2 `tests/Unit/Controller/RoleFeaturePermissionControllerTest.php` — at minimum:
      - non-admin request → 403
      - list returns all objects
      - save with valid body → 201
      - save with invalid body → 400 (static error message, no stack trace)
- [ ] 11.3 `tests/Unit/Controller/WidgetControllerTest.php` (extend existing or create):
      - `allowedWidgets = null` → full list returned unchanged
      - `allowedWidgets = ["activity"]` → only activity in response
      - direct access to restricted widget → 403 + audit entry written

## 12. Integration tests

- [ ] 12.1 Add Postman/Newman collection entries in `tests/integration/` covering all five new
      endpoints with happy-path (200/201) and error-path (403, 400) scenarios (ADR-008).
- [ ] 12.2 Include a test asserting `GET /api/widgets` returns the filtered list when a
      RoleFeaturePermission exists for the test user's group.

## 13. Browser / spec scenarios

- [ ] 13.1 Add Playwright test verifying REQ-RFP-001 scenario 1: employee-role user does not
      see admin-only widget in card library (widget absent from DOM, not merely hidden).
- [ ] 13.2 Add Playwright test verifying REQ-RFP-002 scenario 1: new manager-role user's
      seeded dashboard contains the correct widgets at the correct grid positions.

## 14. Smoke testing (ADR-008)

- [ ] 14.1 Call `GET /api/role-feature-permissions` with admin credentials — verify 200 + array.
- [ ] 14.2 Call `POST /api/role-feature-permissions` with non-admin user — verify 403.
- [ ] 14.3 Call `GET /api/widgets` as a user whose group has a configured RoleFeaturePermission
      — verify only allowed widgets are returned.
- [ ] 14.4 Attempt direct access to a restricted widget endpoint as an unpermitted user —
      verify 403 response with `{"message": "Not authorized"}` (no stack trace, no internal
      path in response body).

## 15. Documentation

- [x] 15.1 Add `docs/role-based-content.md` describing the feature for IT admins: how to
      create RoleFeaturePermission objects, how group priority interacts with widget filtering,
      how RoleLayoutDefault seeds new users (ADR-009).
- [ ] 15.2 Include at least one screenshot of the admin UI role-permissions section.

## 16. Quality gates

- [ ] 16.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) on all new PHP files.
- [ ] 16.2 ESLint + Stylelint clean on all new / modified Vue and JS files.
- [ ] 16.3 SPDX `@license` + `@copyright` PHPDoc tags present in every new `lib/**/*.php` file
      (`gate-spdx` / `hydra-gate-spdx` must pass).
- [ ] 16.4 No forbidden debug helpers (`var_dump`, `die`, `error_log`, `print_r`, `dd`)
      (`hydra-gate-forbidden-patterns` must pass).
- [ ] 16.5 No stub code — no empty `run()` bodies, no "In a complete implementation" comments
      (`hydra-gate-stub-scan` must pass).
- [ ] 16.6 No `#[NoAdminRequired]` without a per-object auth check (all new endpoints use
      `#[AuthorizedAdminSetting]` — confirm `hydra-gate-no-admin-idor` and
      `hydra-gate-route-auth` pass).
- [ ] 16.7 Run all 10 `hydra-gates` locally (`/hydra-gates`) before opening PR.
- [ ] 16.8 `@spec openspec/changes/role-based-content/tasks.md#task-N` PHPDoc tag present on
      every new class and public method (ADR-003 spec traceability).
