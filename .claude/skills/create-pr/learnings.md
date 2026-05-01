# Learnings — create-pr

## Patterns That Work

- **2026-04-13** When the source branch isn't yet on `main` (e.g. stacked PR onto a feature branch), Step 3.4's verify-global-settings-version should compare against the PR target branch, not `main` — comparing against `main` can produce a misleading "file doesn't exist" result and warns about a non-existent missing version bump. Apply the comparison to `{TARGET_BRANCH}` when the relevant directory isn't present on `main`.

## Mistakes to Avoid

- **2026-04-13** Step 0's workspace scan only looks at siblings of the current git repo's parent. When invoked from `/home/wilco/hydra`, it misses repos in `/home/wilco/.github` (which is the user's home, not the workspace). The user had to type the repo name manually via "Other". Consider also scanning common locations like `~/.github` or the additional working directories listed in the environment context.
- **2026-04-13** Step 6 drafted a PR title without a Conventional Commits prefix (`Add settings-repo-ref tracking, harden write blocks, and refresh Claude developer guides`). The `ConductionNL/.github` repo's recent merged PRs (#4, #5, #17) all use `feat:` / `fix:` / `docs:` prefixes. Before drafting the title, run `gh pr list --repo {REMOTE_URL} --state merged --limit 5 --json title` to detect repo title conventions and apply them.
- **2026-04-13** Repeated the unchecked-task-list mistake on `ConductionNL/hydra` PR #7 — drafted `Test plan` with `- [ ]` boxes, which left `task-list-completed` pending. The earlier learning was scoped to `ConductionNL/.github`, but the same status check is active on hydra (and likely all Conduction repos that inherit the org-level app). **Treat unchecked task lists in PR bodies as a global rule, not a per-repo one** — always emit Test plan items as plain bullets (`*` or `-` without `[ ]`).
- **2026-04-13** On `ConductionNL/hydra` PR #7, drafted title `Skill library overhaul, NC builder hardening, and ShellCheck CI` with no Conventional Commits prefix. Recent merged PRs to `development` are mixed (PR #6 has no prefix; PR #3 was a branch-name dump), but PRs to `main` (#1 `docs:`, #2 `feat:`) consistently use prefixes. **Default to a Conventional Commits prefix even for non-`main` PRs** — it costs nothing and is forward-compatible when the branch eventually promotes to `main`.

## Domain Knowledge

- **2026-04-13** The `ConductionNL/.github` repo does not follow the standard `development → beta → main` flow. It only has `main` plus various long-running feature branches (e.g. `feature/claude-code-tooling`). Stacked PRs onto feature branches are normal here; the recommended-target table in Step 3 doesn't apply cleanly.
- **2026-04-13** The `ConductionNL/.github` repo's only CI workflow (`documentation.yml`) only triggers on PRs to the `documentation` branch, not on PRs to `main` or feature branches. There are essentially no automatic CI checks for typical PRs in this repo.
- **2026-04-13** `gh api repos/{owner}/{repo}/rulesets` returns `[]` when no repo-level rulesets are configured — branch protection on `ConductionNL/.github` (if any) is not via the rulesets API.
- **2026-04-13** The `task-list-completed` status check (from stilliard/github-task-list-completed) is configured at the **org level** for ConductionNL — it appears on `ConductionNL/.github` AND `ConductionNL/hydra` PRs (and probably every Conduction repo). It scans the PR body and stays pending/fails if any `- [ ]` task box is unchecked. Because it's org-wide, the create-pr skill's default `Test plan` template MUST use plain bullets, not unchecked task boxes. Check the org check once with `gh api repos/{owner}/{repo}/commits/{sha}/check-runs` on a recent PR if unsure.
- **2026-04-13** Hydra (`ConductionNL/hydra`) runs three workflows on PRs: `Hydra — ShellCheck` (always, on path filter), `Hydra — Review` with `Check branch` filter (always — but `Code Reviewer` / `Security Reviewer` / `Builder fix` jobs are SKIPPED unless the source branch starts with `hydra/`). For human-authored PRs, only ShellCheck and the filter job actually do work — the rest are intentional skips, not failures.
- **2026-04-13** `ConductionNL/.github` PR titles follow Conventional Commits: `feat:`, `fix:`, `docs:` (capitalized first word after the colon). Examples from merged PRs: `feat: Switch CI integration tests from SQLite to PostgreSQL` (#17), `fix: only generate SBOM on protected branches, only commit on main` (#4), `docs: Update CONTRIBUTING.md to reflect org-wide branch protection rulesets` (#5).

## Open Questions

- ~~**2026-04-13** Should Step 3.4 (verify-global-settings-version) be wired to compare against `{TARGET_BRANCH}` rather than `origin/main` when called from within create-pr? Currently the standalone skill compares against `main` only.~~ **Resolved 2026-04-14:** Yes — create-pr now passes `{TARGET_BRANCH}` to the verify skill. Standalone invocation reads `~/.claude/settings-repo-ref` (falls back to `origin/main`).

## Consolidated Principles
