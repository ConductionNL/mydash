# MyDash - Custom Dashboard Application for Nextcloud

MyDash is a flexible and customizable dashboard application for Nextcloud that allows users to create and manage multiple personalized dashboards with various Nextcloud widgets.

## Features

- **Multiple Dashboards**: Create and switch between multiple custom dashboards
- **Widget Management**: Add, remove, and arrange Nextcloud widgets
- **Drag & Drop**: Intuitive drag-and-drop interface powered by GridStack
- **Widget Styling**: Customize widget appearance with color pickers and styling options
- **Conditional Display**: Set rules for when widgets should be displayed
- **Template System**: Administrators can create pre-configured dashboard templates
- **API Support**: Compatible with both Nextcloud Dashboard API v1 and v2 widgets
- **Permission Control**: Fine-grained access control for dashboards and widgets

## Requirements

- Nextcloud 31+
- PHP 8.0+
- PostgreSQL 12+ or MySQL 8.0+

## Installation

1. Clone this repository into your Nextcloud apps directory:
   ```bash
   cd /var/www/nextcloud/apps
   git clone https://github.com/ConductionNL/mydash.git
   ```

2. Install dependencies:
   ```bash
   cd mydash
   composer install
   npm install
   ```

3. Build frontend assets:
   ```bash
   npm run build
   ```

4. Enable the app:
   ```bash
   php occ app:enable mydash
   ```

## Development

### Setup

```bash
# Install dependencies
composer install
npm install

# Build for development (with watch)
npm run dev

# Build for production
npm run build
```

### Code Quality

Run quality checks before committing:

```bash
# PHP Code Quality
composer phpqa

# Run tests
composer test:unit
```

### Architecture

- **Backend**: PHP with Nextcloud App Framework
  - Controllers: Handle API requests
  - Services: Business logic layer
  - Mappers: Database access layer
  - Entities: Data models with Doctrine ORM

- **Frontend**: Vue.js 2.7 with Pinia
  - Components: Reusable UI components
  - Stores: State management
  - Services: API client

- **Database**: 
  - `oc_mydash_dashboards`: Dashboard configurations
  - `oc_mydash_widget_placements`: Widget positions and settings
  - `oc_mydash_conditional_rules`: Display conditions
  - `oc_mydash_admin_settings`: Global settings

## Usage

### Creating a Dashboard

1. Navigate to the MyDash app
2. Click "Create dashboard" if no dashboard exists
3. Click "Add widget" in edit mode
4. Select widgets from the picker
5. Arrange widgets by dragging them
6. Click "Done" to save

### Styling Widgets

1. Enter edit mode by clicking "Customize"
2. Click the palette icon on any widget
3. Adjust colors, borders, padding, and other styling options
4. Click "Save" to apply changes

### Admin Templates

Administrators can create dashboard templates:

1. Go to Settings → Administration → MyDash
2. Create a new template
3. Add and configure widgets
4. Users can now apply this template when creating dashboards

## API Endpoints

### Dashboard Management
- `GET /api/dashboards` - List user's dashboards
- `GET /api/dashboard` - Get active dashboard
- `POST /api/dashboard` - Create new dashboard
- `PUT /api/dashboard/{id}` - Update dashboard
- `DELETE /api/dashboard/{id}` - Delete dashboard
- `POST /api/dashboard/{id}/activate` - Set as active

### Widget Management
- `GET /api/widgets` - List available widgets
- `GET /api/widgets/items` - Get widget items
- `POST /api/dashboard/{id}/widgets` - Add widget to dashboard
- `PUT /api/widgets/{id}` - Update widget placement
- `DELETE /api/widgets/{id}` - Remove widget

## Contributing

We welcome contributions! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run quality checks: `composer phpqa`
5. Commit with meaningful messages
6. Push to your fork
7. Create a Pull Request

## License

AGPL-3.0-or-later

## Support

For issues and questions:
- GitHub Issues: https://github.com/ConductionNL/mydash/issues
- Documentation: [Coming soon]

## Credits

Developed by [Conduction](https://conduction.nl)

## Changelog

### Version 1.0.0 (2026-02-03)

Initial release with:
- Multiple dashboard support
- Widget management with drag & drop
- Widget styling editor
- Conditional display rules
- Admin template system
- Support for Nextcloud Dashboard API v1 and v2
