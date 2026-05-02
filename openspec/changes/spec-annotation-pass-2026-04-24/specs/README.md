# Spec delta — `@spec` annotation pass

This change is **annotation-only**. It does not modify any capability
spec's Requirements. It only adds PHPDoc `@spec` tags to 66 methods
across 17 files, pointing at the already-landed Requirements in:

- `openspec/specs/admin-settings/spec.md`
- `openspec/specs/admin-templates/spec.md`
- `openspec/specs/conditional-visibility/spec.md`
- `openspec/specs/dashboards/spec.md`
- `openspec/specs/grid-layout/spec.md`
- `openspec/specs/permissions/spec.md`
- `openspec/specs/prometheus-metrics/spec.md`
- `openspec/specs/tiles/spec.md`
- `openspec/specs/widgets/spec.md`
- `openspec/changes/archive/2026-04-24-retrofit-legacy-widget-bridge/specs/version-management/spec.md`
  (retrofit destination — Bucket 2b methods)

No new Requirements, no modifications. Hence this directory holds no
`<capability>/spec.md` delta file — this README documents the
annotation-only nature of the change explicitly.
