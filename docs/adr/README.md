---
sidebar_position: 4
---

# Architecture Decision Records

MyDash inherits its ADRs from the ConductionNL platform-wide set. There
are no MyDash-specific ADRs today — every rule that applies to MyDash
comes from the 23 org-wide records maintained in the **hydra** repo:

- Canonical location:
  [`ConductionNL/hydra/openspec/architecture/`](https://github.com/ConductionNL/hydra/tree/main/openspec/architecture)
- Local checkout:
  `hydra/openspec/architecture/adr-001-data-layer.md` through
  `adr-023-action-authorization.md`

## Why no app-level ADRs?

Earlier versions of each Conduction app repo carried stale copies of
the ADRs in `.claude/openspec/architecture/`. Those drifted away from
the hydra source of truth and triggered false-positive review findings
(observed on decidesk #71, 2026-04-19 — the app-level copy of ADR-004
said `fetch()`, hydra said `axios`). The stale copies were deleted
across every app repo; hydra is now the single source.

When Hydra's builder + reviewer containers operate on a MyDash PR, they
bundle the current hydra ADRs into the build image and feed them to
the agents as immutable context. So the rules every PR is measured
against live in hydra, not here.

## Quick index (per app-versions precedent)

| ADR | Topic |
|-----|-------|
| 001 | Data layer (OpenRegister, entities, mappers) |
| 002 | API design (REST, Common Ground) |
| 003 | Backend (PHP, DI, 3-layer) |
| 004 | Frontend (Vue, components, settings) |
| 005 | Security (auth, CORS, input validation) |
| 006 | Metrics & observability |
| 007 | i18n (English primary, Dutch required) |
| 008 | Testing (PHPUnit, Newman, Playwright) |
| 009 | Documentation |
| 010 | NL Design System |
| 011 | Schema standards (schema.org, DCAT) |
| 012 | Deduplication |
| 013 | Container pool |
| 014 | Licensing (EUPL-1.2) |
| 015 | Common patterns (rate-limit retry, axios) |
| 016 | Routes (`appinfo/routes.php` is the only path) |
| 017 | Component composition |
| 018 | Widget header actions |
| 019 | Integration registry |
| 020 | Gate scope to PR diff |
| 021 | Bounded fix scope by change shape |
| 022 | Apps consume OpenRegister abstractions |
| 023 | Action-level authorisation |

## Compliance

MyDash's current compliance posture against the 23 ADRs is tracked in
[adr-audit.md](../adr-audit.md). That file names per-ADR status
(PASS / PARTIAL / FAIL / N/A), evidence, and follow-up items.
