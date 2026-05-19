# Effective default marker

The dashboard switcher sidebar marks the user's *effective default
dashboard* — the one MyDash opens automatically when they visit
`/apps/mydash/` cold — with a small ★ icon and a hover tooltip.

## What you see

A star icon between the dashboard's own icon and its label, on
whichever sidebar row is the user's effective default:

![Sidebar with star marker](/screenshots/tutorials/user/07-default-marker.png)

The marker carries:

- `title="Default dashboard — opens automatically when you visit MyDash"` — browser tooltip on hover
- `aria-label="Default dashboard"` — for screen readers
- `color: var(--color-warning)` — picks up Nextcloud theme overrides

## "Effective default" — the chain

The marker mirrors the resolver's first five steps (REQ-DASH-018):

```
1. defaultUuid (the user's pin)            ← always wins
2. primary-group row with isDefault=1      ← admin-set group default
3. default-group row with isDefault=1      ← org-wide group default
4. first primary-group row                 ← when no flag
5. first default-group row                 ← when no flag
6. first personal dashboard                ← intentionally NOT marked
```

Step 6 is excluded by design — silently starring an arbitrary
personal dashboard the user never marked is more confusing than
helpful. If no pin and no group default applies, no marker renders.

## Setting / clearing the pin

1. Open the sidebar.
2. Click the cog (⚙️) on the row you want as your default.
3. Click **Set as default**.
4. The marker appears next to that row immediately.

To clear: same flow, the menu entry now reads **Default dashboard**
with a filled-star icon. Clicking it again clears the pin.

After clearing, the marker either disappears (if no group default
applies) or moves to the resolver's group fallback.

## Where in the code

| What | File |
|---|---|
| `effectiveDefaultUuid` computed | [DashboardSwitcherSidebar.vue](../../src/components/Workspace/DashboardSwitcherSidebar.vue) |
| `isDefaultDashboard()` helper | same file |
| `<span class="…__default-marker">` rendering | same file (3 row templates: primary-group, default-group, personal) |
| Pin persistence (server) | `setDefaultDashboardPreference()` in `dashboard.js` store |
| Pin endpoint | `POST /api/dashboards/default` |

## Tutorials

- [Set a default dashboard](/docs/tutorials/user/set-default)

## Spec reference

[`openspec/specs/effective-default-marker/spec.md`](../../openspec/specs/effective-default-marker/spec.md)
— REQ-EDM-001 through REQ-EDM-005.
