<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# MyDash end-to-end tests (Playwright)

This directory holds the spec-derived Playwright suite for the mydash app.
Each `*.spec.ts` file maps to a single OpenSpec change in
`openspec/changes/<change>/` — the comment header at the top of every spec
records which `REQ-*` requirements it covers.

## Quick start

```bash
# Install once (from repo root)
npm install
npx playwright install chromium

# Run the whole suite (headless, list reporter)
npm run test:e2e

# Interactive (filter, retry, time-travel)
npm run test:e2e:ui

# See the browser
npm run test:e2e:headed
```

## Required environment

| Var               | Default                  | Purpose                                                                |
|-------------------|--------------------------|------------------------------------------------------------------------|
| `NC_BASE_URL`     | `http://localhost:8080`  | Nextcloud test instance (the standard `nextcloud-docker-dev` setup).   |
| `NC_ADMIN_USER`   | `admin`                  | An admin account that can install widgets and create dashboards.       |
| `NC_ADMIN_PASS`   | `admin`                  | Password for `NC_ADMIN_USER`.                                          |

The mydash app must be installed and enabled in the target instance.
The mounted source must match the branch under test — see the next
section for why.

## How auth works

`global-setup.ts` runs once per `playwright test` invocation:

1. Verifies `${NC_BASE_URL}/status.php` reports `installed: true`.
2. Drives the Nextcloud `/index.php/login` form with admin credentials.
3. Waits for the post-login `#header` to appear (URL-based waits race
   the in-flight click navigation; selector-based is reliable across
   NC 28/29/30).
4. Persists the resulting cookie + storage state to
   `tests/e2e/.auth/admin.json`.

`playwright.config.ts` then sets `use.storageState` to that file so every
spec inherits the authenticated session and starts directly inside the
mydash app via `page.goto('/index.php/apps/mydash')`.

`tests/e2e/.auth/` is `.gitignore`d — never commit harvested sessions.

## Configuration choices

- **Single worker** (`workers: 1`). The Nextcloud test environment is
  single-tenant and many specs mutate shared state (active dashboard,
  default-dashboard flag, group membership). Parallel runs hit race
  conditions you cannot reproduce locally.
- **No `webServer` block**. The Docker container *is* the server. Driving
  it with Playwright's `webServer` would tear it down between runs and
  destroy other agents' state.
- **`trace: 'on-first-retry'`**, **`screenshot: 'only-on-failure'`**,
  **`video: 'retain-on-failure'`** — generous artefacts on the first
  failed run, near-zero overhead on a green run.

## Spec inventory

Only **four** spec files exist on `development` (PR #99). Per-feature
worktrees on the implementation branches each carry their own copy of
`label-widget.spec.ts` (the canonical template), but only the four below
were merged forward as distinct e2e files:

| Spec                                    | OpenSpec change                | Cases | REQ tags                                |
|-----------------------------------------|--------------------------------|-------|-----------------------------------------|
| `label-widget.spec.ts`                  | `label-widget`                 | 2     | REQ-LBL-001, REQ-LBL-005, REQ-LBL-007   |
| `image-widget.spec.ts`                  | `image-widget`                 | 3     | REQ-IMG-002, REQ-IMG-003, REQ-IMG-005   |
| `text-display-widget.spec.ts`           | `text-display-widget`          | 3     | REQ-TXT-001, REQ-TXT-003, REQ-TXT-004   |
| `responsive-grid-breakpoints.spec.ts`   | `responsive-grid-breakpoints`  | 6     | REQ-GRID-007, REQ-GRID-012, REQ-GRID-013|

Total: **14 cases across 4 files** (plus `image-widget.spec.ts` REQ-IMG-002
which already self-skips with `test.skip()` pending a programmatic seed
helper — it counts toward the 14 but never executes).

## First-run triage

Run on `feature/playwright-e2e-wiring` against the standard
`nextcloud-docker-dev` container at `localhost:8080`.

**Result: 0 passing / 14 failing — all blocked by a single environmental
prerequisite, not by spec defects.**

Every failure traces to the same 500 from `PageController::index()`:

```
Class "OCA\MyDash\Service\InitialStateBuilder" not found in
'/var/www/html/custom_apps/mydash/lib/Controller/PageController.php' line 185
```

`InitialStateBuilder` was added by the `initial-state-contract` change
(merged into `development` via PR #99 commit `dd574c7`). The mydash
mount in the running container points at the original submodule, which
is on `feature/replica-spec-proposals` — a branch that **predates** the
contract introduction. Until the running app sources match the branch
under test, every spec fails at `page.goto('/index.php/apps/mydash')`
because the app itself returns a 500 to authenticated requests.

### To unblock

Either:

1. **Switch the mount to `development`** (recommended for CI):
   ```bash
   cd /home/.../apps-extra/mydash
   git checkout development     # carefully — other agents may be on this submodule
   docker exec nextcloud apache2ctl graceful   # clear OPcache
   ```

2. **Run against this worktree directly** by mounting it instead:
   adjust `.github/docker-compose.yml` to bind
   `/tmp/worktrees/mydash-playwright-wiring` → `/var/www/html/custom_apps/mydash`,
   then restart the container.

The user explicitly asked the wiring task NOT to mutate other working
trees, so neither remediation is performed here.

### Once the env is aligned (forward-looking)

After the install matches `development`, the next-step triage will most
likely produce:

- **`label-widget` (2 cases)** — should pass; the form selectors
  (`Label text`, `Font size`, `<input type="color">`) are stable strings
  in `LabelForm.vue`.
- **`image-widget` (3 cases)** — REQ-IMG-005 (file upload) and REQ-IMG-003
  (external URL + click-through new-tab) should pass. REQ-IMG-002
  (placeholder) is already `test.skip()` pending a programmatic seed
  helper. Note the upload case relies on `tests/e2e/fixtures/tiny.png`
  which is created in this PR.
- **`text-display-widget` (3 cases)** — the third case (`REQ-TXT-003`,
  empty placeholder) calls `POST /apps/mydash/api/widget-placements` from
  the page context; that endpoint requires CSRF + a writeable dashboard.
  Likely needs a fixture for "default dashboard exists" — flag if it
  fails.
- **`responsive-grid-breakpoints` (6 cases, 5 column-count + 1 visual)** —
  the column-count assertions inspect `gridstack.opts.column` directly
  on the DOM node. They depend on the dashboard rendering at least one
  widget so `.grid-stack` is mounted. The visual regression case will
  generate fresh baselines on first run.

## Author guidelines

- One spec file per OpenSpec change, named after the change folder.
- The header comment must list which `REQ-*` requirements the file covers
  (mirrors the trace the spec already records — see existing files).
- Use `test.skip(true, '<reason>')` when a case needs fixture data the
  cohort cannot yet seed; **never** rewrite the case to dodge the gap.
  Skipped cases are visible in the report and easy to find later.
- Use `test.fixme()` when a case surfaces a real product bug — the suite
  still records the failure for follow-up but the run stays green.
- Selectors should prefer accessible roles (`getByRole`, `getByLabel`)
  over CSS classes; classes only when no semantic alternative exists
  (e.g. `.label-widget`, `.grid-stack` — these are component contract
  surfaces).
