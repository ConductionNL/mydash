# CLI Commands Suite

## Why

MyDash operators (sysadmins, support engineers, automation scripts) need a consolidated, standardized interface for common management tasks: listing, inspecting, and deleting dashboards; debugging sharing configuration; managing user feed tokens; handling translatable strings; and executing language migrations. Today, CLI operations are scattered across multiple ad-hoc commands or require direct database access. This change establishes a coherent `mydash:` namespace prefix and consistent global flags (`--quiet`, `--json`, `--no-interaction`) across ALL MyDash CLI commands (both pre-existing and new), plus introduces new operational helpers for dashboard inspection, sharing debug, i18n, and feed token revocation.

## What Changes

- Establish the `mydash:` namespace prefix as the canonical CLI surface for MyDash. All commands follow the pattern `mydash:<verb>` or `mydash:<verb>:<noun>` (e.g., `mydash:export`, `mydash:dashboard:list`, `mydash:cleanup:scan`).
- Provide three consistent global flags on every MyDash command:
  - `--quiet|-q` — suppress non-essential output (errors still print to stderr).
  - `--json` — emit machine-readable JSON output (suitable for scripting and log aggregation).
  - `--no-interaction|-n` — skip confirmation prompts (for CI/automation use).
- Add new commands not covered by sibling specs:
  - `mydash:dashboard:list [--user=<uid>] [--group=<gid>] [--status=<draft|published|scheduled>]` — list dashboards with optional filtering.
  - `mydash:dashboard:show <uuid>` — print one dashboard's full config (incl. widget tree) as JSON.
  - `mydash:dashboard:delete <uuid> [--cascade]` — delete a dashboard; `--cascade` removes children (depends on `dashboard-tree` capability).
  - `mydash:dashboard:debug-share <uuid>` — print all share rows, lock state, version count, view count for support debugging.
  - `mydash:user:revoke-feed-token <uid>` — revoke a user's RSS feed token (depends on `dashboard-rss-feeds` capability).
  - `mydash:i18n:export-strings` — extract translatable strings from PHP/Vue source into POT format.
  - `mydash:i18n:migrate-language-structure` — one-time migration: flat-language storage → per-language-table (depends on `dashboard-language-content`).
  - `mydash:i18n:copy-navigation --from=<lang> --to=<lang>` — clone org-navigation tree from one language variant to another.
- All commands MUST emit structured exit codes: `0` (success), `1` (generic error), `2` (invalid arguments), `3` (permission denied), `4` (resource not found), `5` (partial success).
- `--json` output schema: `{success: bool, exitCode: int, data: {...}, errors: [{code, message, context}]}`.
- Commands register via standard NC pattern: `appinfo/info.xml` `<commands>` block + classes extending `OC\Core\Command\Base`.
- Every command includes `--help` text describing arguments, flags, and examples.
- Logging: each command writes one audit line to NC's main log on completion: `[mydash] cli <command> <args> exitCode=<n> durationMs=<ms> byUser=<uid|cli>`.
- Help meta: `php occ list mydash` lists all `mydash:*` commands with one-line descriptions.
- Backwards compat: pre-existing MyDash commands (if any) keep working unchanged; migration to the namespace + flags is documented but not required by this spec.

## Capabilities

### New Capabilities

- `cli-commands`: establishes the `mydash:` namespace convention, global flags standard, and new operational helper commands for dashboard and i18n management.

### Modified Capabilities

- (none — dashboards, metadata-fields, sharing, rss-feeds remain unchanged)

## Impact

**Affected code:**

- `appinfo/info.xml` — add `<commands>` block with all `mydash:*` command class names
- `lib/Command/DashboardListCommand.php` (new) — implements `mydash:dashboard:list`
- `lib/Command/DashboardShowCommand.php` (new) — implements `mydash:dashboard:show`
- `lib/Command/DashboardDeleteCommand.php` (new) — implements `mydash:dashboard:delete`
- `lib/Command/DashboardDebugShareCommand.php` (new) — implements `mydash:dashboard:debug-share`
- `lib/Command/UserRevokeFeedTokenCommand.php` (new) — implements `mydash:user:revoke-feed-token`
- `lib/Command/I18nExportStringsCommand.php` (new) — implements `mydash:i18n:export-strings`
- `lib/Command/I18nMigrateLanguageStructureCommand.php` (new) — implements `mydash:i18n:migrate-language-structure`
- `lib/Command/I18nCopyNavigationCommand.php` (new) — implements `mydash:i18n:copy-navigation`
- `lib/Command/CommandBase.php` (new) — abstract base extending `OC\Core\Command\Base` with `--quiet`, `--json`, `--no-interaction` support and structured exit code logic
- `lib/Service/CommandService.php` (new) — shared service for exit code constants, audit logging, JSON formatting
- No schema migration — uses existing tables only

**Affected APIs:**

- (none — CLI only)

**Dependencies:**

- `symfony/console` (already required by Nextcloud) — for command base classes
- No new composer or npm dependencies

**Backward compatibility:**

- Zero-impact on existing endpoints or data models.
- Pre-existing commands remain unchanged; migration to the `mydash:` namespace and global flags is optional and documented in Impact section but not required by this spec.

## References

- `openspec/specs/dashboards/spec.md` — dashboard structure and lifecycle
- `openspec/specs/admin-settings/spec.md` — admin authorization patterns
- `openspec/changes/dashboard-export-import/specs/dashboard-export-import/spec.md` (sibling) — export/import commands
- `openspec/changes/confluence-html-import/specs/confluence-html-import/spec.md` (sibling) — confluence import command
- `openspec/changes/demo-data-showcases/specs/demo-data-showcases/spec.md` (sibling) — demo command
- `openspec/changes/orphaned-data-cleanup/specs/orphaned-data-cleanup/spec.md` (sibling) — cleanup commands
- `openspec/changes/setup-wizard/specs/setup-wizard/spec.md` (sibling) — setup command
- `openspec/changes/multi-scope-dashboards/specs/dashboards/spec.md` — dashboard scoping and sharing
- `openspec/changes/dashboard-rss-feeds/specs/dashboard-rss-feeds/spec.md` — RSS feed token lifecycle
- `openspec/changes/dashboard-language-content/specs/dashboard-language-content/spec.md` — language content storage
- `openspec/changes/navigation-editor-org/specs/navigation-editor-org/spec.md` (if exists) — org-navigation tree
