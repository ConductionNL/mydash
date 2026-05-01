# Tasks — cli-commands

## 1. Shared infrastructure

- [ ] 1.1 Create `lib/Command/CommandBase.php` — abstract class extending `OC\Core\Command\Base`; registers `--quiet|-q`, `--json`, `--no-interaction|-n` in `configure()`; provides `outputJson()`, `handleException()`, and `writeAuditLog()` helpers
- [ ] 1.2 Create `lib/Service/CommandService.php` — defines exit code constants (`EXIT_SUCCESS=0`, `EXIT_ERROR=1`, `EXIT_INVALID_ARGS=2`, `EXIT_PERMISSION_DENIED=3`, `EXIT_NOT_FOUND=4`, `EXIT_PARTIAL_SUCCESS=5`), JSON schema builder, and audit log formatter
- [ ] 1.3 Audit log helper MUST write `[mydash] cli <command> <args> exitCode=<n> durationMs=<ms> byUser=<uid|cli>` to NC main log via `\OC::$server->getLogger()` regardless of success or failure
- [ ] 1.4 Add SPDX headers inside the file docblock on all new PHP files (gate-spdx compliance)

## 2. Dashboard management commands

- [ ] 2.1 Create `lib/Command/DashboardListCommand.php` implementing `mydash:dashboard:list`
  - Arguments: none required
  - Options: `--user=<uid>`, `--group=<gid>`, `--status=<draft|published|scheduled>`
  - Validates status enum (exit 2 on invalid value)
  - Returns exit 4 if `--user` uid does not exist
  - Outputs table (default) or JSON via `CommandBase`
- [ ] 2.2 Create `lib/Command/DashboardShowCommand.php` implementing `mydash:dashboard:show <uuid>`
  - Validates UUID format (exit 2 on malformed)
  - Returns exit 4 if dashboard not found
  - Outputs full widget tree + metadata as JSON
- [ ] 2.3 Create `lib/Command/DashboardDeleteCommand.php` implementing `mydash:dashboard:delete <uuid> [--cascade]`
  - Checks `dashboard-tree` capability before honoring `--cascade`
  - Returns exit 2 if children exist and `--cascade` not passed
  - Returns exit 3 if caller is not admin
  - Returns exit 4 if dashboard UUID not found
  - Prompts confirmation unless `--no-interaction`
- [ ] 2.4 Create `lib/Command/DashboardDebugShareCommand.php` implementing `mydash:dashboard:debug-share <uuid>`
  - Queries share rows (user shares, group shares, public shares via dashboard-sharing capability)
  - Queries lock state via dashboard-locking capability tables
  - Queries version count via dashboard-versioning capability
  - Queries view count via dashboard-view-analytics capability
  - Returns structured JSON with keys: `shares`, `locked`, `lockedBy`, `lockedAt`, `versionCount`, `viewCount`

## 3. User management commands

- [ ] 3.1 Create `lib/Command/UserRevokeFeedTokenCommand.php` implementing `mydash:user:revoke-feed-token <uid>`
  - Checks `dashboard-rss-feeds` capability is active; exit 1 with message if absent
  - Returns exit 4 if user uid does not exist in Nextcloud
  - Returns exit 3 if caller is not admin
  - Invalidates token in the RSS feeds token table (depends on `dashboard-rss-feeds` entity)
  - Outputs `{uid, revokedAt}` on `--json`

## 4. i18n commands

- [ ] 4.1 Create `lib/Command/I18nExportStringsCommand.php` implementing `mydash:i18n:export-strings`
  - Scans `lib/` (PHP) and `src/` (Vue/JS) for i18n markers
  - Writes output to `l10n/mydash.pot`
  - Idempotent (overwrites existing file); exits 0
- [ ] 4.2 Create `lib/Command/I18nMigrateLanguageStructureCommand.php` implementing `mydash:i18n:migrate-language-structure`
  - Checks `dashboard-language-content` capability; exit 1 if absent
  - Migrates flat-language rows to per-language-table structure
  - Idempotent (already-migrated rows skipped); exits 0
  - Prompts confirmation unless `--no-interaction`
- [ ] 4.3 Create `lib/Command/I18nCopyNavigationCommand.php` implementing `mydash:i18n:copy-navigation --from=<lang> --to=<lang>`
  - Both `--from` and `--to` are required; exit 2 if missing
  - Returns exit 4 if source language has no navigation tree
  - Does not overwrite existing target nodes by default; add `--overwrite` to force
  - Reports count of cloned nodes on success

## 5. Registration

- [ ] 5.1 Add `<commands>` block to `appinfo/info.xml` listing all 8 new command classes:
  - `OCA\MyDash\Command\DashboardListCommand`
  - `OCA\MyDash\Command\DashboardShowCommand`
  - `OCA\MyDash\Command\DashboardDeleteCommand`
  - `OCA\MyDash\Command\DashboardDebugShareCommand`
  - `OCA\MyDash\Command\UserRevokeFeedTokenCommand`
  - `OCA\MyDash\Command\I18nExportStringsCommand`
  - `OCA\MyDash\Command\I18nMigrateLanguageStructureCommand`
  - `OCA\MyDash\Command\I18nCopyNavigationCommand`
- [ ] 5.2 Verify `php occ list mydash` lists all 8 commands after registration
- [ ] 5.3 Verify `php occ list mydash:dashboard`, `php occ list mydash:i18n`, `php occ list mydash:user` filter correctly

## 6. Help text

- [ ] 6.1 Each command implements `getDescription()` returning ≤60 chars
- [ ] 6.2 Each command implements `getHelp()` covering purpose, arguments, options, and ≥2 examples
- [ ] 6.3 Help text reviewed for accuracy before PR merge (pair review item)

## 7. PHPUnit tests

- [ ] 7.1 `CommandServiceTest` — verify all 6 exit code constants are distinct integers; verify JSON builder produces valid schema; verify audit log format string
- [ ] 7.2 `DashboardListCommandTest` — `--status=invalid` → exit 2; unknown `--user` → exit 4; success with 0 results → exit 0; `--json` output parses correctly
- [ ] 7.3 `DashboardShowCommandTest` — malformed UUID → exit 2; missing UUID → exit 4; valid UUID → exit 0 with widget tree in JSON
- [ ] 7.4 `DashboardDeleteCommandTest` — children exist without `--cascade` → exit 2; non-admin → exit 3; missing UUID → exit 4; success with `--cascade` → exit 0
- [ ] 7.5 `DashboardDebugShareCommandTest` — valid UUID returns all expected JSON keys; missing UUID → exit 4
- [ ] 7.6 `UserRevokeFeedTokenCommandTest` — missing capability → exit 1; unknown uid → exit 4; non-admin → exit 3; success → exit 0 with `revokedAt` in JSON
- [ ] 7.7 `I18nCommandsTest` — export-strings creates `.pot` file; migrate aborts without capability; copy-navigation exit 4 for unknown source language

## 8. Quality gates

- [ ] 8.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes on all new files — fix any pre-existing issues encountered
- [ ] 8.2 Verify audit log is written on both success and unhandled exception paths (unit test with mock logger)
- [ ] 8.3 Verify `--json` output passes `jq .` without error for every command (integration smoke test)
- [ ] 8.4 SPDX headers inside docblocks on all new PHP files (gate-spdx)
- [ ] 8.5 `i18n` keys for all new error messages in both `nl` and `en` per i18n ADR
- [ ] 8.6 Run all relevant `hydra-gates` locally before opening PR
