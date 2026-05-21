# CLI Commands Suite Specification

## Problem

MyDash requires a coherent, standardized operator interface for management tasks. Currently, there is no consistent command structure, global flag support, or operational helpers for dashboard inspection, sharing debugging, feed token management, and internationalization. Commands lack standardized error handling, audit logging, and machine-readable output, making scriptability, auditability, and discoverability difficult.

## Proposed Solution

Implement a CLI Commands Suite establishing:
- **Namespace convention**: All MyDash CLI commands MUST use the `mydash:` prefix with verb-noun or category-verb patterns
- **Global flags**: Every command supports `--quiet|-q`, `--json`, and `--no-interaction|-n`
- **Standardized exit codes**: 0 (success), 1 (generic error), 2 (invalid args), 3 (permission denied), 4 (not found), 5 (partial success)
- **JSON output schema**: Consistent `{success, exitCode, data, errors}` structure
- **Audit logging**: Every command writes a single audit line to `data/nextcloud.log`
- **New operational commands**:
  - Dashboard management: `mydash:dashboard:list`, `mydash:dashboard:show`, `mydash:dashboard:delete`, `mydash:dashboard:debug-share`
  - User management: `mydash:user:revoke-feed-token`
  - i18n management: `mydash:i18n:export-strings`, `mydash:i18n:migrate-language-structure`, `mydash:i18n:copy-navigation`

## Scope

This change covers:
- Core command infrastructure (CommandBase, CommandService, shared global flags, exit codes, audit logging)
- Eight new operator-facing commands for dashboard, user, and i18n operations
- Command registration via `appinfo/info.xml`
- Help text and discoverability (`php occ list mydash`)
- Comprehensive specification and implementation tasks

## Success Criteria

- All commands use the `mydash:` namespace
- Every command supports `--quiet`, `--json`, and `--no-interaction` flags
- Commands emit one of the six standard exit codes on every path
- All commands produce valid JSON when invoked with `--json`
- Every command writes an audit line to `data/nextcloud.log` on completion
- Dashboard commands enable inspection, debugging, and safe deletion with cascade support
- User commands respect capability dependencies (e.g., RSS feeds)
- i18n commands support batch language operations
- Help text is complete, discoverable, and includes realistic examples
