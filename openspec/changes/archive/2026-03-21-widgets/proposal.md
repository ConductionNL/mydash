# Widgets Specification

## Problem
Widgets are the primary content blocks on MyDash dashboards. MyDash integrates with the Nextcloud Dashboard Widget API (v1 and v2) via `OCP\Dashboard\IManager::getWidgets()` to discover all registered dashboard widgets across installed Nextcloud apps. Users can add these discovered widgets to their dashboards as "placements" -- records that track the widget's position on the grid, display configuration, and custom styling. Widget placements bridge the Nextcloud widget ecosystem with the MyDash grid layout system.

## Proposed Solution
Implement Widgets Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the widgets specification.

## Success Criteria
- List all available widgets
- Widget list includes v1 and v2 widgets
- Widget list updates when apps are installed
- Widget formatting via WidgetFormatter
- Fetch items for a v2 widget
