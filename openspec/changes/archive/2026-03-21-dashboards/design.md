# Dashboards - Design Document

## Architecture

### Backend
- **Entity**: `Db\Dashboard` - Core entity with UUID, name, description, type, grid config, permissions
- **Mapper**: `Db\DashboardMapper` - Database operations (find, findByUserId, findAdminTemplates, setActive, deactivateAllForUser)
- **Service**: `Service\DashboardService` - Business logic for CRUD, activation, template creation
- **Factory**: `Service\DashboardFactory` - Creates Dashboard entities with UUID generation
- **Resolver**: `Service\DashboardResolver` - Resolves effective dashboard (active, existing, or template-based)
- **Controller**: `Controller\DashboardApiController` - REST API endpoints

### Frontend
- **Store**: `stores/dashboard.js` - Pinia store for dashboard state
- **Component**: `components/DashboardSwitcher.vue` - UI for switching between dashboards
- **View**: `views/Views.vue` - Main dashboard view

### Data Flow
1. User requests dashboard -> DashboardApiController
2. Controller calls DashboardService.getEffectiveDashboard()
3. DashboardResolver checks: active dashboard -> existing dashboard -> template -> create new
4. DashboardFactory creates entity with generated UUID
5. Response includes dashboard + placements + permissionLevel

### Key Design Decisions
- Only one dashboard active per user at a time (enforced by deactivateAllForUser)
- New dashboards auto-activate and get default placements (recommendations + activity)
- UUID v4 generated via custom DashboardFactory.generateUuid() (no external library)
- Dashboard types: "user" (personal) and "admin_template" (admin-managed)
