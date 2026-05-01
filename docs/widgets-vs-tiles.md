# Widgets vs Tiles in MyDash

## Overview

MyDash supports two distinct types of dashboard items: **Widgets** and **Tiles**. Understanding the difference between them is crucial for effective use of the application.

## Widgets

### What are Widgets?

**Widgets** are dynamic, interactive dashboard components provided by Nextcloud core and other Nextcloud apps. They display real-time data and can be interacted with.

### Characteristics:

1. **Source**: Provided by Nextcloud apps through the Dashboard API (`IWidget` interface)
2. **Dynamic Content**: Display live data that can refresh automatically
3. **API-Driven**: Use Nextcloud's Dashboard API (`IAPIWidget`, `IAPIWidgetV2`)
4. **Interactive**: Can display lists of items, buttons, and actions
5. **Standardized**: Follow Nextcloud's widget interface specifications
6. **Examples**:
   - Files widget (recent files)
   - Calendar widget (upcoming events)
   - Activity widget (recent activity)
   - Mail widget (unread messages)
   - Weather widget
   - News widget

### Technical Implementation:

- **Backend**: Uses Nextcloud's `IManager` Dashboard Manager
- **Registration**: Automatically registered by apps implementing `IWidget` interface
- **Data Source**: Calls `getItems()` or `getItemsV2()` methods from widget classes
- **Location**: Defined in Nextcloud apps (e.g., `lib/Dashboard/FilesWidget.php`)

### Widget Features:

- Display multiple items (configurable limit, default 7)
- Support buttons with links
- Can have custom reload intervals
- Support various API versions
- Can display empty state messages
- Styling can be customized per placement

## Tiles

### What are Tiles?

**Tiles** are custom, user-created shortcuts that link to apps or URLs. They are simple, static navigational elements.

### Characteristics:

1. **Source**: Created by users through the MyDash interface
2. **Static Content**: Display a title and icon only
3. **User-Managed**: Users can create, edit, and delete their own tiles
4. **Simple Links**: Navigate to apps or external URLs
5. **Customizable**: Icon, colors, title, and link can be customized
6. **Examples**:
   - Quick link to Files app
   - Link to Calendar app
   - External URL (GitHub, Google Docs, etc.)
   - Link to custom Nextcloud apps

### Technical Implementation:

- **Backend**: Custom database table (`oc_mydash_tiles`)
- **API**: RESTful API endpoints (`TileApiController`)
- **Storage**: Persisted in MyDash database
- **Component**: `TileCard.vue` and `TileWidget.vue`

### Tile Features:

- **Icon Types**:
  - CSS class (e.g., `icon-files`)
  - SVG path data (Material Design Icons)
  - Image URL
  - Emoji
- **Customization**:
  - Title
  - Background color
  - Text color
  - Icon
  - Link type (app or URL)
  - Link value

### Tile Properties:

```javascript
{
  id: 1,
  title: 'Files',
  icon: 'icon-files',  // or SVG path, URL, emoji
  iconType: 'class',   // or 'svg', 'url', 'emoji'
  backgroundColor: '#0082c9',
  textColor: '#ffffff',
  linkType: 'app',     // or 'url'
  linkValue: 'files',  // app ID or full URL
  userId: 'admin'
}
```

## Key Differences

| Feature | Widgets | Tiles |
|---------|---------|-------|
| **Origin** | Provided by Nextcloud apps | Created by users |
| **Content** | Dynamic, data-driven | Static, navigational |
| **Data** | Live data (files, events, etc.) | Title + Icon only |
| **Interactivity** | Can display lists, buttons | Simple link/button |
| **Customization** | Limited (styling only) | Full (icon, colors, link) |
| **Management** | System-managed | User-managed (CRUD) |
| **API** | Nextcloud Dashboard API | MyDash custom API |
| **Registration** | Auto-registered by apps | Created via UI |
| **Database** | No persistence (transient) | Stored in `oc_mydash_tiles` |

## How They Work Together

### In the Dashboard Grid:

1. **Widgets** are placed in grid cells and wrapped by `WidgetWrapper.vue`
   - Render using `WidgetRenderer.vue`
   - Display dynamic content from Nextcloud apps
   - Can show multiple items, buttons, and actions

2. **Tiles can be displayed in two ways**:
   - **As standalone tiles** in a dedicated section (rendered by `TileCard.vue`)
   - **As a widget** through a special "Tiles" widget type (rendered by `TileWidget.vue`)

### Widget Picker:

The "Add to dashboard" panel has two tabs:

1. **Widgets Tab**: Shows all available Nextcloud widgets
2. **Tiles Tab**: Shows user-created tiles + "Create Tile" button

## Example: Files

### Files as a Widget:

```
┌─────────────────────────────┐
│ 📁 Files                    │
├─────────────────────────────┤
│ • document.pdf  2 hours ago │
│ • image.jpg     Yesterday   │
│ • report.docx   2 days ago  │
│ [View all files]            │
└─────────────────────────────┘
```

- Provided by Files app
- Shows recent files
- Updates automatically
- Has action buttons

### Files as a Tile:

```
┌───────────┐
│     📁    │
│   Files   │
└───────────┘
```

- Created by user
- Simple link to Files app
- Static, no data
- Customizable colors and icon

## Use Cases

### When to Use Widgets:

- Display live, changing data
- Show recent activity or updates
- Provide quick actions (mark as read, open, etc.)
- Monitor system status
- View aggregated information

### When to Use Tiles:

- Quick navigation to apps
- Bookmarks to external services
- Custom shortcuts
- Frequently accessed URLs
- Organizing apps by category/priority

## API Endpoints

### Widgets:

- `GET /apps/mydash/api/widgets` - List available widgets
- `GET /apps/mydash/api/widgets/items` - Get widget items
- `POST /apps/mydash/api/dashboard/{dashboardId}/widgets` - Add widget
- `PUT /apps/mydash/api/widgets/{placementId}` - Update widget placement
- `DELETE /apps/mydash/api/widgets/{placementId}` - Remove widget

### Tiles:

- `GET /apps/mydash/api/tiles` - List user's tiles
- `POST /apps/mydash/api/tiles` - Create new tile
- `PUT /apps/mydash/api/tiles/{id}` - Update tile
- `DELETE /apps/mydash/api/tiles/{id}` - Delete tile

## Best Practices

### For Users:

1. **Use Widgets** for apps you actively monitor (mail, calendar, activity)
2. **Use Tiles** for apps you frequently access but don't need to monitor
3. Mix both types to create an effective dashboard
4. Group related items together
5. Use custom colors for tiles to create visual categories

### For Developers:

1. **Implement IWidget** in your app to provide rich, data-driven widgets
2. **Don't create tiles programmatically** - let users create their own
3. Follow Nextcloud's Dashboard API standards for widgets
4. Support both API v1 and v2 for broader compatibility

## Summary

- **Widgets** = Dynamic, data-driven components from Nextcloud apps
- **Tiles** = Simple, user-created navigation shortcuts
- Both can coexist on the same dashboard
- They serve different purposes and complement each other
- MyDash provides the framework to manage and display both types effectively
