# CLI Commands Suite - Design Document

## Architecture

### Shared Infrastructure

#### CommandBase Abstract Class
- Extends `OC\Core\Command\Base`
- Centralizes global flag registration: `--quiet|-q`, `--json`, `--no-interaction|-n`
- Provides helper methods: `getQuietMode()`, `getJsonMode()`, `getNoInteractionMode()`
- All command classes extend `CommandBase` instead of directly extending `OC\Core\Command\Base`

#### CommandService
- Static utility for exit code constants: `EXIT_SUCCESS` (0), `EXIT_GENERIC_ERROR` (1), `EXIT_INVALID_ARGUMENT` (2), `EXIT_PERMISSION_DENIED` (3), `EXIT_NOT_FOUND` (4), `EXIT_PARTIAL_SUCCESS` (5)
- JSON response builder: `formatJsonResponse(success, exitCode, data, errors)`
- Error object builder: `buildErrorObject(code, message, context)`
- Audit logging: `logAuditLine(command, args, exitCode, durationMs, userId)`

### Backend Commands

#### DashboardListCommand (`mydash:dashboard:list`)
- **Purpose**: List dashboards with optional filters
- **Arguments**: None
- **Options**: 
  - `--user=<uid>` Filter by owner user ID
  - `--status=<published|draft|archived>` Filter by status
  - `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Returns**: Array of dashboard objects (uuid, name, status, owner, createdAt)
- **Exit codes**: 0 (success), 2 (invalid filter), 3 (permission denied)

#### DashboardShowCommand (`mydash:dashboard:show`)
- **Purpose**: Display full dashboard configuration including widget tree
- **Arguments**: `<uuid>` Dashboard UUID
- **Options**: `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Returns**: Dashboard object with nested widgets, placements, and metadata
- **Exit codes**: 0 (success), 2 (invalid UUID), 4 (dashboard not found)

#### DashboardDeleteCommand (`mydash:dashboard:delete`)
- **Purpose**: Delete a dashboard with optional cascade for children
- **Arguments**: `<uuid>` Dashboard UUID
- **Options**: 
  - `--cascade` Delete child dashboards
  - `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Prompts**: "Delete dashboard X? (y/N)" — skipped if `--no-interaction`
- **Exit codes**: 0 (success), 2 (invalid UUID, missing --cascade), 3 (permission denied), 4 (dashboard not found)

#### DashboardDebugShareCommand (`mydash:dashboard:debug-share`)
- **Purpose**: Print all sharing state for support engineers
- **Arguments**: `<uuid>` Dashboard UUID
- **Options**: `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Returns**: Object with shares[], locked, lockedBy, lockedAt, versionCount, viewCount
- **Exit codes**: 0 (success), 2 (invalid UUID), 4 (dashboard not found)

#### UserRevokeFeedTokenCommand (`mydash:user:revoke-feed-token`)
- **Purpose**: Revoke a user's RSS feed token
- **Arguments**: `<uid>` Nextcloud user ID
- **Options**: `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Prerequisites**: `dashboard-rss-feeds` capability must be enabled
- **Returns**: Object with uid, revokedAt (ISO 8601)
- **Exit codes**: 0 (success), 1 (capability unavailable), 3 (permission denied — admin only), 4 (user not found)

#### I18nExportStringsCommand (`mydash:i18n:export-strings`)
- **Purpose**: Extract translatable strings to POT file
- **Arguments**: None
- **Options**: `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Output**: Writes to `l10n/mydash.pot`
- **Returns**: Object with count, filePath, potFile
- **Exit codes**: 0 (success), 1 (generic error)

#### I18nMigrateLanguageStructureCommand (`mydash:i18n:migrate-language-structure`)
- **Purpose**: One-time migration from flat to per-language-table schema
- **Arguments**: None
- **Options**: `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Prerequisites**: `dashboard-language-content` capability must be enabled
- **Idempotency**: Already-migrated rows are skipped; re-running is safe
- **Returns**: Object with migrated, skipped, errors
- **Exit codes**: 0 (success), 1 (capability unavailable), 3 (permission denied — admin only)

#### I18nCopyNavigationCommand (`mydash:i18n:copy-navigation`)
- **Purpose**: Clone organization navigation tree across language variants
- **Arguments**: None
- **Options**: 
  - `--from=<lang>` Source language code (e.g., nl, en)
  - `--to=<lang>` Target language code
  - `--overwrite` Overwrite existing target nodes (default: no-overwrite)
  - `--quiet|-q`, `--json`, `--no-interaction|-n` (global)
- **Returns**: Object with copied, skipped, errors
- **Exit codes**: 0 (success), 2 (invalid language code), 3 (permission denied — admin only), 4 (source language not found)

### Command Registration

- Commands are registered in `appinfo/info.xml` under `<commands>` block
- Each command's fully qualified class name is listed: `OCA\MyDash\Command\DashboardListCommand`
- Nextcloud auto-discovers and registers on app load

### Data Flow

```
User runs: php occ mydash:dashboard:list --user=alice --json
    ↓
Nextcloud routes to DashboardListCommand::execute()
    ↓
CommandBase::configure() provides global flags
    ↓
execute(InputInterface $input, OutputInterface $output): int
    - Retrieve global flag values
    - Validate arguments (exit code 2)
    - Check authorization (exit code 3)
    - Fetch data via backend service
    - Format response (JSON or text)
    - Log audit line
    - Return exit code
    ↓
Output to stdout (or stderr for errors)
Process exits with code 0-5
```

### Key Design Decisions

- **Namespace pattern**: All commands prefixed with `mydash:` ensures discoverability via `php occ list mydash`
- **Global flags in CommandBase**: Eliminates duplication; all child commands inherit without modification
- **CommandService for exit codes**: Centralized constants prevent magic numbers; ensures consistency across all commands
- **Audit logging on every path**: Even failures are logged; critical for operator accountability
- **Capability-aware commands**: RSS and i18n commands check for capability presence and fail gracefully with clear messages
- **No-interaction mode for CI**: Commands safe to invoke unattended in automation pipelines
- **JSON output schema**: Fixed structure allows programmatic parsing and aggregation
- **Help text completeness**: Every command includes purpose, arguments, flags, and at least two examples

## Seed Data

The CLI Commands Suite does not introduce new data models requiring seed data. All commands operate on existing MyDash entities (dashboards, users, language entries) that are created and managed through the web UI or external integrations.
