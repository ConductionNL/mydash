# Coverage Report — mydash

Generated: 2026-04-24 09:45 UTC
Branch: development
Scanner: opsx-coverage-scan v1 (manual drive from Wilco's feature/skill-improvement branch)

> **Scanner note**: this run was a focused manual drive to test `/opsx-reverse-spec`
> downstream. Pass A classification was completed; Bucket 3 (reverse pass for
> unimplemented REQs) and Bucket 4 (ADR conformance sweep) were intentionally skipped
> to keep the test loop tight. The `coverage-report.json` sidecar is authoritative for
> `/opsx-reverse-spec` consumption.

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (no `@spec` tags in mydash yet) |
| plumbing | 9 | — (never tagged) |
| 1 — REQ matched | 61 | `/opsx-annotate mydash` |
| 2a — existing capability, no REQ | 4 (1 cluster: `dashboards`) | `/opsx-reverse-spec mydash --extend dashboards` |
| 2b — no capability owner | 5 (1 cluster: `legacy-widget-bridge`) | `/opsx-reverse-spec mydash --cluster legacy-widget-bridge` |
| 3 — REQ broken / unimplemented | — (skipped) | — |
| 4 — ADR conformance | — (skipped) | — |

## Bucket 1 — Ready to annotate

61 methods across 17 files, spanning all 9 capability specs (admin-settings, admin-templates, conditional-visibility, dashboards, grid-layout indirectly via dashboards, permissions, prometheus-metrics, tiles, widgets). See `coverage-report.json` for the full list. No method below 0.75 confidence; three `NEEDS-REVIEW` flags:

- `DashboardResolver::getEffectivePermissionLevel` (0.80) — duplicates a method of the same name in `PermissionService`; unclear which REQ owns which
- `MyDashAdmin::getForm` (0.80), `MyDashAdminSection::getID` (0.75) — admin UI registration likely deserves its own REQ (admin-settings has REQ-ASET-001..011 but UI boilerplate isn't called out)

## Bucket 2a — Existing capability, no REQ

### cluster: dashboards (4 methods)

- `lib/Controller/DashboardRequestValidator.php::checkCreatePermissions` — Enforces allowUserDashboards + allowMultipleDashboards admin settings on create
- `lib/Controller/DashboardRequestValidator.php::checkUpdatePermissions` — Enforces ownership + permission_level on update
- `lib/Controller/DashboardRequestValidator.php::resolveCreateParams` — Maps POST body to dashboard entity fields with defaults
- `lib/Controller/DashboardRequestValidator.php::buildUpdateData` — Extracts whitelisted update fields

The DASH spec references validation outcomes (scenarios like "Create a dashboard with invalid grid columns") but not the validator-class architecture. Candidate for `--extend dashboards` if we want REQs that pin validator behavior.

## Bucket 2b — No capability owner

### cluster: legacy-widget-bridge (5 methods) ⭐ TEST TARGET

File: `src/services/widgetBridge.js` (singleton). Zero spec references anywhere.

Integration layer that captures legacy Nextcloud widget callbacks
(`window.OCA.Dashboard.register`) and mounts them into the MyDash grid when a user
renders a v1 widget. Distinct from the `widgets` capability (discovery / placement /
items); this is purely about compatibility with the legacy v1 registration pattern.

- `interceptRegistration()` — Monkey-patches `window.OCA.Dashboard.register` and `registerStatus` on construction; captures appId→callback into internal Maps; preserves any existing registrar
- `mountWidget(widgetId, container, widgetData)` — Looks up a registered callback and invokes it against a DOM container; clears container first; catches + logs errors
- `mountStatusWidget(widgetId, container)` — Same as mountWidget but for status widgets (single-arg callback)
- `hasWidgetCallback(widgetId)` — Returns boolean: is a callback registered
- `getRegisteredWidgetIds()` — Returns array of all appIds that have registered

Exactly at the skill's 5-REQ cap. Self-contained. Clear observable behavior.

## Notes for the human reviewer

- **Classifier heuristic miss #1** — RuleEvaluatorService / VisibilityChecker /
  UserAttributeResolver were initially flagged Bucket 2b because their class names don't
  contain "conditional" or "visibility". Corrected to Bucket 1 (REQ-VIS-005..010 cover
  them by scenario content). **Signal**: when capability and class-name vocabulary
  diverge, fall back to scenario-noun matching before declaring Bucket 2.
- **Classifier heuristic miss #2** — DashboardResolver's 5 methods were flagged Bucket 2a
  "because no REQ mentions DashboardResolver". Corrected to Bucket 1 after finding that
  REQ-DASH-003 scenarios *explicitly name* `tryActivateExistingDashboard()` and
  `createDashboardFromTemplate()`. **Signal**: grep REQ scenarios for method names before
  declaring Bucket 2.
- **App is well-specced** — only 9 genuinely unmatched methods (4 Bucket 2a + 5 Bucket
  2b). Most method-level classification was clean.
