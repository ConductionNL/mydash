# Tasks — role-based-content

> **Stage 1-3 complete on build/role-based-content (PR #95).** Native mydash
> persistence used in place of OpenRegister-based design (see PR description).
> Remaining unchecked tasks below cover deferred Stage 4 + follow-up work.

## Completed in Stage 1-3 (PR #95)

- [x] 1.1 Add `RoleFeaturePermission` schema to `lib/Settings/mydash_register.json` (REQ-RFP per design)
- [x] 1.2 Add `RoleLayoutDefault` schema to `lib/Settings/mydash_register.json`
- [x] 2.1 Create `lib/Service/RoleFeaturePermissionService.php` with `getAllowedWidgetIds`, `isWidgetAllowed`, `seedLayoutFromRoleDefaults`, `authorizeAdminObject` (stateless, DI-only per ADR-003)
- [x] 2.2 Multi-group resolution algorithm (REQ-RFP-005/006) — first-match base + union additional matches + deny-wins
- [x] 2.3 Fallback chain to `'default'` group then null (REQ-RFP-009)
- [x] 2.4 `seedLayoutFromRoleDefaults` only fires on dashboards with zero placements (REQ-RFP-002 s.3)
- [x] 3.1 Create `lib/Controller/RoleFeaturePermissionController.php` with the 4 admin endpoints (`#[AuthorizedAdminSetting]`)
- [x] 3.2 Register all 4 routes in `appinfo/routes.php` BEFORE wildcard `{slug}` routes
- [x] 4.1 `WidgetController::list()` filters by `getAllowedWidgetIds` when non-null (REQ-RFP-001/003)
- [x] 5.1 `DashboardResolver::tryCreateFromTemplate()` calls `seedLayoutFromRoleDefaults` when admin-template matching fails
- [x] 5.2 Zero-placements guard asserted in unit tests
- [x] 6.1 Settings/initial-state includes `allowedWidgets` (REQ-RFP-010), null when unconfigured
- [x] 6.2 Initial-state type/PHPDoc declares the new field
- [x] 7.1 `src/store/modules/roleFeaturePermission.js` via `createObjectStore` + `auditTrailsPlugin`, registered in `store.js`
- [x] 7.2 `src/store/modules/roleLayoutDefault.js`, registered in `store.js`
- [x] 7.3 Settings store exposes `allowedWidgets` from initial state
- [x] 8.1 Card library / picker filters by `allowedWidgets` (filtered out of DOM, NOT hidden via CSS)
- [x] 9.1 `RolePermissionsSection.vue` with `CnDataTable` + `CnFormDialog` + `CnDeleteDialog`, EUPL header, all strings via `t(appName, ...)`
- [x] 9.2 `RolePermissionsSection` wired into `src/views/AdminApp.vue`
- [x] 10.1 English translation keys in `l10n/en.json`
- [x] 10.2 Dutch (`nl`) parity in `l10n/nl.json` (zero key gaps vs English)
- [x] 11.1 `RoleFeaturePermissionServiceTest` table-driven coverage of every REQ-RFP scenario (single-group allow/deny, multi-group first-match, deny-wins, null fallback, `default` fallback, no-default null, seed creates placements, seed no-op on existing placements)
- [x] 15.1 `docs/role-based-content.md` admin guide (RoleFeaturePermission creation, group priority interaction, RoleLayoutDefault seeding)

## Tasks (Stage 4 / follow-up)

- [ ] Task 1: Dedup audit — search `openspec/specs/` for prior widget-level role filtering capability (vs `permissions` and `admin-templates`); grep `openregister/lib/Service/` + `lib/Service/` for `getAllowedWidgetIds`/`widgetPermission`/`roleFilter`; verify `@conduction/nextcloud-vue` does not already expose a role-filtered picker; record findings (even "no overlap") in a comment block at the top of `RoleFeaturePermissionService.php`
- [ ] Task 2: Seed data — add the 5 RoleFeaturePermission seed objects + 5 RoleLayoutDefault seed objects from design.md to `lib/Settings/mydash_register.json` under `components.objects[]` using the `@self` envelope; verify idempotency (re-running `ConfigurationService::importFromApp()` with `force:false` MUST NOT duplicate)
- [ ] Task 3: `WidgetController` getItems / per-widget content endpoints call `isWidgetAllowed($userId, $widgetId)` before delegating; return HTTP 403 `{"message":"Not authorized"}` AND write an audit-trail entry on denial (REQ-RFP-001 s.3 + REQ-RFP-006 s.2)
- [ ] Task 4: Audit-trail entry format — `AuditTrailService` (OpenRegister), `$user->getUID()` (NOT display name per ADR-005), `widgetId`, ISO timestamp, reason string `"role_permission_denied"` or `"interest_without_role"`
- [ ] Task 5: Frontend hardening — every `await store.action()` wrapped in `try/catch` with user-facing error feedback per ADR-004 (covers card-library + admin UI store calls)
- [ ] Task 6: Admin UI — add a RoleLayoutDefault section (`CnDataTable` + `CnFormDialog` + `CnDeleteDialog`) either as a second tab in `RolePermissionsSection` or as a separate `RoleLayoutDefaultsSection.vue` component
- [ ] Task 7: PHPUnit controller — `RoleFeaturePermissionControllerTest`: non-admin → 403; list returns all objects; save with valid body → 201; save with invalid body → 400 (static message, no stack trace)
- [ ] Task 8: PHPUnit widget controller — extend `WidgetControllerTest`: `allowedWidgets = null` → unchanged full list; `allowedWidgets = ["activity"]` → only activity returned; direct access to a restricted widget → 403 + audit entry written
- [ ] Task 9: Newman/Postman — add `tests/integration/` entries for all 5 new endpoints with happy-path (200/201) and error-path (403/400) scenarios per ADR-008; include a test asserting `GET /api/widgets` is filtered for a user whose group has a configured RoleFeaturePermission
- [ ] Task 10: Playwright — employee-role user does NOT see admin-only widget in card library (widget absent from DOM, not hidden); new manager-role user's seeded dashboard contains the correct widgets at the correct grid positions (REQ-RFP-001 s.1, REQ-RFP-002 s.1)
- [ ] Task 11: Smoke ADR-008 — `GET /api/role-feature-permissions` admin creds → 200 + array; `POST /api/role-feature-permissions` non-admin → 403; `GET /api/widgets` for configured-group user returns only allowed widgets; direct restricted-widget endpoint as unpermitted user → 403 `{"message":"Not authorized"}` (no stack trace, no internal path)
- [ ] Task 12: Documentation — add at least one screenshot of the admin role-permissions section to `docs/role-based-content.md`
- [ ] Task 13: Quality gates — `composer check:strict` clean on all new PHP; ESLint+Stylelint clean on new/modified Vue+JS; SPDX `@license`+`@copyright` in every new `lib/**/*.php`; no forbidden debug helpers (`var_dump`/`die`/`error_log`/`print_r`/`dd`); no stub code (no empty `run()` bodies, no "In a complete implementation" placeholders); `#[NoAdminRequired]` always paired with a per-object auth check; all 10 `hydra-gates` green
- [ ] Task 14: ADR-003 traceability — `@spec openspec/changes/role-based-content/tasks.md#task-N` PHPDoc tag present on every new class and public method

## Verification

`openspec validate` exits clean. Hydra gates 1-10 pass on the follow-up branch; Playwright + Newman gates per Tasks 9–11 green.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 7–8; Newman per Task 9; Playwright per Task 10. Smoke calls per Task 11.

## Documentation (company-wide ADR-010)

`docs/role-based-content.md` already exists; screenshot supplement per Task 12.

## i18n (company-wide ADR-005)

`l10n/en.json` + `l10n/nl.json` parity already achieved; any new admin-UI strings shipped in Task 6 follow the same convention.
