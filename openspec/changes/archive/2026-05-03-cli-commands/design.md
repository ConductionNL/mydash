# Design — CLI Commands

## Context

Operators need CLI access for tasks impractical through the web UI: scripted dashboard imports,
i18n string extraction, bulk cleanup, and diagnostics in headless environments. Without a
defined contract, operators resort to one-off scripts that break across upgrades.

The platform's console framework (`OC\Core\Command\Base`) provides registration, argument
parsing, and output formatting. A consistent namespace prefix, uniform flags, and a documented
exit-code contract make the surface predictable for shell scripts, CI pipelines, and monitoring
systems that branch on process exit codes.

Any command that reads or mutates data must leave a log trace. Silent failures or partial
successes in cleanup and import operations can leave the installation in an inconsistent state.

## Goals / Non-Goals

**Goals:**
- All MyDash CLI commands registered under the `mydash:` namespace.
- Consistent global flags across every command.
- A documented exit-code contract (0–5).
- Structured JSON output mode for scripting consumers.
- A log line emitted on completion of every command.
- Full discoverability via `php occ list mydash`.

**Non-Goals:**
- Interactive TUI / wizard-style commands.
- Commands that bypass the service layer and write directly to the database.
- Commands executable by non-admin platform users.

## Decisions

### D1: Namespace and naming convention
**Decision:** All commands use the `mydash:` prefix with a verb-noun-style suffix separated
by colons where a sub-domain is needed — e.g. `mydash:dashboard:list`,
`mydash:cleanup:scan`, `mydash:i18n:export-strings`.
**Alternatives considered:** Flat names without sub-namespaces (`mydash:list-dashboards`);
no namespace prefix (relying solely on class registration).
**Rationale:** The colon-separated sub-namespace groups related commands in `occ list` output
and prevents name collisions as the command surface grows.

### D2: Global flags
**Decision:** Every command accepts `--quiet` (suppress non-error output), `--json` (emit
structured JSON instead of human-readable tables), and `--no-interaction` (disable any
confirmation prompts, error on missing required args).
**Alternatives considered:** Per-command flag definitions only; use the platform's built-in
`-q` shorthand exclusively.
**Rationale:** Uniform flags allow operators to write generic wrapper scripts that apply the
same flags regardless of which `mydash:*` command they invoke. `--json` is especially
important for CI consumers that parse output.

### D3: Exit-code contract
**Decision:** `0` success, `1` generic error, `2` invalid args, `3` permission denied,
`4` not found, `5` partial success.
**Alternatives considered:** Only `0` and `1`; undocumented platform codes.
**Rationale:** Distinct codes let monitoring scripts take different actions per error class;
`5` is essential for bulk commands where aborting entirely is worse than partial completion.

### D4: JSON output schema
**Decision:** `--json` emits one object to stdout:
`{success, exitCode, data: <command-specific>, errors: [string]}`. Warnings go to stderr.
**Alternatives considered:** NDJSON per item; per-command JSON shapes.
**Rationale:** A common envelope lets generic consumers check `success` without knowing the
`data` shape; bulk output fits as an array inside `data`.

### D5: Registration via info.xml
**Decision:** Commands declared in `appinfo/info.xml` `<commands>` block, each pointing to
the implementing class name.
**Alternatives considered:** Dynamic registration in `Application::register()`.
**Rationale:** `info.xml` is the canonical manifest; commands declared there are visible to
`occ list` without requiring a full app bootstrap.

### D6: Audit logging
**Decision:** Every command emits one `INFO` log line on completion:
`[mydash] cli <cmd> exitCode=N durationMs=M byUser=<uid>`.
**Alternatives considered:** Log only on failure; dedicated audit table.
**Rationale:** One line per command is low-noise and routes through the platform log backend
without requiring a separate table migration.

### D7: Discoverability requirement
**Decision:** `php occ list mydash` MUST list every `mydash:*` command with a one-line
description. Missing description = code review failure.
**Alternatives considered:** No explicit requirement.
**Rationale:** An undocumented command is invisible to operators; the description requirement
enforces discoverability at review time.

## Risks / Trade-offs

- Commands run as the web server user; operator tooling must not assume write access to paths
  outside the platform data directory.
- `--no-interaction` disables confirmation prompts — callers accept responsibility for
  destructive operations running unattended.
- Exit code `5` (partial success) requires CI pipeline authors to handle non-zero exits that
  are not failures.

## Open follow-ups

- Define a `mydash:doctor` command for self-checks (database, file-store, lock health).
- Add `--output-file=<path>` to commands with large binary output to avoid polluting stdout.
- Evaluate a `mydash:shell` REPL for interactive debugging in production environments.
