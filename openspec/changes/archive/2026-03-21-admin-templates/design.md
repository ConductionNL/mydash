# Admin Templates - Design Document

## Architecture

### Backend
- **Service**: `Service\TemplateService` - Template distribution, dashboard creation from template
- **Service**: `Service\AdminTemplateService` - Admin CRUD for templates
- **Controller**: `Controller\AdminController` - Admin-only template endpoints

### Data Model
- Templates are Dashboard entities with type="admin_template"
- Additional fields: targetGroups (JSON), isDefault (0/1), permissionLevel
- userId set to null for templates (not owned by specific user)
- Template placements include isCompulsory flags

### Template Distribution Flow
1. Admin creates template with target groups
2. User opens MyDash -> DashboardResolver checks for applicable template
3. TemplateService.getApplicableTemplate() matches user groups
4. TemplateService.createDashboardFromTemplate() clones template + placements
5. User gets independent copy with inherited permission level

### Key Design Decisions
- Group-specific templates take priority over default templates
- Only one default template allowed (clearDefaultTemplates on new default)
- Template placements cloned with isCompulsory preserved
- User copies reference template via basedOnTemplate field
- Ramsey/Uuid used for template-based dashboard UUIDs
