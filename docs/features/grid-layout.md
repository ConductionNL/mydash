# Grid Layout

The grid layout system powers the drag-and-drop dashboard experience in MyDash, built on GridStack 10.3.1.

## Features

- 12-column responsive grid (configurable per dashboard)
- Cell height: 80px with 12px margins
- View mode (static) and edit mode (drag-and-drop)
- Float mode enabled (items stay at exact position)
- Minimum widget size: 2 columns wide, 2 rows tall
- Position changes emitted via Vue events
- 0-based coordinate system

## Configuration

| Setting | Value |
|---------|-------|
| Library | GridStack 10.3.1 |
| Default columns | 12 |
| Cell height | 80px |
| Margins | 12px |
| Float mode | Enabled |
| Animation | Enabled |
| Min size | 2x2 |

## Screenshot

![Grid Layout](../screenshots/mydash-dashboard-overview.png)
