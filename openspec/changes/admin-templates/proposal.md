# Admin Templates Specification

## Problem
Admin templates allow Nextcloud administrators to create pre-configured dashboards that are automatically distributed to users based on group membership. When a user opens MyDash for the first time (or when a new template targets their group), the system creates a personal copy of the matching template. This copy is an independent dashboard that the user can modify within the limits of the inherited permission level. Templates enable organizations to provide standardized dashboard layouts with compulsory widgets while still allowing user customization where appropriate.

## Proposed Solution
Implement Admin Templates Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the admin-templates specification.

## Success Criteria
- Create a template targeting specific groups
- Create a default template for all users
- Non-admin user cannot create templates
- Create template with invalid permission level
- Create template with UUID generation
