---
capability: admin-templates
delta: true
status: draft
---

# Admin Templates — Delta from change `group-routing`

## ADDED Requirements

### Requirement: REQ-TMPL-012 Primary-group resolution for workspace routing

The system MUST expose a pure function `resolvePrimaryGroup(string $userId): string` that returns the Nextcloud group ID whose `group_shared` dashboards the user should see, OR the literal string `'default'` when no match is found. The algorithm MUST be:

1. Read the admin-configured ordered list of group IDs from `admin_settings.group_order` (JSON `string[]`, default `[]`).
2. Read the user's Nextcloud group memberships via `IGroupManager::getUserGroupIds($userId)`.
3. Walk `group_order` left-to-right and return the first group ID that also appears in the user's memberships.
4. If no match, return the literal string `'default'`.

The function MUST be deterministic and idempotent (no writes).

#### Scenario: First match wins by admin-configured priority

- GIVEN admin has set `group_order = ["engineering", "all-staff"]`
- AND user "alice" belongs to groups: `["all-staff", "engineering", "marketing"]`
- WHEN `resolvePrimaryGroup("alice")` is called
- THEN it MUST return `"engineering"` (because engineering appears first in group_order, even though all-staff is alphabetically earlier in alice's groups)

#### Scenario: User in no active group falls through to default sentinel

- GIVEN admin has set `group_order = ["engineering", "executives"]`
- AND user "carol" belongs only to groups: `["support"]`
- WHEN `resolvePrimaryGroup("carol")` is called
- THEN it MUST return `"default"`

#### Scenario: Empty group_order always returns default

- GIVEN admin has not configured any active groups (`group_order = []`)
- WHEN `resolvePrimaryGroup` is called for any user
- THEN it MUST return `"default"` regardless of the user's actual group memberships

#### Scenario: Configured group that the user is NOT in is skipped

- GIVEN `group_order = ["executives", "engineering"]`
- AND user "bob" belongs to: `["engineering", "support"]`
- WHEN `resolvePrimaryGroup("bob")` is called
- THEN it MUST skip "executives" and return `"engineering"`

#### Scenario: Configured group that no longer exists in Nextcloud is harmless

- GIVEN `group_order = ["deleted-group", "engineering"]`
- AND the Nextcloud group "deleted-group" has been removed
- AND user "alice" belongs to: `["engineering"]`
- WHEN `resolvePrimaryGroup("alice")` is called
- THEN it MUST return `"engineering"`
- AND MUST NOT raise an error
- NOTE: Cleanup of stale group IDs in `group_order` is the admin UI's responsibility; the resolver MUST be tolerant.

### Requirement: REQ-TMPL-013 Resolver is the single routing authority

All workspace-rendering and dashboard-resolution code paths (REQ-DASH-013, REQ-DASH-018) MUST consult `resolvePrimaryGroup` for the user's primary group. There MUST NOT be parallel implementations of this lookup.

#### Scenario: Single source of truth

- GIVEN any future capability needs the user's primary workspace group
- WHEN it computes a group ID
- THEN it MUST go through `AdminTemplateService::resolvePrimaryGroup` (or its declared service interface)
- AND duplicating the algorithm inline is forbidden by code review
