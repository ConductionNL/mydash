# MyDash Review

**Date:** 2026-03-21
**Reviewer:** Claude (automated)
**App path:** `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/mydash`

---

## 1. OpenSpec Status

**Result: CLEAN -- all changes archived, specs complete.**

| Metric | Count |
|--------|-------|
| Specs in `openspec/specs/` | 9 |
| Active changes | 0 |
| Archived changes | 9 |

All 9 specs have been implemented and archived:

1. `admin-settings` -- Admin configuration panel
2. `admin-templates` -- Group-based dashboard templates
3. `conditional-visibility` -- Rule-based widget show/hide
4. `dashboards` -- Core dashboard CRUD
5. `grid-layout` -- GridStack drag-and-drop layout
6. `permissions` -- Permission levels (add-only, full, etc.)
7. `prometheus-metrics` -- Prometheus-format metrics endpoint
8. `tiles` -- Custom shortcut tiles (link cards)
9. `widgets` -- Nextcloud widget API integration (v1 + v2)

Each spec directory contains `spec.md`. Each archive contains `proposal.md`, `design.md`, `specs/`, and `tasks.md`.

---

## 2. Unit Test Results

**Result: ALL PASSING**

```
PHPUnit 10.5.63
OK (104 tests, 291 assertions)
Time: 00:00.150, Memory: 36.00 MB
```

Test config: `phpunit.xml` (note: `phpunit-unit.xml` does not exist -- the review task template assumed it did).

Test coverage spans:
- Dashboard entity and mapper
- WidgetPlacement entity (grid position, widget ID, style config, tile fields, JSON serialization)
- Tile entity (icon type, colors, link type/value, timestamps, serialization)
- VisibilityChecker service (include/exclude rules, OR/AND logic, mixed rules)
- DashboardTemplate entity and mapper
- Permission-related logic

No warnings, no skipped tests.

---

## 3. Browser Test Results

### Main Dashboard (`/apps/mydash/`)

**Result: RENDERS SUCCESSFULLY with some Vue warnings**

The dashboard loads and displays:
- **Grid layout** with multiple widget tiles arranged in a responsive grid
- **Client Search** widget (Pipelinq) -- renders with placeholder avatars ("?"), data fetch fails (HTTP error for OpenRegister objects endpoint, expected since those objects may not exist)
- **Files** tile -- shortcut link to /apps/files, renders correctly with icon
- **Recommended files** widget -- renders with 7 file items (UUIDs from Open Registers), links work
- **Cards due today** widget (Deck) -- renders, shows "No upcoming cards"
- **Recommended files** (second instance) -- duplicate widget placement, renders identically
- **Upcoming events** widget (Calendar) -- renders with "Example event - open me!" item
- **Customize** button -- visible, opens a sidebar panel with:
  - Widget search/add panel with search box
  - Available widgets listed (Favorite files, Teams, Important mail, etc.)
  - "Already added" indicators for active widgets
  - "Create Tile" button
  - Tabs for "Widgets" and "Dashboards"
- **Documentation** button -- visible alongside Customize

**Console issues (MyDash-specific):**
- `[Vue warn]: Invalid prop: type check failed` -- repeated ~15 times across widget items (likely widget item `subtitle` prop receiving wrong type)
- `[Vue warn]: Duplicate keys detected` -- duplicate key `1773...` in recommended files list
- `[NcModal] You need either set...` -- NcModal missing required prop
- `[NcSelect] An inputLabel or...` -- NcSelect accessibility warning (2 occurrences in admin)
- `[vue-select warn]: Label key "option.Ico..."` -- label key mismatch in a select component

These are Vue warnings, not blocking errors. The app remains functional.

### Admin Settings (`/settings/admin/mydash`)

**Result: RENDERS CORRECTLY**

The admin settings page displays:
- **Title:** "MyDash Settings" with external documentation link to mydash.app
- **Subtitle:** "Configure dashboard permissions and defaults"
- **Default settings section:**
  - Default permission level dropdown (set to "Add only")
  - Checkbox: "Allow users to create custom dashboards" (checked)
  - Checkbox: "Allow users to have multiple dashboards" (checked)
  - Default grid columns dropdown (set to "12")
- **Dashboard templates section:**
  - "Create template" button
  - Description: "Create dashboard templates that will be applied to users based on their groups."
  - Empty state: "No templates yet"
- **Setting as default app section:**
  - Instructions to set MyDash as default via Theming settings

---

## 4. Documentation Status

### Feature Docs (`docs/features/`)

**Result: 9 feature docs present, all with content**

| File | Lines | Topic |
|------|-------|-------|
| admin-settings.md | 29 | Global admin config |
| admin-templates.md | 34 | Group-based templates |
| conditional-visibility.md | 32 | Rule-based visibility |
| dashboards.md | 28 | Core dashboard unit |
| grid-layout.md | 29 | GridStack layout system |
| permissions.md | 23 | Permission levels |
| prometheus-metrics.md | 36 | Metrics endpoint |
| tiles.md | 25 | Custom shortcut tiles |
| widgets.md | 26 | Widget API integration |

### Screenshots (`docs/screenshots/`)

**Result: 1 screenshot present**

| File | Size |
|------|------|
| mydash-dashboard-overview.png | 140,246 bytes |

The screenshot is valid (non-zero, confirmed viewable).

### Other Docs

- `project.md` exists at repo root (3,291 bytes)
- `docs/` also contains a `node_modules/` directory (likely from a documentation site build tool like Docusaurus/Storybook) -- this should ideally be in `.gitignore`

---

## 5. Issues Found

### Blocking Issues
None. The app loads, renders, and all tests pass.

### Non-Blocking Issues

1. **Vue prop type warnings (MEDIUM):** ~15 `Invalid prop: type check failed` warnings in console. These are likely `subtitle` or similar string props receiving non-string values from widget item data. Should be fixed for cleanliness.

2. **Duplicate keys in widget list (LOW):** `Duplicate keys detected: '1773...'` -- likely the same recommended file appearing twice in the v-for list. Needs a unique key strategy.

3. **NcSelect accessibility warning (LOW):** Two NcSelect components in admin settings missing `inputLabel` prop. Easy fix.

4. **NcModal prop warning (LOW):** Modal component missing a required prop setup.

5. **`docs/node_modules/` committed or present (LOW):** The `docs/` directory contains a full `node_modules/` tree. If committed to git, this bloats the repository. Should be added to `.gitignore`.

6. **Missing `phpunit-unit.xml` (COSMETIC):** The standard Conduction app pattern uses `phpunit-unit.xml` but MyDash uses `phpunit.xml`. Not a problem functionally, but inconsistent with other apps.

7. **Client Search widget data error (EXPECTED):** The Pipelinq Client Search widget fails to fetch from `/api/objects/228/498` -- this is expected when the referenced register/schema does not exist in the current environment.

---

## 6. Codebase Size

| Category | Count |
|----------|-------|
| PHP files (`lib/`) | 64 |
| Vue/JS/TS files (`src/`) | 19 |
| Unit tests | 104 |
| Assertions | 291 |

---

## 7. Overall Assessment

**Status: GOOD -- production-ready with minor polish needed**

MyDash is in solid shape. All 9 OpenSpec features have been implemented, spec'd, and archived. The unit test suite is comprehensive (104 tests, 291 assertions, all passing). The dashboard renders correctly with a functional grid layout, widget rendering (both API and legacy callback widgets), tile shortcuts, a customize sidebar, and admin settings with permission controls and template management.

The main areas for improvement are:
- Fix the ~15 Vue prop type warnings to clean up the console
- Add `inputLabel` to NcSelect components for accessibility compliance
- Ensure `docs/node_modules/` is gitignored
- Consider adding more screenshots to `docs/screenshots/` to document the customize panel, admin settings, and template creation flows
