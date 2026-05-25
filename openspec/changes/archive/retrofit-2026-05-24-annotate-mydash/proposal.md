# Retrofit — annotate mydash against existing specs

Retroactive annotation of mydash legacy code against existing REQs in `openspec/specs/`. Two concurrent activities:

1. **Shorthand-to-canonical rewrite** — 25 PHP files under `lib/` carry the non-canonical `@spec capability:REQ-XXX` form. Per ADR-037, the canonical annotation is `@spec openspec/changes/<change>/tasks.md#task-N`. This change rewrites every shorthand occurrence to the canonical form.

2. **File-docblock annotation** — Bucket 1 files identified by `openspec/coverage-report.json` (generated 2026-05-24) are tagged at file-docblock level with `@spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-N` where missing.

No code logic changes. No spec deltas (all REQs already exist in `openspec/specs/`).

Source: `openspec/coverage-report.md` generated 2026-05-24 (Bucket 1 only).

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
