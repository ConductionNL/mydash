# MyDash - Project Handover Report

## Overview

MyDash is an enhanced dashboard application for Nextcloud that provides a grid-based layout system with drag-and-drop functionality, widget customization, and administrative controls. It maintains full compatibility with existing Nextcloud dashboard widgets.

**Version:** 1.0.0
**Status:** Backend complete, frontend built, app enabled
**Access URL:** http://localhost:8080/apps/mydash/
**Admin Settings:** http://localhost:8080/settings/admin/mydash

---

## Goals & Features

### Primary Goals

1. **Enhanced Widget Layout** - Replace the fixed Nextcloud dashboard layout with a flexible grid system
2. **Widget Customization** - Allow users to style widgets (colors, borders, titles)
3. **Admin Control** - Enable administrators to create dashboard templates and control user permissions
4. **Full Compatibility** - Work with all existing Nextcloud dashboard widgets

### Feature List

| Feature | Status | Description |
|---------|--------|-------------|
| Grid Layout | Implemented | 12-column responsive grid using gridstack.js |
| Drag & Drop | Implemented | Move widgets by dragging |
| Widget Resizing | Implemented | Resize widgets by dragging edges |
| Multiple Dashboards | Implemented | Users can create/switch between dashboards |
| Widget Styling | Implemented | Background color, borders, padding, custom titles |
| Title Visibility | Implemented | Show/hide widget titles |
| Admin Templates | Implemented | Admins create dashboard templates for groups |
| Permission Levels | Implemented | view_only, add_only, full permissions |
| Compulsory Widgets | Implemented | Widgets users cannot remove |
| Conditional Rules | Implemented | Show/hide widgets based on groups, time, date |
| Group Targeting | Implemented | Assign templates to specific user groups |

### Permission Levels Explained

| Level | Add Widgets | Remove Widgets | Move/Resize | Style |
|-------|-------------|----------------|-------------|-------|
| view_only | No | No | No | No |
| add_only | Yes | Own only (not compulsory) | Yes | Yes |
| full | Yes | All | Yes | Yes |

---

## Tech Stack

### Backend (PHP)

| Component | Version/Type | Purpose |
|-----------|--------------|---------|
| PHP | 8.x | Server-side language |
| Nextcloud | 28+ | Application framework |
| OCP\AppFramework | - | Controllers, services, entities |
| OCP\Dashboard\IManager | - | Widget discovery and integration |
| Doctrine DBAL | - | Database abstraction (via Nextcloud) |

### Frontend (JavaScript/Vue)

| Component | Version | Purpose |
|-----------|---------|---------|
| Vue.js | 2.7.16 | Frontend framework |
| Pinia | 2.1.7 | State management |
| gridstack.js | 10.3.1 | Grid layout engine |
| @nextcloud/vue | 8.16.0 | Nextcloud UI components |
| @nextcloud/axios | 2.5.0 | HTTP client |
| @nextcloud/router | 2.0.1 | URL generation |
| @nextcloud/l10n | 3.2.0 | Translations |
| vue-loader | 15.11.1 | Vue 2 webpack loader |
| vue-material-design-icons | 5.2.0 | Icon components |

### Build Tools

| Tool | Version | Purpose |
|------|---------|---------|
| Webpack | 5.x | Module bundler |
| @nextcloud/webpack-vue-config | 5.5.0 | Nextcloud webpack presets |
| Node.js | 18+ (20 recommended) | Build environment |
| npm | 10+ | Package manager |

---

## Design Decisions

### 1. Coexistence with Built-in Dashboard

**Decision:** Use Nextcloud's native default app mechanism instead of modifying core files.

**Rationale:**
- App must work "out of the box" when downloaded from the app store
- Admins set default app via **Settings > Administration > Theming > Navigation bar > Default app**
- Users can override in personal settings
- No core Nextcloud modifications required

### 2. Grid Library: gridstack.js

**Decision:** Use gridstack.js instead of alternatives like vue-grid-layout.

**Rationale:**
- More feature-rich (used by Home Assistant)
- Better collision detection and auto-positioning
- Supports responsive breakpoints
- Active development and maintenance
- MIT license

### 3. Widget Integration Strategy

**Decision:** Support both IAPIWidgetV2 (modern) and legacy IWidget interfaces.

**Implementation:**
- Modern widgets: Fetch items via API, render with NcDashboardWidget
- Legacy widgets: Intercept `OCA.Dashboard.register()` callback, mount to container
- Widget discovery via `OCP\Dashboard\IManager::getWidgets()`

### 4. Database Boolean Columns

**Decision:** Use `SMALLINT` instead of `BOOLEAN` type for boolean fields.

**Rationale:**
- Nextcloud's DBAL has issues with `BOOLEAN` + `notnull` + `default: false`
- PostgreSQL compatibility requires this workaround
- Values: 0 = false, 1 = true

### 5. Multiple Dashboards Per User

**Decision:** Allow users to create multiple named dashboards with one active at a time.

**Implementation:**
- `is_active` column marks currently displayed dashboard
- Users switch via dropdown in header
- Each dashboard has independent widget placements

### 6. Template-Based Admin Control

**Decision:** Admins create "templates" that are applied to user groups.

**Flow:**
1. Admin creates template dashboard with widgets
2. Admin targets template to user groups
3. Users in those groups see template as their default
4. Users can customize within permission level
5. Compulsory widgets cannot be removed

---

## Code Structure

```
/apps-extra/mydash/
├── appinfo/
│   ├── info.xml              # App metadata, dependencies, navigation
│   └── routes.php            # All API route definitions
├── lib/
│   ├── AppInfo/
│   │   └── Application.php   # Bootstrap, container registration
│   ├── Controller/
│   │   ├── PageController.php        # Main page rendering
│   │   ├── DashboardApiController.php # Dashboard CRUD API
│   │   ├── WidgetApiController.php    # Widget management API
│   │   └── AdminController.php        # Admin templates/settings API
│   ├── Service/
│   │   ├── DashboardService.php      # Dashboard business logic
│   │   ├── WidgetService.php         # Widget discovery & integration
│   │   ├── PermissionService.php     # Permission checks
│   │   └── ConditionalService.php    # Rule evaluation
│   ├── Db/
│   │   ├── Dashboard.php             # Dashboard entity
│   │   ├── DashboardMapper.php       # Dashboard DB operations
│   │   ├── WidgetPlacement.php       # Widget placement entity
│   │   ├── WidgetPlacementMapper.php # Widget placement DB operations
│   │   ├── AdminSetting.php          # Admin setting entity
│   │   ├── AdminSettingMapper.php    # Admin setting DB operations
│   │   ├── ConditionalRule.php       # Conditional rule entity
│   │   └── ConditionalRuleMapper.php # Conditional rule DB operations
│   ├── Migration/
│   │   └── Version001000Date20240101000000.php # Database schema
│   └── Settings/
│       ├── MyDashAdmin.php           # Admin settings page
│       └── MyDashAdminSection.php    # Admin settings section
├── src/
│   ├── main.js               # Main app entry point
│   ├── admin.js              # Admin settings entry point
│   ├── App.vue               # Main Vue component
│   ├── components/
│   │   ├── DashboardGrid.vue       # GridStack.js integration
│   │   ├── DashboardSwitcher.vue   # Dashboard selection dropdown
│   │   ├── WidgetWrapper.vue       # Widget container with styling
│   │   ├── WidgetRenderer.vue      # Renders widget content
│   │   ├── WidgetPicker.vue        # Sidebar for adding widgets
│   │   ├── WidgetStyleEditor.vue   # Style customization modal
│   │   └── admin/
│   │       └── AdminSettings.vue   # Admin settings component
│   ├── stores/
│   │   ├── dashboard.js      # Dashboard state (Pinia)
│   │   └── widgets.js        # Available widgets state (Pinia)
│   └── services/
│       ├── api.js            # API client
│       └── widgetBridge.js   # Legacy widget bridge
├── templates/
│   ├── index.php             # Main page template
│   └── settings/
│       └── admin.php         # Admin settings template
├── css/
│   └── mydash.css            # App styles
├── js/                       # Built JavaScript (generated)
├── package.json              # npm dependencies
├── webpack.config.js         # Webpack configuration
└── composer.json             # PHP dependencies (autoloading)
```

---

## Database Schema

### oc_mydash_dashboards

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT (PK) | Auto-increment ID |
| uuid | VARCHAR(36) | Unique identifier |
| name | VARCHAR(255) | Dashboard name |
| description | TEXT | Optional description |
| type | VARCHAR(20) | 'admin_template' or 'user' |
| user_id | VARCHAR(64) | Owner (null for admin templates) |
| based_on_template | BIGINT | FK to template dashboard |
| grid_columns | INTEGER | Grid columns (default: 12) |
| permission_level | VARCHAR(20) | 'view_only', 'add_only', 'full' |
| target_groups | TEXT | JSON array of group IDs |
| is_default | SMALLINT | Default template flag (0/1) |
| is_active | SMALLINT | Currently active dashboard (0/1) |
| created_at | DATETIME | Creation timestamp |
| updated_at | DATETIME | Last update timestamp |

### oc_mydash_widget_placements

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT (PK) | Auto-increment ID |
| dashboard_id | BIGINT (FK) | Parent dashboard |
| widget_id | VARCHAR(255) | Nextcloud widget ID |
| grid_x | INTEGER | Grid X position |
| grid_y | INTEGER | Grid Y position |
| grid_width | INTEGER | Widget width (grid units) |
| grid_height | INTEGER | Widget height (grid units) |
| is_compulsory | SMALLINT | Cannot be removed (0/1) |
| is_visible | SMALLINT | Currently visible (0/1) |
| style_config | TEXT | JSON style settings |
| custom_title | VARCHAR(255) | Override widget title |
| show_title | SMALLINT | Show title bar (0/1) |
| sort_order | INTEGER | Display order |
| created_at | DATETIME | Creation timestamp |
| updated_at | DATETIME | Last update timestamp |

### oc_mydash_admin_settings

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT (PK) | Auto-increment ID |
| setting_key | VARCHAR(255) | Setting identifier |
| setting_value | TEXT | JSON value |
| updated_at | DATETIME | Last update timestamp |

### oc_mydash_conditional_rules

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT (PK) | Auto-increment ID |
| widget_placement_id | BIGINT (FK) | Parent widget placement |
| rule_type | VARCHAR(50) | 'group', 'time', 'date', 'attribute' |
| rule_config | TEXT | JSON rule configuration |
| is_include | SMALLINT | Include rule (1) or exclude (0) |
| created_at | DATETIME | Creation timestamp |

---

## API Endpoints

### User Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/apps/mydash/api/dashboards` | List user's dashboards |
| GET | `/apps/mydash/api/dashboard` | Get active dashboard |
| POST | `/apps/mydash/api/dashboard` | Create new dashboard |
| PUT | `/apps/mydash/api/dashboard/{id}` | Update dashboard |
| DELETE | `/apps/mydash/api/dashboard/{id}` | Delete dashboard |
| POST | `/apps/mydash/api/dashboard/{id}/activate` | Switch active dashboard |
| GET | `/apps/mydash/api/widgets` | List available widgets |
| POST | `/apps/mydash/api/dashboard/{id}/widgets` | Add widget |
| PUT | `/apps/mydash/api/widgets/{placementId}` | Update widget placement |
| DELETE | `/apps/mydash/api/widgets/{placementId}` | Remove widget |
| POST | `/apps/mydash/api/widgets/{placementId}/rules` | Add conditional rule |
| DELETE | `/apps/mydash/api/rules/{ruleId}` | Remove rule |

### Admin Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/apps/mydash/api/admin/templates` | List admin templates |
| POST | `/apps/mydash/api/admin/templates` | Create template |
| PUT | `/apps/mydash/api/admin/templates/{id}` | Update template |
| DELETE | `/apps/mydash/api/admin/templates/{id}` | Delete template |
| GET | `/apps/mydash/api/admin/settings` | Get global settings |
| PUT | `/apps/mydash/api/admin/settings` | Update global settings |

---

## Development Setup

### Prerequisites

- Docker environment (openregister docker-compose)
- Node.js 18+ (20 recommended)
- npm 10+

### Build Commands

```bash
# Navigate to app directory
cd /apps-extra/mydash

# Install dependencies
npm install

# Development build (with watch)
npm run watch

# Production build
npm run build

# Lint JavaScript
npm run lint
npm run lint:fix

# Lint styles
npm run stylelint
npm run stylelint:fix
```

### Docker Commands

```bash
# Enable/disable app
docker exec -u www-data nextcloud php occ app:enable mydash
docker exec -u www-data nextcloud php occ app:disable mydash

# Check app status
docker exec -u www-data nextcloud php occ app:list | grep mydash

# Clear cache (after changes)
docker exec nextcloud apachectl -k graceful
```

### Deployment to Docker

The openregister docker-compose mounts `./custom_apps` directory. To deploy:

```bash
# Copy app to Docker-accessible location
cp -r /apps-extra/mydash /apps-extra/openregister/custom_apps/
```

---

## Known Issues & Workarounds

### 1. Vue-loader Version Compatibility

**Issue:** vue-loader@17 is for Vue 3, causes build errors with Vue 2.7
**Solution:** Use vue-loader@15.11.1 and @nextcloud/webpack-vue-config@5.5.0

### 2. Boolean Database Columns

**Issue:** `Types::BOOLEAN` with `notnull: true` fails on PostgreSQL
**Solution:** Use `Types::SMALLINT` with `default: 0` or `default: 1`

### 3. Bundle Size Warnings

**Issue:** Webpack warns about large bundle sizes (3+ MB)
**Solution:** Future optimization: code splitting, lazy loading components

---

## Testing Checklist

### Functional Tests

- [ ] User can view dashboard with widgets
- [ ] User can add widgets from picker
- [ ] User can drag widgets to reposition
- [ ] User can resize widgets
- [ ] User can remove widgets (respecting permissions)
- [ ] User can customize widget styles
- [ ] User can create multiple dashboards
- [ ] User can switch between dashboards
- [ ] Admin can create templates
- [ ] Admin can target templates to groups
- [ ] Admin can set permission levels
- [ ] Admin can mark widgets as compulsory
- [ ] Conditional rules show/hide widgets correctly
- [ ] All Nextcloud widgets render correctly

### Browser Compatibility

- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge

### Responsive Design

- [ ] Desktop (1920px+)
- [ ] Laptop (1366px)
- [ ] Tablet (768px)
- [ ] Mobile (375px) - if supported

---

## Next Steps for Browser Agent

1. **Access the application** at http://localhost:8080/apps/mydash/
2. **Login credentials:** Check `.claude/CLAUDE.local.md` in openregister or use default admin/admin
3. **Test core functionality:** Add widgets, drag, resize, style
4. **Test admin features:** Go to Settings > Administration > MyDash
5. **Fix any UI/UX issues** discovered during testing
6. **Optimize bundle size** if performance is poor
7. **Add responsive breakpoints** for smaller screens
8. **Test with various Nextcloud widgets** (calendar, weather, etc.)

---

## File Locations Summary

| File | Location |
|------|----------|
| Main source | `/apps-extra/mydash/` |
| Deployed to Docker | `/apps-extra/openregister/custom_apps/mydash/` |
| Plan document | `~/.claude/plans/woolly-exploring-teacup.md` |
| This handover | `/apps-extra/mydash/HANDOVER.md` |

---

*Generated: February 2026*
