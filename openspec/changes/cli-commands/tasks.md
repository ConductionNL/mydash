# CLI Commands Suite - Tasks

## Core Infrastructure Tasks

- [ ] TASK-CLI-001: Implement `CommandBase` abstract class extending `OC\Core\Command\Base`
  - Add method `configure()` to register global flags: `--quiet|-q`, `--json`, `--no-interaction|-n`
  - Add helper methods: `getQuietMode()`, `getJsonMode()`, `getNoInteractionMode()`
  - Ensure all three flags are available to child commands

- [ ] TASK-CLI-002: Implement `CommandService` utility class
  - Define exit code constants: `EXIT_SUCCESS=0`, `EXIT_GENERIC_ERROR=1`, `EXIT_INVALID_ARGUMENT=2`, `EXIT_PERMISSION_DENIED=3`, `EXIT_NOT_FOUND=4`, `EXIT_PARTIAL_SUCCESS=5`
  - Implement `formatJsonResponse(success, exitCode, data, errors): string` method
  - Implement `buildErrorObject(code, message, context): array` method
  - Implement `logAuditLine(command, args, exitCode, durationMs, userId): void` method

- [ ] TASK-CLI-003: Update `appinfo/info.xml` with `<commands>` block
  - Register all 8 command classes with their fully qualified names
  - Ensure commands are loaded on app initialization

## Dashboard Management Commands

- [ ] TASK-CLI-004: Implement `DashboardListCommand` (`mydash:dashboard:list`)
  - Arguments: None
  - Options: `--user=<uid>`, `--status=<status>`, plus global flags
  - Implement filtering by user and status
  - Return array of dashboard objects with uuid, name, status, owner, createdAt
  - Exit codes: 0, 2 (invalid filter), 3 (permission denied)
  - Implement help text with at least two examples

- [ ] TASK-CLI-005: Implement `DashboardShowCommand` (`mydash:dashboard:show`)
  - Arguments: `<uuid>` Dashboard UUID with validation
  - Options: Global flags only
  - Return full dashboard object with nested widgets, placements, and metadata
  - Exit codes: 0, 2 (invalid UUID), 4 (dashboard not found)
  - Implement help text with examples

- [ ] TASK-CLI-006: Implement `DashboardDeleteCommand` (`mydash:dashboard:delete`)
  - Arguments: `<uuid>` Dashboard UUID
  - Options: `--cascade`, plus global flags
  - Validate UUID format (exit code 2 on failure)
  - Check authorization — admin only (exit code 3)
  - Implement confirmation prompt "Delete dashboard X? (y/N)" — skip if `--no-interaction`
  - Fail with exit code 2 if dashboard has children and `--cascade` not provided
  - Delete with cascade if flag present
  - Exit codes: 0, 2, 3, 4
  - Implement help text with examples

- [ ] TASK-CLI-007: Implement `DashboardDebugShareCommand` (`mydash:dashboard:debug-share`)
  - Arguments: `<uuid>` Dashboard UUID
  - Options: Global flags only
  - Return object with: shares[], locked, lockedBy, lockedAt, versionCount, viewCount
  - Exit codes: 0, 2 (invalid UUID), 4 (dashboard not found)
  - Implement help text noting this is for support engineers

## User Management Commands

- [ ] TASK-CLI-008: Implement `UserRevokeFeedTokenCommand` (`mydash:user:revoke-feed-token`)
  - Arguments: `<uid>` Nextcloud user ID
  - Options: Global flags only
  - Check if `dashboard-rss-feeds` capability is available (exit code 1 if absent)
  - Check authorization — admin only (exit code 3)
  - Validate user exists (exit code 4 if not)
  - Revoke the user's RSS feed token in the database
  - Return object with uid, revokedAt (ISO 8601)
  - Exit codes: 0, 1 (capability unavailable), 3, 4
  - Implement help text documenting capability requirement

## Internationalization Commands

- [ ] TASK-CLI-009: Implement `I18nExportStringsCommand` (`mydash:i18n:export-strings`)
  - Arguments: None
  - Options: Global flags only
  - Scan `lib/` and `src/` for i18n markers (t(), n() functions)
  - Generate `.pot` file and write to `l10n/mydash.pot`
  - Return object with: count, filePath, potFile
  - Exit codes: 0, 1 (generic error)
  - Implement help text with examples

- [ ] TASK-CLI-010: Implement `I18nMigrateLanguageStructureCommand` (`mydash:i18n:migrate-language-structure`)
  - Arguments: None
  - Options: Global flags only
  - Check if `dashboard-language-content` capability is available (exit code 1 if absent)
  - Check authorization — admin only (exit code 3)
  - Implement migration from flat schema to per-language-table structure
  - Ensure idempotency — already-migrated rows are skipped
  - Return object with: migrated, skipped, errors
  - Exit codes: 0, 1, 3
  - Implement help text documenting capability requirement and one-time nature

- [ ] TASK-CLI-011: Implement `I18nCopyNavigationCommand` (`mydash:i18n:copy-navigation`)
  - Arguments: None
  - Options: `--from=<lang>`, `--to=<lang>`, `--overwrite`, plus global flags
  - Validate language codes (exit code 2 if invalid)
  - Check authorization — admin only (exit code 3)
  - Query organization navigation tree for source language
  - Check source language exists (exit code 4 if not)
  - Clone all nodes to target language, skipping conflicts by default
  - Implement `--overwrite` flag to replace existing target nodes
  - Return object with: copied, skipped, errors
  - Exit codes: 0, 2, 3, 4
  - Implement help text with examples

## Testing & Quality Assurance

- [ ] TASK-CLI-012: Write unit tests for `CommandBase`
  - Test global flag registration and retrieval
  - Test flag parsing from input

- [ ] TASK-CLI-013: Write unit tests for `CommandService`
  - Test exit code constants
  - Test JSON response formatting
  - Test error object building
  - Test audit line formatting

- [ ] TASK-CLI-014: Write integration tests for all 8 commands
  - Test each command with valid arguments
  - Test each command with invalid arguments (exit code validation)
  - Test each command with permission denied scenarios
  - Test each command with missing resources
  - Test JSON output format for all commands
  - Test quiet mode suppression
  - Test no-interaction mode skips prompts

- [ ] TASK-CLI-015: Test audit logging
  - Verify audit lines are written to `data/nextcloud.log`
  - Verify format matches specification
  - Verify audit lines written even on failures
  - Test truncation of long args (≤100 chars)

- [ ] TASK-CLI-016: Verify help text completeness (ADR-009)
  - All commands implement `getDescription()` returning ≤60 characters
  - All commands implement `getHelp()` with purpose, arguments, flags, examples
  - Global flags documented in help
  - Test `php occ list mydash` output
  - Test `php occ mydash:<command> --help` for each command

- [ ] TASK-CLI-017: Verify i18n support (ADR-007)
  - All user-facing strings are translatable (not hardcoded)
  - English keys used in `t()` functions
  - Test with `--quiet` and `--json` flags to ensure no hardcoded output

- [ ] TASK-CLI-018: Verify backwards compatibility
  - Any pre-existing MyDash commands still work unchanged
  - Test interaction between new and pre-existing commands

## Documentation & Code Review

- [ ] TASK-CLI-019: Create ADR reference in code comments
  - Reference ADR-007 for i18n approach
  - Reference ADR-005 for security (admin authorization checks)
  - Reference spec requirement numbers in command implementations

- [ ] TASK-CLI-020: Update developer documentation
  - Document `CommandBase` usage pattern for future CLI commands
  - Document how to add new commands to the namespace
  - Create CLI commands contribution guide

- [ ] TASK-CLI-021: Feature documentation and screenshots
  - Document each command's usage with examples
  - Create cheat sheet for common admin tasks
  - Document JSON output schema with real examples
  - Document exit codes and error scenarios
