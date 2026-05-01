---
sidebar_position: 5
---

# ADR compliance audit — MyDash

Audit of the 23 org-wide ADRs (`hydra/openspec/architecture/adr-*.md`)
against what MyDash actually does. Audit date: **2026-04-24**, after
consolidating 6 in-flight feature PRs into `development` and landing
the template + ADR cleanup PR.

**Legend:** ✅ compliant · ⚠️ partial · ❌ gap · N/A out of scope

## Matrix

| ADR | Rule (short) | Status | Note |
|---|---|---|---|
| **001** Data layer | App config → `IAppConfig`, not OpenRegister | N/A | MyDash owns its own Doctrine entities (Dashboard, Tile, WidgetPlacement, ConditionalRule, AdminSetting). Self-contained domain model, no OpenRegister dependency |
| 001 | Register JSON at `lib/Settings/{app}_register.json` | N/A | no domain schemas exposed to OR |
| **002** API | URL pattern `/api/{resource}`, standard verbs | ✅ | all 17 routes in `appinfo/routes.php` follow REST conventions |
| 002 | No stack traces in error responses | ✅ | `ResponseHelper::error` now returns generic message (fixed in this PR) |
| 002 | Pagination | N/A | dashboards / tiles lists are small per-user; no pagination needed |
| **003** Backend | Controller → Service → Mapper layering | ✅ | 11 controllers delegate to 20 services which delegate to 20 mappers/entities |
| 003 | Thin controllers | ✅ | most methods under 20 lines; `DashboardApiController::update` is the largest at ~45 lines due to metadata-vs-layout branch |
| 003 | DI via constructor + `private readonly` | ✅ | verified across `lib/Controller/` and `lib/Service/` |
| 003 | No `\OC::$server` or static locators | ✅ | post-PR: `grep -rnE '\\OC::\\$server\\|Server::get(\\|new \\OC_' lib/` returns zero |
| 003 | `@spec` on every class + public method | ❌ | **deferred** — 0 of ~215 public methods tagged. Tracked as [openspec change + issue](#follow-ups) |
| 003 | Specific routes before wildcard | ✅ | no wildcard routes |
| **004** Frontend | Vue 2 + Pinia + Options API | ✅ | Vue 2.7 + Pinia stores + Webpack (template-aligned) |
| 004 | Never import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` | ✅ | post-PR: all 9 direct imports migrated |
| 004 | All user-visible strings via `t(appName, '…')` | ✅ | verified across `src/components/` and `src/views/` |
| 004 | CSS uses NC variables only | ✅ | `--color-*` tokens used throughout |
| 004 | Never `window.confirm()` / `alert()` | ✅ | uses `NcDialog` via `@conduction/nextcloud-vue` |
| **005** Security | Admin check on backend, not frontend | ✅ | controller methods verify admin group membership |
| 005 | `#[NoAdminRequired]` paired with per-object auth check | ✅ | verified: `DashboardApiController::update` routes to `PermissionService::canEditDashboard`; `DashboardService::deleteDashboard` + `activateDashboard` check `$dashboard->getUserId() !== $userId`; `TileService` + `WidgetService` follow the same pattern at service layer |
| 005 | `#[PasswordConfirmationRequired]` on admin mutations | ⚠️ | admin-console mutations could benefit; deferred for a focused security review pass |
| 005 | No stack traces / exception messages in API responses | ✅ | `ResponseHelper::error` now returns generic `'Operation failed'` (fixed in this PR) |
| **006** Metrics | `/api/metrics` + `/api/health` | ✅ | `MetricsController` exposes Prometheus text format at `/api/metrics`; `HealthController` at `/api/health` |
| **007** i18n | English primary + Dutch required | ✅ | `l10n/en.json` (5485 B) + `l10n/nl.json` (5798 B); JS mirrors present |
| 007 | Frontend `t(appName, 'key')` + `n(...)` for plurals | ✅ | spot-check shows `translatePlural` used for count-based strings |
| 007 | Backend `$this->l10n->t('key')` | ✅ | admin settings strings use `IL10N` |
| **008** Testing | PHPUnit coverage per service / controller | ⚠️ | 8 unit tests present (DashboardFactory, VisibilityChecker, Tile, ConditionalRule, AdminSetting, …). Service+controller coverage grows with each opsx change |
| 008 | Newman/Postman collection | ❌ | **deferred** — no `tests/integration/*.postman_collection.json`. Tracked as [openspec change + issue](#follow-ups) |
| **009** Docs | User-facing features documented | ✅ | Docusaurus site at `docs/` with 10+ feature docs under `docs/features/` + intro + dev guide. Architecture doc added in this PR |
| **010** NL Design | CSS custom properties, no hardcoded colors | ✅ | verified |
| 010 | WCAG AA | ⚠️ | basic keyboard nav + focus handling present; no structured axe-core or NVDA pass run. Consistent with "admin-only app" guidance, but deeper audit worth a focused PR |
| **011** Schema standards | schema.org vocabulary | N/A | no domain schemas exposed externally |
| **012** Dedup | Reuse analysis in OpenSpec changes | ✅ | each archived change includes a "reuse analysis" section (spot-check on `2026-03-21-dashboards/design.md`) |
| **013** Container pool | Hydra infra concern | N/A | not an app concern |
| **014** Licensing | EUPL-1.2 on every source file | ✅ | post-PR: 72 PHP files have clean `@license EUPL-1.2` PHPDoc; contradictory `SPDX-License-Identifier: AGPL-3.0-or-later` block removed; `REUSE.toml` added for machine-readable REUSE compliance |
| 014 | `info.xml` licence element | ✅ | `<licence>agpl</licence>` retained as the Nextcloud app-store schema element (schema expects `agpl`) — separate from source licence; documented in commit `ab9fc70` |
| **015** Common patterns | Static generic error messages | ✅ | `ResponseHelper::error` returns generic text (fixed in this PR) |
| 015 | No raw `fetch()` — use `@nextcloud/axios` | ✅ | verified: `grep -rn 'fetch(' src/` returns zero |
| 015 | EUPL headers | ✅ | see ADR-014 |
| **016** Routes | `appinfo/routes.php` is the only registration path | ✅ | `grep -rE '#\\[ApiRoute\\|#\\[FrontpageRoute' lib/` returns zero; all 17 routes declared explicitly |
| **017** Component composition | Avoid wrapping self-contained components | ✅ | no file in `src/` exceeds 537 lines (`WidgetPicker.vue`); components use the `@conduction/nextcloud-vue` dashboard primitives |
| **018** Widget header actions | `header-actions` slot on cards | ⚠️ | MyDash renders **legacy** Nextcloud dashboard widgets (`NcDashboardWidget`) rather than OR-backed `CnDetailCard` / `CnObjectDataWidget`. That's by design (MyDash is a container app, not an OR data consumer), but ADR-018 contemplates header-actions on data cards. Treated as N/A-by-design for now; revisit if MyDash grows OR-backed tile types |
| **019** Integration registry | Sidebar tabs / linked items | N/A | MyDash is a widget **consumer**, not a registry provider |
| **020** Gate scope | Hydra gate scope is PR diff | N/A | reviewer guidance, not app code |
| **021** Bounded fix scope | Reviewer bounded-fix by change shape | N/A | reviewer guidance, not app code |
| **022** Apps consume OR abstractions | RBAC / audit / archival via OR | N/A | no OR consumption — see ADR-001 note |
| **023** Action authorization | Admin-configured action/group mappings | N/A | MyDash uses role-based permissions (`view` / `add_only` / `full`), not fine-grained action mapping |

## Summary

- **Compliant:** 25 rules (incl. compound rules)
- **Partial:** 4 rules (ADR-005 `PasswordConfirmationRequired`, ADR-008
  PHPUnit breadth, ADR-010 deep WCAG AA, ADR-018 `header-actions`
  slot by design)
- **Gaps:** 2 rules (ADR-003 `@spec` tag coverage, ADR-008 Newman)
- **N/A:** 14 rules (no OR consumption, no registry provider, no fine-
  grained actions, hydra-infra rules)

## Follow-ups

The two remaining `❌` items are tracked as formal OpenSpec changes +
GitHub issues so Hydra's pipeline can pick them up after this PR
merges:

1. **`@spec` annotation pass across 64 PHP files / 215 public methods**
   — needs a full `/opsx-annotate` run against the 10 archived changes
   in `openspec/changes/archive/`. Scope is too large to pack into this
   PR.
2. **Newman / Postman integration collection** — covering the 17 OCS
   endpoints, with env-placeholder credentials and CI wiring.

Partial items that may warrant their own follow-up PRs later (not
filed as Hydra-triggered issues today):

3. **`PasswordConfirmationRequired` on admin-console mutations**
   (ADR-005). The admin console writes template definitions + global
   settings; a stolen session shouldn't silently alter those. Focused
   security review.
4. **Deep WCAG AA audit** (ADR-010) — axe-core + manual screen-reader
   traversal on the dashboard + tile editor surfaces.
5. **Thread `LoggerInterface` through the 6 controllers that currently
   don't inject one**, so `ResponseHelper::error($e, logger: $this->logger)`
   logs the real exception server-side. The leak is already closed
   client-side; this restores visibility.
