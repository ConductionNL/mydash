---
sidebar_position: 5
title: Edit widget content & style
description: Change a widget's content, colours, custom title, or border without removing it.
---

# Edit widget content & style

Each placement carries two layers of configuration:

- **Content** — type-specific fields (text body, link URL, folder path, …). Changes the widget's payload.
- **Style** — borders, background colour, custom title override, custom icon override. Cosmetic.

Both are editable post-add without removing the widget.

## Goal

Edit a widget you already added — both its content and its visual style.

## Prerequisites

- A dashboard you can edit, with at least one widget on it.

## Steps

### 1. Right-click the widget

In edit mode, right-clicking a widget opens a context menu anchored at the cursor:

![Right-click context menu](/screenshots/tutorials/user/05-context-menu.png)

Options:
- **Edit** — opens the per-type configuration form (same as during add).
- **Style** — opens the cosmetic style editor.
- **Remove** — see [Remove a widget](06-remove-widget.md).
- **Cancel** — close the menu.

### 2. Edit content

Pick **Edit**. The same `AddWidgetModal` you used to add the widget reopens, this time pre-filled with the current placement's content. Change fields and **Save**.

![Edit content modal](/screenshots/tutorials/user/05-edit-content.png)

### 3. Edit style

Pick **Style** instead. The dedicated `WidgetStyleEditor` opens with these controls:

- **Custom title** — overrides the widget's default title (leave blank for default).
- **Custom icon** — registry key, URL, or empty for default.
- **Show title** — toggle the title bar on/off.
- **Border** — colour and thickness.
- **Background colour** — solid, transparent, or theme-bound.

![Style editor](/screenshots/tutorials/user/05-style-editor.png)

The style is persisted as a JSON blob in `placement.styleConfig`; it doesn't touch the widget's content.

### 4. Save

Both modals close on **Save** and the change is reflected immediately.

## Verification

- Reload the page. Content / style changes are still applied.
- The widget header reflects any custom title; widget background reflects any custom colour.

## Common issues

| Symptom | Fix |
|---|---|
| **Edit** is disabled | The widget type has no configuration form (renderer-only widgets). Use **Style** for cosmetics, or remove and re-add. |
| Custom icon doesn't render | The icon string isn't a registered registry key and not a valid URL. See [Dashboard icons capability](../../features/dashboards.md). |
| Title row gone after toggling **Show title** | Re-open the style editor and toggle it back on, OR set a custom title. |

## Reference

- [Widgets feature reference](../../features/widgets.md)
