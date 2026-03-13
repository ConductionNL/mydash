# MyDash — Test Guide

> **Agentic testing (experimental)**: This guide is used by automated browser testing agents. Results are approximate and should be verified manually for critical findings.

## App Access

- **App URL**: `http://localhost:8080/index.php/apps/mydash`
- **Admin Settings**: `http://localhost:8080/settings/admin/mydash`
- **Login**: admin / admin

## What to Test

Read the feature documentation:

- **Docs**: [docs/](docs/) — intro
- **Specs**: [openspec/specs/](openspec/specs/) — admin-settings, admin-templates, conditional-visibility, dashboards, grid-layout, permissions, tiles, widgets

### Features

| Feature | Spec | Key Page / Component |
|---------|------|---------------------|
| Dashboard Grid | grid-layout | Main page — grid-based widget layout |
| Widgets | widgets | Widget picker sidebar — add/remove Nextcloud widgets |
| Custom Tiles | tiles | Tile editor — create shortcut tiles with icons and links |
| Edit Mode | dashboards | Toggle via Customize button (top-right gear icon) |
| Multiple Dashboards | dashboards | Dashboard switcher (top-right, when 2+ dashboards exist) |
| Widget Styling | widgets | Widget style editor — title, background color, icon |
| Permissions | permissions | View-only / add-only / full customization levels |
| Admin Settings | admin-settings | Default permission, grid columns, allow user dashboards |
| Dashboard Templates | admin-templates | Create templates targeted at user groups |
| Conditional Visibility | conditional-visibility | Rules for when widgets appear |

### Navigation Structure

MyDash is a full-screen dashboard app with NO sidebar navigation. Controls are floating in the top-right:
- **Dashboard Switcher** (dropdown, only when 2+ dashboards)
- **Customize** (gear icon → toggles edit mode)
- **Add** (plus icon, only in edit mode → opens widget picker)
- **Documentation** (book icon → opens mydash.app)

### Testing Flow

1. Navigate to the app URL
2. **Initial state**: May show empty state ("No dashboard yet") or existing dashboard
3. **Create dashboard**: If empty, click "Create dashboard" button
4. **Edit mode**: Click the gear icon (Customize) to enter edit mode
5. **Add widget**: Click "Add" → widget picker sidebar opens → search/select a widget
6. **Create tile**: In widget picker, click "Create custom tile" → tile editor modal
7. **Drag & resize**: In edit mode, try dragging widgets to new positions and resizing
8. **Widget style**: Click a widget in edit mode → style editor modal (title, colors)
9. **Exit edit mode**: Click "Close" to return to view mode
10. **Dashboard management**: In widget picker, switch to "Dashboards" tab → create/edit/delete dashboards

### Admin Settings Testing

1. Navigate to `/settings/admin/mydash`
2. **Default permission**: Change dropdown (View only / Add only / Full)
3. **Allow user dashboards**: Toggle checkbox
4. **Allow multiple dashboards**: Toggle checkbox
5. **Grid columns**: Change dropdown (6, 8, 12)
6. **Create template**: Click "Create template" → fill name, description, target groups, permission level → save
7. **Edit/delete template**: Use buttons on template cards

### Key Interactions

- **Gear button**: Toggles edit mode (primary when active, secondary when inactive)
- **Widget picker**: Sidebar with two tabs (Widgets, Dashboards)
- **Tile editor**: Modal with live preview, icon selector, color pickers, link configuration
- **Style editor**: Modal for widget title, background color, icon
- **Dashboard switcher**: Dropdown select to switch between dashboards
- **Documentation button**: Opens mydash.app in new tab

## What NOT to Test

No ROADMAP.md exists — test all features. However, conditional-visibility rules may require specific setup that isn't available in a fresh install.
