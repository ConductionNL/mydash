---
capability: cli-commands
delta: true
status: draft
---

# CLI Commands Suite — New Capability

## Purpose

The CLI commands suite establishes a coherent, standardized operator interface for MyDash management tasks. It defines the `mydash:` namespace prefix, consistent global flags across all commands, and introduces new operational helpers for dashboard inspection, sharing debugging, feed token management, and internationalization. The capability ensures scriptability, auditability, and discoverability of all CLI operations.

## Data Model

### Command Namespace Convention

All MyDash CLI commands MUST use the `mydash:` prefix followed by a verb-noun pattern:
- `mydash:<verb>` (e.g., `mydash:export`)
- `mydash:<verb>:<noun>` (e.g., `mydash:dashboard:list`)
- `mydash:<noun>:<verb>` (e.g., `mydash:user:revoke-feed-token`)
- `mydash:<category>:<verb>` (e.g., `mydash:cleanup:scan`, `mydash:i18n:export-strings`)

This prefix appears in `php occ list` output and in programmatic command lookups.

### Global Flags (Every Command)

Every MyDash command MUST support three global flags:

1. **`--quiet|-q`** (boolean, optional)
   - Suppress non-essential output (progress, summary lines).
   - Errors and critical messages MUST still print to stderr.
   - Useful for CI/automation scripts.

2. **`--json`** (boolean, optional)
   - Emit machine-readable JSON output instead of formatted text.
   - Output schema (below) is fixed across all commands.
   - Enables log aggregation and programmatic parsing.

3. **`--no-interaction|-n`** (boolean, optional)
   - Skip all confirmation prompts (e.g., "Delete dashboard X? (y/N)").
   - Assume "yes" on all questions.
   - Required for unattended CI/automation.

### JSON Output Schema

When `--json` is passed, the command's stdout MUST be a single JSON object (no preamble or trailing text) with the following schema:

```json
{
  "success": true,
  "exitCode": 0,
  "data": {
    "key": "value"
  },
  "errors": []
}
```

or on error:

```json
{
  "success": false,
  "exitCode": 2,
  "data": null,
  "errors": [
    {
      "code": "INVALID_ARGUMENT",
      "message": "Invalid UUID format: 'not-a-uuid'",
      "context": {
        "field": "uuid",
        "providedValue": "not-a-uuid"
      }
    }
  ]
}
```

**Fields:**
- `success` (boolean): `true` if the command completed without errors; `false` if any unrecovered error occurred or exit code > 0.
- `exitCode` (integer): One of the standard exit codes (0, 1, 2, 3, 4, 5).
- `data` (object | array | null): Command-specific output (dashboard list, single dashboard, metrics, etc.). `null` on error.
- `errors` (array): List of error objects. Empty on success.

**Error object fields:**
- `code` (string): Constant error identifier (e.g., `INVALID_ARGUMENT`, `PERMISSION_DENIED`, `NOT_FOUND`, `PARTIAL_SUCCESS`).
- `message` (string): Human-readable error description.
- `context` (object, optional): Additional metadata (field name, provided value, attempted action, etc.).

### Exit Codes (All Commands)

All MyDash commands MUST emit one of these exit codes:

| Code | Meaning | Example |
|------|---------|---------|
| `0` | Success — all tasks completed. | Command finished without errors. |
| `1` | Generic error — unhandled exception. | Uncaught exception in command logic. |
| `2` | Invalid arguments — usage error. | Missing required arg, malformed UUID, invalid enum value. |
| `3` | Permission denied — auth/authz failure. | Current user is not admin; insufficient scope. |
| `4` | Resource not found — lookup failed. | Dashboard UUID does not exist; user not found. |
| `5` | Partial success — batch operation with some failures. | Import 10 dashboards, 3 succeeded, 7 failed. |

### Audit Logging

Every command MUST write a single audit line to Nextcloud's main log (`data/nextcloud.log`) on completion. The line format is:

```
[mydash] cli <command> <args> exitCode=<n> durationMs=<ms> byUser=<uid|cli>
```

**Fields:**
- `<command>`: Full command name (e.g., `dashboard:list`).
- `<args>`: Space-separated non-sensitive arg values (omit passwords, tokens, file contents). Truncated to 100 chars if necessary.
- `<exitCode>`: Exit code (0, 1, 2, 3, 4, or 5).
- `durationMs`: Total execution time in milliseconds (wall clock).
- `byUser`: Nextcloud user ID of the caller, or `"cli"` if run via cron/automation.

### Help & Discoverability

- Every command MUST implement `getDescription()` returning a one-line summary (60 chars max).
- Every command MUST implement `getHelp()` returning purpose, arguments, flags, and examples.
- `php occ list mydash` MUST list all `mydash:*` commands with their one-line descriptions.

### Command Registration

Commands are registered via Nextcloud's standard pattern:

1. **`appinfo/info.xml`** — add a `<commands>` block:
   ```xml
   <commands>
       <command>OCA\MyDash\Command\DashboardListCommand</command>
       <command>OCA\MyDash\Command\DashboardShowCommand</command>
       <!-- ... etc -->
   </commands>
   ```

2. **Command class** — each extends `OC\Core\Command\Base` (via shared `CommandBase` helper):
   - Implements `configure()` to set name, description, arguments, options.
   - Implements `execute(InputInterface $input, OutputInterface $output): int` and returns exit code.
   - Uses shared `CommandService` for exit code constants, JSON formatting, audit logging.

## ADDED Requirements

### Requirement: REQ-CLI-001 Namespace Convention

The system MUST establish `mydash:` as the canonical namespace prefix for ALL MyDash CLI commands. Every command name MUST begin with `mydash:` followed by a verb-noun or category-verb pattern (e.g., `mydash:export`, `mydash:dashboard:list`, `mydash:cleanup:scan`). No MyDash command SHALL use a bare name or a different prefix.

#### Scenario: Discover all MyDash commands

- GIVEN a Nextcloud operator runs `php occ list mydash`
- WHEN the system enumerates all registered commands
- THEN every MyDash command MUST be prefixed with `mydash:`
- AND the output includes a one-line description for each command
- AND the list is alphabetically ordered by full command name

#### Scenario: Sibling specs all fall under the namespace

- GIVEN the `dashboard-export-import` spec defines `mydash:export` and `mydash:import`
- AND the `confluence-html-import` spec defines `mydash:import:confluence`
- AND the `demo-data-showcases` spec defines `mydash:demo-showcases:install`
- WHEN all commands are installed into one MyDash instance
- THEN all commands appear under the `mydash:` namespace
- AND there are no namespace collisions

#### Scenario: Future commands follow the convention

- GIVEN a developer adds a new command for MyDash
- WHEN they name it `mydash:custom:action` and register it in `appinfo/info.xml`
- THEN `php occ list mydash` includes the new command
- AND the convention is enforced by code review and ADR reference

#### Scenario: Commands with multi-level verbs

- GIVEN the command `mydash:i18n:copy-navigation --from=nl --to=en`
- WHEN an operator runs it
- THEN the command is recognized under the `mydash:` namespace
- AND `php occ list mydash:i18n` returns only i18n-related commands

#### Scenario: Namespace not shared with other apps

- GIVEN another Nextcloud app (e.g., Calendar) registers `occ calendar:list`
- WHEN `php occ list mydash` is run
- THEN only `mydash:*` commands appear
- AND no cross-app command collisions occur under the `mydash:` prefix

### Requirement: REQ-CLI-002 Global Flags: --quiet, --json, --no-interaction

Every MyDash command MUST support three consistent global flags: `--quiet|-q` (suppress non-essential output), `--json` (emit machine-readable JSON), and `--no-interaction|-n` (skip prompts). These flags MUST be defined in the shared `CommandBase` abstract class so all commands inherit them without duplication.

#### Scenario: Quiet mode suppresses progress but not errors

- GIVEN an operator runs `php occ mydash:dashboard:list --quiet`
- WHEN the command finishes
- THEN no progress or summary output is printed to stdout
- AND any error message (e.g., "User not found") MUST still be printed to stderr
- AND the exit code is correct (0 for success, non-zero for error)

#### Scenario: JSON flag emits structured output only

- GIVEN an operator runs `php occ mydash:dashboard:list --json`
- WHEN the command finishes
- THEN stdout is a single JSON object with fields `{success, exitCode, data, errors}`
- AND stdout MUST contain only the JSON object (no preamble, no debug text)
- AND newlines and special characters in data are properly escaped

#### Scenario: No-interaction flag skips prompts in CI

- GIVEN a CI script runs `php occ mydash:dashboard:delete a1b2c3d4 --no-interaction`
- WHEN the command would normally prompt "Delete dashboard X? (y/N)"
- THEN the prompt MUST be skipped and the deletion proceeds immediately
- AND the exit code is 0 on success or 3 on permission denied

#### Scenario: All three flags combine cleanly

- GIVEN `php occ mydash:import --file=/tmp/x.zip --quiet --no-interaction --json`
- WHEN the import runs
- THEN no prompts appear, no progress messages print, and only JSON appears on stdout
- AND all three flags work together without conflict

#### Scenario: Global flags inherited by every command

- GIVEN any newly registered `mydash:*` command extending `CommandBase`
- WHEN `php occ mydash:my-new-command --help` is run
- THEN the help output MUST document `--quiet, -q`, `--json`, and `--no-interaction, -n`
- AND the flags function correctly without any per-command implementation

### Requirement: REQ-CLI-003 New Commands: Dashboard Management

The system MUST provide four new operator-facing dashboard commands: `mydash:dashboard:list`, `mydash:dashboard:show`, `mydash:dashboard:delete`, and `mydash:dashboard:debug-share`. These MUST cover listing with filters, full-config inspection, safe deletion with cascade option, and support-oriented sharing/state diagnostics.

#### Scenario: List dashboards with optional filters

- GIVEN an admin runs `php occ mydash:dashboard:list --user=alice --status=published`
- WHEN the system queries dashboards
- THEN the output MUST list all dashboards owned by alice with status=published
- AND with `--json` the data field MUST be an array of dashboard objects with uuid, name, status, owner

#### Scenario: Show one dashboard's full widget tree

- GIVEN `php occ mydash:dashboard:show a1b2c3d4-e5f6-4789-abcd-ef1234567890`
- WHEN the command runs
- THEN the output MUST include dashboard metadata (uuid, name, owner, type, status, gridColumns)
- AND the full widget placement tree (widget id, position, size, config)
- AND with `--json` the data is wrapped in the standard response schema

#### Scenario: Delete without cascade fails when children exist

- GIVEN `php occ mydash:dashboard:delete a1b2c3d4 --no-interaction`
- WHEN the dashboard has child dashboards (depends on `dashboard-tree` capability)
- AND `--cascade` is NOT provided
- THEN the command MUST fail with exit code 2
- AND stderr MUST print "Use --cascade to also delete child dashboards"

#### Scenario: Delete with cascade removes children

- GIVEN `php occ mydash:dashboard:delete a1b2c3d4 --cascade --no-interaction`
- WHEN the dashboard and its two children exist
- THEN all three MUST be deleted
- AND the exit code MUST be 0
- AND the audit log records the cascaded deletion

#### Scenario: Debug-share prints all sharing state for support

- GIVEN `php occ mydash:dashboard:debug-share a1b2c3d4 --json`
- WHEN the command queries the sharing and locking state
- THEN the output MUST include all share rows (recipient, permission level, created at), lock state (locked/unlocked, locked by, locked at), version count, and view count
- AND the JSON data MUST have keys: `shares`, `locked`, `lockedBy`, `lockedAt`, `versionCount`, `viewCount`
- NOTE This command is intended for support engineers diagnosing sharing issues

### Requirement: REQ-CLI-004 New Commands: User Management

The system MUST provide the command `mydash:user:revoke-feed-token <uid>` to revoke a user's RSS feed token. The command MUST depend on the `dashboard-rss-feeds` capability being enabled and MUST refuse gracefully when that capability is absent.

#### Scenario: Revoke token for a valid user

- GIVEN user "alice" has an active RSS feed token
- WHEN an admin runs `php occ mydash:user:revoke-feed-token alice`
- THEN the token MUST be invalidated in the database
- AND any subsequent RSS feed request using the old token MUST be rejected with HTTP 401
- AND the exit code MUST be 0

#### Scenario: Revoke token for unknown user returns exit code 4

- GIVEN no Nextcloud user with uid "nonexistent" exists
- WHEN an admin runs `php occ mydash:user:revoke-feed-token nonexistent`
- THEN the exit code MUST be 4 (resource not found)
- AND stderr MUST print "User not found: 'nonexistent'"

#### Scenario: Command fails gracefully when RSS capability is absent

- GIVEN the `dashboard-rss-feeds` capability is not installed or disabled
- WHEN any operator runs `php occ mydash:user:revoke-feed-token alice`
- THEN the exit code MUST be 1 (generic error)
- AND stderr MUST print "The dashboard-rss-feeds capability is not available"

#### Scenario: JSON output on success

- GIVEN `php occ mydash:user:revoke-feed-token alice --json`
- WHEN the command successfully revokes the token
- THEN stdout MUST be `{success: true, exitCode: 0, data: {uid: "alice", revokedAt: "<iso8601>"}, errors: []}`

#### Scenario: Non-admin is denied

- GIVEN a regular (non-admin) Nextcloud user runs the command
- WHEN authorization is checked
- THEN the exit code MUST be 3 (permission denied)
- AND stderr MUST print "Permission denied: administrator required"

### Requirement: REQ-CLI-005 New Commands: i18n Management

The system MUST provide three i18n commands: `mydash:i18n:export-strings` (extract translatable strings to POT), `mydash:i18n:migrate-language-structure` (one-time flat-to-per-language-table migration, depends on `dashboard-language-content`), and `mydash:i18n:copy-navigation --from=<lang> --to=<lang>` (clone org-navigation tree across language variants). Each command MUST document its prerequisites in `--help`.

#### Scenario: Export translatable strings to POT

- GIVEN a developer runs `php occ mydash:i18n:export-strings`
- WHEN the command scans `lib/` and `src/` for i18n markers
- THEN a `.pot` file is written to `l10n/mydash.pot`
- AND the exit code MUST be 0
- AND the file contains all discovered translatable strings

#### Scenario: Migrate language structure one-time

- GIVEN the old flat-language storage schema is in place
- AND the `dashboard-language-content` capability is enabled
- WHEN an admin runs `php occ mydash:i18n:migrate-language-structure --no-interaction`
- THEN all flat language entries MUST be migrated to the per-language-table structure
- AND the exit code MUST be 0
- AND rerunning the command MUST be idempotent (already migrated rows are skipped, exit code 0)

#### Scenario: Migration aborts when capability is absent

- GIVEN the `dashboard-language-content` capability is not installed
- WHEN an admin runs `php occ mydash:i18n:migrate-language-structure`
- THEN the exit code MUST be 1
- AND stderr MUST print "dashboard-language-content capability is required"

#### Scenario: Copy navigation tree between language variants

- GIVEN a Dutch (`nl`) navigation tree with 12 nodes exists
- WHEN an admin runs `php occ mydash:i18n:copy-navigation --from=nl --to=en`
- THEN all 12 nodes are cloned as English (`en`) variants
- AND existing English nodes that conflict MUST NOT be overwritten (no-overwrite by default)
- AND the exit code MUST be 0

#### Scenario: Copy navigation fails on unknown language

- GIVEN `php occ mydash:i18n:copy-navigation --from=xx --to=en` where `xx` has no navigation data
- WHEN the command looks up the source language
- THEN the exit code MUST be 4 (resource not found)
- AND stderr MUST print "No navigation tree found for language 'xx'"

### Requirement: REQ-CLI-006 Exit Code Contract

All MyDash commands MUST emit exactly one of the six standard exit codes (0, 1, 2, 3, 4, 5) on every execution path, including panics and unhandled exceptions. No command SHALL exit with any other code. The exit codes MUST be defined as constants in the shared `CommandService` and referenced consistently.

#### Scenario: Success exit code 0

- GIVEN `php occ mydash:dashboard:list` completes with no errors
- WHEN the exit code is inspected via `echo $?`
- THEN the value MUST be `0`

#### Scenario: Invalid argument exit code 2

- GIVEN `php occ mydash:dashboard:show not-a-uuid`
- WHEN the command validates the UUID argument
- THEN the exit code MUST be 2
- AND stderr MUST print "Invalid UUID format: 'not-a-uuid'"

#### Scenario: Permission denied exit code 3

- GIVEN a regular (non-admin) user runs `php occ mydash:dashboard:delete a1b2c3d4`
- WHEN the command checks authorization
- THEN the exit code MUST be 3
- AND stderr MUST print "Permission denied: administrator required"

#### Scenario: Resource not found exit code 4

- GIVEN `php occ mydash:dashboard:show 00000000-0000-0000-0000-000000000000` where that UUID is absent
- WHEN the command queries the database
- THEN the exit code MUST be 4
- AND stderr MUST print "Dashboard not found"

#### Scenario: Partial success exit code 5

- GIVEN `php occ mydash:import --file=/tmp/batch.zip` with 10 dashboards where 7 succeed and 3 fail
- WHEN the batch import completes
- THEN the exit code MUST be 5
- AND the summary output MUST state "7 imported, 3 failed"

### Requirement: REQ-CLI-007 JSON Output Schema

All commands invoked with `--json` MUST emit a single JSON object to stdout conforming to the schema `{success: bool, exitCode: int, data: object|array|null, errors: [{code, message, context}]}`. No other text or whitespace MUST appear before or after the JSON object on stdout.

#### Scenario: Successful JSON response

- GIVEN `php occ mydash:dashboard:list --json`
- WHEN the command finds 3 dashboards
- THEN stdout MUST be valid JSON with structure `{success: true, exitCode: 0, data: {dashboards: [...]}, errors: []}`
- AND the `data.dashboards` field MUST be an array

#### Scenario: Error JSON response

- GIVEN `php occ mydash:user:revoke-feed-token unknown-user --json`
- WHEN the command does not find the user
- THEN stdout MUST be `{success: false, exitCode: 4, data: null, errors: [{code: "NOT_FOUND", message: "...", context: {userId: "unknown-user"}}]}`
- AND `exitCode` in JSON MUST match the process exit code

#### Scenario: Multiple errors in JSON

- GIVEN `php occ mydash:import --file=/tmp/corrupt.zip --json`
- WHEN the ZIP contains 3 invalid dashboards
- THEN `errors` MUST be an array of 3 error objects
- AND each error object MUST have `code`, `message`, and `context` fields

#### Scenario: stdout clean of non-JSON bytes

- GIVEN any command run with `--json`
- WHEN the output is piped to `jq .`
- THEN `jq` MUST parse it without error
- NOTE Any debug or progress output MUST go to stderr only

#### Scenario: Partial success exitCode in JSON

- GIVEN `php occ mydash:import --file=/tmp/mix.zip --json`
- WHEN 7 succeed and 3 fail
- THEN stdout MUST have `{exitCode: 5, success: false, data: {imported: 7, skipped: 3}, errors: [...]}`
- AND process exit code MUST also be 5

### Requirement: REQ-CLI-008 Command Registration via appinfo/info.xml

All MyDash CLI commands MUST be registered in `appinfo/info.xml` under a `<commands>` element listing each command's fully qualified class name. No command SHALL be discoverable by Nextcloud unless it appears in this block. Each class MUST extend a shared `CommandBase` abstract class (itself extending `OC\Core\Command\Base`).

#### Scenario: Commands are registered at install

- GIVEN a fresh MyDash installation with `appinfo/info.xml` containing a `<commands>` block
- WHEN Nextcloud loads the app
- THEN `php occ list mydash` MUST list every class in the `<commands>` block
- AND `php occ mydash:dashboard:list --help` MUST display the command's help text

#### Scenario: New sibling-spec command added to info.xml

- GIVEN `lib/Command/ExportCommand.php` is created for `mydash:export`
- WHEN its class is added to the `<commands>` block in `appinfo/info.xml`
- THEN `php occ list mydash` MUST include `mydash:export` without further manual registration

#### Scenario: CommandBase centralizes shared flag logic

- GIVEN a command class extends `CommandBase`
- WHEN `CommandBase::configure()` registers `--quiet`, `--json`, and `--no-interaction`
- THEN the child command MUST NOT duplicate flag registration
- AND all three flags MUST work on every child command

### Requirement: REQ-CLI-009 Help Text & --help Documentation

Every MyDash command MUST implement `getDescription()` (one-line, ≤60 chars, for `occ list`) and `getHelp()` (multi-line, for `--help`) covering command purpose, all arguments with types and constraints, all flags with defaults, and at least two examples. Commands without adequate help text MUST NOT pass the quality gate.

#### Scenario: One-line description in occ list

- GIVEN an operator runs `php occ list mydash`
- WHEN the system enumerates commands
- THEN each command MUST show a one-line description no longer than 60 characters:
  ```
  mydash:dashboard:delete    Delete a dashboard by UUID
  mydash:dashboard:list      List dashboards in the system
  mydash:dashboard:show      Display full dashboard configuration
  ```

#### Scenario: Detailed help via --help

- GIVEN `php occ mydash:dashboard:list --help`
- WHEN the command outputs help
- THEN the output MUST include: purpose (2–3 sentences), arguments with types, options with defaults, and at least 2 examples
- AND example: `$ php occ mydash:dashboard:list --user=alice --status=published --json`

#### Scenario: Help documents global flags

- GIVEN `php occ mydash:dashboard:delete --help`
- WHEN the help text is displayed
- THEN it MUST document `--quiet, -q`, `--json`, and `--no-interaction, -n`

#### Scenario: No undocumented or hidden commands

- GIVEN any command registered in `appinfo/info.xml`
- WHEN `php occ mydash:<name> --help` is run
- THEN the output MUST include all arguments and flags
- AND no argument or flag SHALL be hidden from help output

#### Scenario: Examples are realistic

- GIVEN `php occ mydash:i18n:copy-navigation --help`
- WHEN the examples section is read
- THEN it MUST include at least: `$ php occ mydash:i18n:copy-navigation --from=nl --to=en` and one piped example with `--json`

### Requirement: REQ-CLI-010 Audit Logging

Every MyDash command MUST write exactly one audit line to Nextcloud's main log on completion (whether success or failure). The line MUST follow the format `[mydash] cli <command> <args> exitCode=<n> durationMs=<ms> byUser=<uid|cli>` and MUST be written even when the command fails.

#### Scenario: Audit log on success

- GIVEN an admin runs `php occ mydash:dashboard:list --user=alice`
- WHEN the command completes with exit code 0
- THEN `data/nextcloud.log` MUST contain:
  ```
  [mydash] cli dashboard:list --user=alice exitCode=0 durationMs=<ms> byUser=admin
  ```
- NOTE `byUser` is the Nextcloud user ID running occ, or `cli` if called from cron/automation

#### Scenario: Audit log on permission error

- GIVEN a non-admin user runs `php occ mydash:dashboard:delete a1b2c3d4`
- WHEN the command exits with exit code 3
- THEN `data/nextcloud.log` MUST include:
  ```
  [mydash] cli dashboard:delete a1b2c3d4 exitCode=3 durationMs=<ms> byUser=<uid>
  ```

#### Scenario: Audit log truncates long args

- GIVEN `php occ mydash:import --file=/very/long/path/to/archive.zip --preserve-uuids`
- WHEN the args string exceeds 100 characters
- THEN the logged args MUST be truncated to ≤100 chars
- AND a trailing `...` MUST indicate truncation

#### Scenario: byUser is "cli" for cron-invoked commands

- GIVEN a cron job invokes `php occ mydash:cleanup:scan`
- WHEN no Nextcloud session is active
- THEN the audit line MUST use `byUser=cli`

#### Scenario: Log written even on unhandled exception

- GIVEN a command throws an uncaught exception
- WHEN the exception propagates
- THEN the audit line MUST still be written with `exitCode=1`
- AND the exception MUST be re-thrown or result in process exit code 1

### Requirement: REQ-CLI-011 Discoverability and Backwards Compatibility

The command `php occ list mydash` MUST enumerate all registered `mydash:*` commands in alphabetical order with one-line descriptions. Pre-existing MyDash commands that existed before this spec MUST continue to work unchanged; this spec only mandates the namespace convention and global flags for new commands. Future commands SHALL NOT be accepted without namespace compliance.

#### Scenario: Full command listing is alphabetical

- GIVEN MyDash has 8+ registered commands
- WHEN `php occ list mydash` is run
- THEN all commands MUST appear alphabetically sorted by full command name
- AND descriptions MUST be left-aligned within the 80-char terminal width

#### Scenario: Filtering the listing by sub-namespace

- GIVEN `php occ list mydash:dashboard`
- WHEN the operator runs the command
- THEN only commands starting with `mydash:dashboard:` MUST be shown

#### Scenario: Pre-existing commands are not broken

- GIVEN a pre-existing MyDash command (registered before this spec) uses a different naming convention
- WHEN this spec is implemented
- THEN the pre-existing command MUST still be callable and return the same results
- AND it MUST NOT be forcibly renamed or removed by this change

#### Scenario: New commands must comply with namespace

- GIVEN a developer opens a PR adding a new occ command `occ myd:something`
- WHEN code review checks compliance with this spec
- THEN the PR MUST be rejected unless the command is renamed to `mydash:something`
- NOTE Enforcement is by convention and code review, not runtime validation

#### Scenario: occ list links to --help for each command

- GIVEN an operator sees `mydash:i18n:export-strings` in `php occ list mydash` output
- WHEN they run `php occ mydash:i18n:export-strings --help`
- THEN the detailed help text MUST be displayed, providing a path from discovery to usage
