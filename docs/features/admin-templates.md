# Admin Templates

Admin templates allow Nextcloud administrators to create pre-configured dashboards that are automatically distributed to users based on group membership.

## Features

- Create templates targeting specific Nextcloud groups
- Default templates distributed to all users
- Permission level inherited by user copies
- Widget placements cloned with compulsory flags preserved
- User copies are independent (changes don't affect other users)
- Only one default template allowed at a time

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/templates` | List templates |
| POST | `/api/admin/templates` | Create template |
| GET | `/api/admin/templates/{id}` | Get template |
| PUT | `/api/admin/templates/{id}` | Update template |
| DELETE | `/api/admin/templates/{id}` | Delete template |

## Distribution Flow

1. Admin creates template with target groups and permission level
2. User opens MyDash for the first time
3. System finds applicable template (group-specific first, then default)
4. TemplateService creates personal copy with cloned placements
5. User can customize within permission level constraints

## Screenshot

![Dashboard Overview](../screenshots/mydash-dashboard-overview.png)
