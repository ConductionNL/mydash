# Admin Settings Specification

## Problem
Admin settings provide Nextcloud administrators with global configuration options for the MyDash app. These settings control system-wide behavior such as whether users can create their own dashboards, how many dashboards they can have, default permission levels for new dashboards, and default grid configuration. Settings are stored as key-value pairs in a dedicated database table and are applied as defaults or constraints across the entire MyDash installation.

## Proposed Solution
Implement Admin Settings Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the admin-settings specification.

## Success Criteria
- Get all settings with defaults
- Get settings after modification
- Non-admin user retrieves settings
- Settings used by non-admin endpoints
- Settings response format consistency
