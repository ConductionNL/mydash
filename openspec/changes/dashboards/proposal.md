# Dashboards Specification

## Problem
Dashboards are the core organizational unit in MyDash. Each user can create and manage multiple personal dashboards, each acting as a container for widget placements, tiles, and layout configuration. Dashboards define the grid structure, permission level, and active state. Only one dashboard can be active per user at a time, serving as their landing page when they open Nextcloud. Dashboards can also be of type `admin_template`, managed by administrators for distribution to users.

## Proposed Solution
Implement Dashboards Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the dashboards specification.

## Success Criteria
- Create a dashboard with default settings
- Create a dashboard with custom settings
- Create a dashboard with invalid grid columns
- Create a dashboard without a name
- Dashboard creation creates default placements
