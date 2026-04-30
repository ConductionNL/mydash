# Admin Settings - Design Document

## Architecture

### Backend
- **Entity**: `Db\AdminSetting` - Key-value pair with JSON-encoded values
- **Mapper**: `Db\AdminSettingMapper` - getAllAsArray, setSetting, getValue
- **Service**: `Service\AdminSettingsService` - Get/update settings with defaults
- **Controller**: `Controller\AdminController` - Admin-only REST endpoints

### Frontend
- **Component**: `components/admin/AdminSettings.vue` - Settings form UI
- **Entry**: `admin.js` - Admin page entry point

### Settings
| Key (DB) | API Key | Type | Default |
|----------|---------|------|---------|
| allow_user_dashboards | allowUserDashboards | boolean | true |
| allow_multiple_dashboards | allowMultipleDashboards | boolean | true |
| default_permission_level | defaultPermissionLevel | string | add_only |
| default_grid_columns | defaultGridColumns | integer | 12 |

### Key Design Decisions
- Settings stored as JSON-encoded values in dedicated table
- DB uses snake_case keys, API returns camelCase keys
- Factory defaults applied in service layer (not DB)
- Admin-only access enforced by @AdminRequired annotation
