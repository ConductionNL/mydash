# Permission Levels

Permission levels control what users can do with their dashboards, especially for dashboards created from admin templates.

## Permission Matrix

| Level | View | Add widgets | Edit settings | Move/resize | Remove non-compulsory | Remove compulsory |
|-------|------|-------------|---------------|-------------|----------------------|-------------------|
| `view_only` | Yes | No | No | No | No | No |
| `add_only` | Yes | Yes | Yes | Yes | Yes | No |
| `full` | Yes | Yes | Yes | Yes | Yes | Yes |

## Features

- Permission level inherited from admin template
- Falls back to dashboard's own level if template deleted
- Metadata editing (name, description) not restricted by permission level
- Compulsory widgets cannot be removed at `add_only` level
- Admin settings define default permission level for new dashboards

## Screenshot

![Dashboard Overview](../screenshots/mydash-dashboard-overview.png)
