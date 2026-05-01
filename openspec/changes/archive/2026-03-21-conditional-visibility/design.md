# Conditional Visibility - Design Document

## Architecture

### Backend
- **Entity**: `Db\ConditionalRule` - Rule type, config JSON, include/exclude flag
- **Mapper**: `Db\ConditionalRuleMapper` - CRUD, findByPlacementId
- **Service**: `Service\ConditionalService` - Rule CRUD and visibility evaluation
- **Service**: `Service\RuleEvaluatorService` - Evaluates individual rules by type
- **Service**: `Service\VisibilityChecker` - Orchestrates include/exclude logic
- **Service**: `Service\UserAttributeResolver` - Resolves user attributes for rules
- **Controller**: `Controller\RuleApiController` - REST API for rule management

### Rule Types
- **group**: Match user's Nextcloud groups against target groups
- **time**: Match current time against startTime/endTime and optional days
- **date**: Match current date against startDate/endDate range
- **attribute**: Match user attribute against value with operator

### Visibility Logic
- Include rules: OR logic (at least one must match to show)
- Exclude rules: AND logic (any match hides the widget)
- No rules + isVisible=1: always shown
- isVisible=0: always hidden (overrides rules)

### Key Design Decisions
- Rules stored per widget placement (not per dashboard)
- No separate visibility state string - uses isVisible flag + rule existence
- Time evaluation uses server DateTime (no user timezone support)
- Rule config stored as JSON blob with type-specific schema
