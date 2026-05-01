---
capability: dashboards
delta: true
status: draft
---

# Dashboards — Delta from change `dashboard-tree`

## ADDED Requirements

### Requirement: REQ-DASH-011 Dashboard hierarchy and parent relationship

The system MUST support an optional parent-child hierarchy among dashboards. Each dashboard MAY have a parent dashboard specified by UUID in a nullable `parentUuid` column. Dashboards with no parent are root-level dashboards. A dashboard MAY have unlimited children, but the total depth (root + descendants) MUST NOT exceed 5 levels.

#### Scenario: Create a child dashboard

- GIVEN user "alice" has a root dashboard "Marketing" with UUID `uuid-marketing`
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "parentUuid": "uuid-marketing"}`
- THEN the system MUST create a dashboard with `parentUuid = "uuid-marketing"`
- AND the dashboard's depth-from-root MUST be 2 (root + 1 child)
- AND the response MUST return HTTP 201 with the new dashboard object

#### Scenario: Root dashboard has null parent

- GIVEN user "alice" creates a dashboard without specifying `parentUuid`
- WHEN she sends POST /api/dashboard with body `{"name": "Marketing"}`
- THEN the system MUST set `parentUuid = null`
- AND the dashboard MUST be a root-level dashboard

#### Scenario: Non-existent parent returns 400

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Child", "parentUuid": "uuid-nonexistent"}`
- THEN the system MUST return HTTP 400 with `{error: "Parent dashboard not found"}`
- AND no dashboard MUST be created

#### Scenario: Changing parent moves the subtree

- GIVEN user "alice" has dashboard tree: "Marketing" > "Q1 Campaigns" (child)
- WHEN she sends PUT /api/dashboard/q1-campaigns-id with body `{"parentUuid": "uuid-finance"}`
- THEN the system MUST move "Q1 Campaigns" under "Finance"
- AND the dashboard's `parentUuid` MUST be updated to `uuid-finance`
- AND the computed path MUST change from `/marketing/q1-campaigns` to `/finance/q1-campaigns`

#### Scenario: Reading a dashboard includes parent reference

- GIVEN dashboard "Q1 Campaigns" with `parentUuid = "uuid-marketing"`
- WHEN the dashboard is fetched via GET /api/dashboard/{id}
- THEN the response MUST include `parentUuid: "uuid-marketing"`

#### Scenario: Null parent is preserved in serialization

- GIVEN a root dashboard with `parentUuid = null`
- WHEN the dashboard is serialized
- THEN the JSON MUST include `parentUuid: null`

### Requirement: REQ-DASH-012 Slug uniqueness and path resolution

Each dashboard MUST have a `slug` field — a URL-safe string unique among its siblings (dashboards sharing the same parent). Slugs are auto-generated from the dashboard name if not supplied, and MAY be manually overridden. Slugs are used to form human-readable paths like `/marketing/campaigns/q1`.

#### Scenario: Slug auto-generation from name

- GIVEN a user "alice" creates a dashboard with name "Q1 Campaigns" and no explicit slug
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns"}`
- THEN the system MUST auto-generate `slug = "q1-campaigns"` (lowercased, spaces to dashes, max 128 chars)
- AND the slug MUST be stored in the database

#### Scenario: Slug uniqueness among siblings

- GIVEN user "alice" has a parent dashboard "Marketing" with child "Q1 Campaigns" (slug `q1-campaigns`)
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "parentUuid": "uuid-marketing"}` (attempting to create a second sibling with the same slug)
- THEN the system MUST return HTTP 400 with `{error: "Slug must be unique among siblings"}`
- AND no dashboard MUST be created

#### Scenario: Custom slug override

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Q1 Campaigns", "slug": "q1-custom"}`
- THEN the system MUST use the supplied slug `q1-custom`
- AND NOT auto-generate one from the name

#### Scenario: Slug validation characters

- GIVEN a user "alice"
- WHEN she sends POST /api/dashboard with body `{"slug": "q1 campaigns!"}`
- THEN the system MUST reject the slug and return HTTP 400
- AND slugs MUST only allow alphanumeric, dash, and underscore characters

#### Scenario: Reading a dashboard includes slug

- GIVEN a dashboard with `slug = "q1-campaigns"`
- WHEN the dashboard is fetched via GET /api/dashboard/{id}
- THEN the response MUST include `slug: "q1-campaigns"`

#### Scenario: Slug update does not auto-regenerate on name change

- GIVEN a dashboard with `name = "Q1 Campaigns"` and `slug = "q1"`
- WHEN user sends PUT /api/dashboard/{id} with body `{"name": "Q2 Campaigns"}` (name change, no slug supplied)
- THEN the system MUST preserve `slug = "q1"`
- AND MUST NOT auto-regenerate the slug to `q2-campaigns`

### Requirement: REQ-DASH-013 Computed path and breadcrumb navigation

The system MUST compute a `path` field on demand (not stored) by joining the slug chain from root to the target dashboard. The system MUST also compute `breadcrumbs` — an ordered list of `{uuid, name, slug}` objects from root to the target dashboard, used for navigation UI.

#### Scenario: Compute path for root dashboard

- GIVEN a root dashboard with `slug = "marketing"`
- WHEN the path is computed
- THEN the path MUST equal `/marketing`

#### Scenario: Compute path for nested dashboard

- GIVEN a dashboard tree: "Marketing" (slug `marketing`) > "Campaigns" (slug `campaigns`) > "Q1" (slug `q1`)
- WHEN the path is computed for "Q1"
- THEN the path MUST equal `/marketing/campaigns/q1`

#### Scenario: Path updates when parent changes

- GIVEN dashboard "Q1" with computed path `/marketing/campaigns/q1`
- WHEN the parent of "Q1" is changed to "Finance" (slug `finance`)
- THEN on next read, the path MUST equal `/finance/q1`

#### Scenario: Breadcrumbs from root to target

- GIVEN dashboard "Q1" in tree "Marketing" > "Campaigns" > "Q1"
- WHEN breadcrumbs are computed
- THEN the breadcrumbs MUST be:
  - `{uuid: "uuid-marketing", name: "Marketing", slug: "marketing"}`
  - `{uuid: "uuid-campaigns", name: "Campaigns", slug: "campaigns"}`
  - `{uuid: "uuid-q1", name: "Q1", slug: "q1"}`
- AND the list MUST be ordered from root to leaf

#### Scenario: Root dashboard has single-item breadcrumbs

- GIVEN a root dashboard with `uuid = "uuid-root"`, `name = "Marketing"`, `slug = "marketing"`
- WHEN breadcrumbs are computed
- THEN the breadcrumbs MUST be a single-item array: `{uuid: "uuid-root", name: "Marketing", slug: "marketing"}`

#### Scenario: Breadcrumbs accessible via API

- GIVEN a dashboard is returned via any GET endpoint
- WHEN the response is inspected
- THEN it SHOULD include a computed `breadcrumbs` field (optional per endpoint; at minimum available via `/api/dashboards/by-path/...`)

### Requirement: REQ-DASH-014 Tree API endpoint

The system MUST expose `GET /api/dashboards/tree` returning the full visible tree of dashboards as a nested structure `{uuid, name, slug, children: [...]}`, allowing the frontend to render collapsible hierarchies.

#### Scenario: Tree endpoint returns nested structure

- GIVEN user "alice" has dashboards: "Marketing" (root) with child "Campaigns", and "Finance" (root) with child "Budget"
- WHEN she sends GET /api/dashboards/tree
- THEN the response MUST contain two root objects in the `children` array:
  - `{uuid: "uuid-marketing", name: "Marketing", slug: "marketing", children: [{uuid: "uuid-campaigns", ...}]}`
  - `{uuid: "uuid-finance", name: "Finance", slug: "finance", children: [{uuid: "uuid-budget", ...}]}`
- AND each node MUST include `uuid`, `name`, `slug`, and `children` (empty array if no children)

#### Scenario: Tree endpoint respects user ownership

- GIVEN user "alice" has 3 root dashboards and user "bob" has 2 root dashboards
- WHEN alice sends GET /api/dashboards/tree
- THEN the response MUST include only alice's 3 root dashboards (and their subtrees)
- AND bob's dashboards MUST NOT be included

#### Scenario: Tree endpoint includes sort order

- GIVEN user "alice" has root dashboards with `sortOrder = 10, 5, 20` and same parent
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be sorted by `sortOrder` (5, 10, 20)
- AND ties MUST be broken alphabetically by `name`

#### Scenario: Empty tree returns empty children array

- GIVEN user "bob" has no dashboards
- WHEN he sends GET /api/dashboards/tree
- THEN the response MUST be an empty array (or `{children: []}` depending on schema)

### Requirement: REQ-DASH-015 Path-based dashboard resolution

The system MUST expose `GET /api/dashboards/by-path/{*path}` to resolve a slug chain (e.g., `/marketing/campaigns/q1`) to the dashboard at that location, returning the dashboard object with computed breadcrumbs and path.

#### Scenario: Resolve path to dashboard

- GIVEN user "alice" has dashboard tree "Marketing" > "Campaigns" > "Q1"
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q1
- THEN the response MUST return the "Q1" dashboard object with computed path `/marketing/campaigns/q1` and breadcrumbs array

#### Scenario: Path not found returns 404

- GIVEN user "alice" has dashboard tree "Marketing" > "Campaigns" but no "Q2"
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q2
- THEN the system MUST return HTTP 404 with `{error: "Dashboard not found at path"}`

#### Scenario: User cannot access other user's dashboard via path

- GIVEN user "alice" has dashboard "Marketing" (slug `marketing`) and user "bob" has a different dashboard with the same slug
- WHEN alice sends GET /api/dashboards/by-path/marketing
- THEN the response MUST return only alice's "Marketing" dashboard
- AND bob's dashboard MUST NOT be accessible to alice

#### Scenario: Path is case-insensitive

- GIVEN user "alice" has dashboard with `slug = "marketing"`
- WHEN she sends GET /api/dashboards/by-path/MARKETING
- THEN the system MUST resolve to the dashboard (slugs are stored lowercase, comparison must be case-insensitive or stored-case-matching)

#### Scenario: Trailing slash is optional

- GIVEN user "alice" has dashboard at path `/marketing/campaigns/q1`
- WHEN she sends GET /api/dashboards/by-path/marketing/campaigns/q1/ (with trailing slash)
- THEN the system MUST resolve to the dashboard (trailing slash must be ignored or normalized)

### Requirement: REQ-DASH-016 Cycle prevention and depth validation

The system MUST prevent setting a dashboard's parent to any of its own descendants (cycle prevention) and MUST enforce a maximum tree depth of 5 levels (root + 4 descendants).

#### Scenario: Cycle detection on parent update

- GIVEN user "alice" has dashboard tree "A" > "B" > "C"
- WHEN she sends PUT /api/dashboard/a-id with body `{"parentUuid": "uuid-c"}`
- THEN the system MUST return HTTP 400 with `{error: "Setting this parent would create a cycle"}`
- AND the dashboard MUST NOT be updated

#### Scenario: Self-parenting is rejected

- GIVEN dashboard "Marketing" with UUID `uuid-marketing`
- WHEN user "alice" sends PUT /api/dashboard/marketing-id with body `{"parentUuid": "uuid-marketing"}`
- THEN the system MUST return HTTP 400
- AND the dashboard MUST NOT be updated (cannot be its own parent)

#### Scenario: Max depth exceeded on create

- GIVEN user "alice" has a 5-level tree: A > B > C > D > E (depth = 5)
- WHEN she sends POST /api/dashboard with body `{"name": "F", "parentUuid": "uuid-e"}` (attempting to add a 6th level)
- THEN the system MUST return HTTP 400 with `{error: "Cannot exceed maximum tree depth of 5 levels"}`
- AND no dashboard MUST be created

#### Scenario: Max depth exceeded on parent update

- GIVEN user "alice" has two trees: A > B > C > D (4 levels) and X > Y > Z (3 levels)
- WHEN she sends PUT /api/dashboard/x-id with body `{"parentUuid": "uuid-d"}` (attempting to nest X > Y > Z under the 4-level tree, creating 7 levels total)
- THEN the system MUST return HTTP 400 with `{error: "Cannot exceed maximum tree depth of 5 levels"}`
- AND the parent MUST NOT be updated

#### Scenario: Exactly 5 levels is allowed

- GIVEN user "alice" creates a 5-level tree: A > B > C > D > E (depth = 5)
- WHEN she sends POST /api/dashboard with body `{"name": "NewRoot"}`
- THEN the system MUST allow creating the new root dashboard
- AND multiple independent 5-level trees MAY coexist

### Requirement: REQ-DASH-017 Sibling ordering

Dashboards sharing the same parent MUST be ordered by a `sortOrder INT` column. Ties in `sortOrder` MUST be broken alphabetically by `name`. Ordering MUST be reflected in all tree and list responses.

#### Scenario: Default sort order on create

- GIVEN user "alice" creates dashboard "Marketing" without specifying `sortOrder`
- WHEN the dashboard is created
- THEN `sortOrder` MUST default to 0

#### Scenario: Custom sort order on create

- GIVEN user "alice"
- WHEN she sends POST /api/dashboard with body `{"name": "Marketing", "sortOrder": 100}`
- THEN the dashboard MUST be created with `sortOrder = 100`

#### Scenario: Sort order respected in tree

- GIVEN user "alice" has three root dashboards with `sortOrder = 20, 5, 15` respectively
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be ordered as: sortOrder 5, 15, 20

#### Scenario: Tie-breaking by name

- GIVEN user "alice" has two sibling dashboards both with `sortOrder = 0`, named "Zebra" and "Alice"
- WHEN she sends GET /api/dashboards/tree
- THEN the `children` array MUST be ordered as: "Alice" then "Zebra" (alphabetically)

#### Scenario: Update sort order via PUT

- GIVEN user "alice" has dashboard with `sortOrder = 10`
- WHEN she sends PUT /api/dashboard/{id} with body `{"sortOrder": 50}`
- THEN the dashboard MUST be updated to `sortOrder = 50`
- AND tree responses MUST reflect the new order

### Requirement: REQ-DASH-018 Cascade deletion with guard

Deleting a dashboard with children MUST require an explicit `?cascade=true` query parameter. Without it, the system MUST return HTTP 409 with the count of children, preventing accidental loss of subtrees.

#### Scenario: Delete parent without cascade returns 409

- GIVEN user "alice" has dashboard "Marketing" with 3 child dashboards
- WHEN she sends DELETE /api/dashboard/marketing-id (without `?cascade=true`)
- THEN the system MUST return HTTP 409 with `{error: "Dashboard has 3 children. Use ?cascade=true to delete the subtree."}`
- AND the dashboard MUST NOT be deleted

#### Scenario: Delete parent with cascade deletes subtree

- GIVEN user "alice" has dashboard "Marketing" > "Campaigns" > "Q1" (3 total)
- WHEN she sends DELETE /api/dashboard/marketing-id?cascade=true
- THEN the system MUST delete all 3 dashboards
- AND all associated placements MUST be cascade-deleted per REQ-DASH-005
- AND the response MUST return HTTP 200

#### Scenario: Delete childless dashboard has no guard

- GIVEN user "alice" has a root dashboard with no children
- WHEN she sends DELETE /api/dashboard/{id} (with or without `?cascade=true`)
- THEN the system MUST delete the dashboard (no cascade guard needed)
- AND the response MUST return HTTP 200

#### Scenario: Cascade parameter is case-insensitive

- GIVEN user "alice" has a dashboard with children
- WHEN she sends DELETE /api/dashboard/{id}?cascade=TRUE or ?cascade=Cascade
- THEN the system MUST interpret it as true and delete the subtree

#### Scenario: User cannot delete another user's dashboard subtree

- GIVEN user "alice" has a dashboard tree
- WHEN user "bob" sends DELETE /api/dashboard/alice-dashboard-id?cascade=true
- THEN the system MUST return HTTP 403 (ownership check fails)
- AND alice's dashboards MUST NOT be deleted

## UNCHANGED Requirements

All REQ-DASH-001 through REQ-DASH-010 from the base `dashboards` capability remain fully supported. Existing flat dashboard operations (create, list, update, delete without children) continue to work unchanged. This delta is additive and does not break backward compatibility for clients using the legacy `/api/dashboards` and `/api/dashboard/{id}` endpoints.
