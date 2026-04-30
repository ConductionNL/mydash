# Permission Levels - Design Document

## Architecture

### Backend
- **Service**: `Service\PermissionService` - Central permission checking
- **Constants**: `Dashboard::PERMISSION_VIEW_ONLY`, `PERMISSION_ADD_ONLY`, `PERMISSION_FULL`

### Permission Matrix
| Level | View | Add widgets | Edit settings | Move/resize | Remove non-compulsory | Remove compulsory |
|-------|------|-------------|---------------|-------------|----------------------|-------------------|
| view_only | Yes | No | No | No | No | No |
| add_only | Yes | Yes | Yes | Yes | Yes | No |
| full | Yes | Yes | Yes | Yes | Yes | Yes |

### Methods
- `canEditDashboard()` - add_only or full
- `canEditDashboardMetadata()` - any owner (metadata not restricted by permission)
- `canAddWidget()` - add_only or full
- `canRemoveWidget()` - full always, add_only for non-compulsory only
- `canStyleWidget()` - add_only or full
- `canCreateDashboard()` - checks admin setting
- `canHaveMultipleDashboards()` - checks admin setting
- `getEffectivePermissionLevel()` - resolves from template -> dashboard -> default

### Key Design Decisions
- Permission level inherited from admin template via basedOnTemplate
- If template deleted, falls back to dashboard's own level
- Metadata editing (name, description) not restricted by permission level
- Compulsory flag on placements prevents removal at add_only level
