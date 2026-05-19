---
sidebar_position: 6
title: Remove a widget
description: Take a widget off the dashboard without deleting the dashboard itself.
---

# Remove a widget

Removing a widget deletes its placement row (`oc_mydash_widget_placements`) but doesn't touch the dashboard or the widget's underlying data.

## Goal

Remove one widget from a dashboard.

## Prerequisites

- A dashboard you can edit.
- The widget must not be marked **compulsory** by an admin template (compulsory widgets can only be removed by an admin).

## Steps

### 1. Right-click the widget

In edit mode, right-click anywhere on the widget. The context menu opens at the cursor.

![Right-click context menu](/screenshots/tutorials/user/05-context-menu.png)

### 2. Click **Remove**

The menu auto-closes and the placement disappears from the grid. The DELETE call fires immediately; there is no undo.

![After remove](/screenshots/tutorials/user/06-after-remove.png)

:::caution
The remove is destructive. If you're unsure, [edit the style](05-edit-content.md) and toggle **Show title** off — it hides the placement without deleting it.
:::

## Verification

- The widget is gone from the grid.
- Reload — it stays gone (the row was deleted server-side).

## Common issues

| Symptom | Fix |
|---|---|
| **Remove** is greyed out | The widget is `isCompulsory=1` on this dashboard (admin-pinned). Ask your admin to lift it. |
| Removing throws "permission denied" | Your permission level on the dashboard is `view_only`. |

## Reference

- [Widgets feature reference](../../features/widgets.md)
- [Permissions reference](../../features/permissions.md)
