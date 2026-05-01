# Design — Admin Roles (Dashboard Admin / Editor / Viewer)

## Context

MyDash currently resolves all access control through Nextcloud's binary admin/non-admin flag and GroupFolder ACL bitmasks (READ=1, UPDATE=2, CREATE=4, DELETE=8, SHARE=16). This is sufficient for filesystem-level sharing but creates a delegation problem: an organisation that wants to empower a trusted user to manage dashboards must either grant them full Nextcloud system administration — including server configuration, user management, and app installs — or leave them with no elevated access at all.

The source app the MyDash feature set is informed by has no named role concept and no role precedence system. Its `PermissionService` resolves permissions entirely from GroupFolder ACL lookups via Nextcloud's `FolderManager`. It does seed three Nextcloud groups (`IntraVox Admins`, `IntraVox Editors`, `IntraVox Users`) and assign them GroupFolder permissions, but those are plain Nextcloud groups — not an in-app role model, not stored in any app table, and not consulted by any role resolver.

Admin-roles is therefore a net-new MyDash design with no source counterpart. It introduces three roles scoped entirely within MyDash (Dashboard Admin, Dashboard Editor, Dashboard Viewer), persistent DB-backed assignments, and a highest-privilege-wins resolution algorithm. The existing `permissions` capability is extended to consult the role system before falling back to its default GroupFolder-derived behavior.

The three role names deliberately echo the three groups the source app seeds (`Admins`, `Editors`, `Users`) so that customers familiar with the source app's group structure recognise the model immediately, even though the underlying mechanism is entirely different.

## Goals / Non-Goals

**Goals:**
- Introduce three named MyDash roles that map to real organisational delegation needs.
- Persist role assignments in a dedicated DB table, supporting both user-level and group-level scope.
- Resolve effective role per user using a deterministic highest-privilege-wins algorithm.
- Layer the role system on top of Nextcloud's admin flag — NC admin is always treated as Dashboard Admin, no explicit assignment required.
- Cascade cleanly on Nextcloud user or group deletion via listener hooks.
- Enable the setup wizard (sibling capability) to optionally bootstrap role assignments from existing NC groups.

**Non-Goals:**
- Do NOT replace or modify Nextcloud's built-in admin/non-admin distinction — the role system layers on top of it.
- Do NOT introduce per-dashboard role overrides (e.g., "admin globally, viewer on this one dashboard") — keep resolution user-scoped.
- Do NOT replace GroupFolder ACL for filesystem-level access; GroupFolder remains the storage permission model.
- Do NOT expose a "preview" API showing what role a user would gain by joining a hypothetical group (potential future follow-up).
- Do NOT grant roles via filesystem ACL or GroupFolder permissions — the two systems are intentionally separate.

## Decisions

### D1: Net-new capability — no source counterpart

**Decision**: Admin-roles is an entirely new MyDash design. The source app has no named-role concept and resolves all permissions from the GroupFolder ACL bitmask alone.

**Source evidence (what the source DOES have, for context)**:
- `intravox-source/lib/Service/PermissionService.php:1-150` — bitmask resolver from GroupFolder ACL only (READ/UPDATE/CREATE/DELETE/SHARE). No role table, no role names, no precedence resolver.
- `intravox-source/lib/Service/SetupService.php` — three NC groups seeded with GroupFolder permissions at install time, but they are standard Nextcloud groups, not an in-app role model. No app-level code references them by name after seeding.

**Rationale**: The source's ACL bitmask model cannot express a "Viewer" role meaningfully — a user who owns a personal dashboard would normally have write access via GroupFolder ownership, but MyDash's Viewer role must block that. Expressing this distinction requires an in-app role layer that overrides GroupFolder-derived permissions, not a bitmask union.

### D2: Storage — dedicated `oc_mydash_role_assignments` table, not derived from groups

**Decision**: Persist role assignments in a DB table with one row per assignment. Each row binds either a `userId` OR a `groupId` (XOR constraint) to one of three roles. Schema fields: `id` (auto-increment PK), `userId` (VARCHAR 64, nullable), `groupId` (VARCHAR 64, nullable), `role` (VARCHAR 10: "admin"/"editor"/"viewer"), `assignedBy` (VARCHAR 64, audit), `assignedAt` (DATETIME, audit). A UNIQUE constraint prevents duplicate assignments per target and role.

**Alternatives considered:**
- **Derive from NC group name** (user in NC group `mydash-admin` → Dashboard Admin automatically): rejected because group names are brittle (renaming breaks the mapping), user-specific assignments outside groups become impossible, and the coupling is implicit and unauditable.
- **Single per-user role column on the users/profile table**: rejected because group-scoped assignments are a primary use case (assigning all of "marketing" the editor role at once), and a single column cannot represent group-level delegation.
- **Reuse GroupFolder permission bits**: rejected because GroupFolder ACL cannot express the Viewer role's personal-dashboard restriction, and the ACL layer is owned by Nextcloud's GroupFolders app, not MyDash.

**Rationale**: DB-backed assignments are explicit, auditable (assignedBy/assignedAt), and survive group renames and deletions gracefully (cascade listeners handle cleanup). They support both user-level and group-level scope in a single table with a clean XOR constraint. The zero-impact migration (new table only) means existing installs are unaffected until an admin makes an explicit assignment.

### D3: Three-role taxonomy — Admin / Editor / Viewer

**Decision**: Three roles named "admin", "editor", "viewer", defined in the spec. Names match the source app's three seeded NC group names to ease customer migration recognition.

**Source evidence**:
- `intravox-source/lib/Service/SetupService.php` — seeded groups `IntraVox Admins`, `IntraVox Editors`, `IntraVox Users`.

**Rationale**: Three levels map cleanly to real organisational needs (full delegation, content delegation, read-only access) without introducing fine-grained permission combinations that require complex UI to manage. The naming alignment with the source groups is intentional UX continuity, not a technical dependency — MyDash does not read from those groups at runtime.

### D4: Effective-role resolution — highest privilege wins

**Decision**: For a given user, effective role is computed in this order:
1. If the user is a Nextcloud admin → effective role is "admin", source is "nc-admin". No assignment lookup required.
2. Otherwise, collect: the user's direct assignment (if any) and the role from each NC group assignment where the user is a member.
3. Map roles to a numeric rank: "admin"=2, "editor"=1, "viewer"=0.
4. Effective role = the assignment with the highest rank. Direct user assignment and group assignments are pooled and compared by rank — neither automatically beats the other; the higher rank wins.
5. If no assignments exist → effective role is null; falls back to `permissions` capability behavior.

Note: REQ-ROLE-009 scenario 1 describes a case where a user's direct "viewer" assignment wins over a group "admin" assignment, because direct assignment "takes precedence." This conflicts with a pure highest-wins rule. The spec resolves this by treating direct user assignment as the canonical source when it exists — if a direct user assignment is present, it is used as-is regardless of group assignments. Group assignments are only consulted when no direct user assignment exists.

**Rationale**: Highest-privilege-wins for group assignments is intuitive (belonging to a higher-privileged group should not be negated by another lower-privileged group), consistent with GroupFolder ACL union semantics, and easy to implement (MAX over role ranks). The direct-assignment-wins-over-groups rule gives administrators a predictable explicit override mechanism — if you assign a user "viewer" directly, that is intentional and should not be silently overridden by their group memberships. NC admin is the unconditional root override, consistent with Nextcloud's own privilege model.

### D5: Cascade on user/group deletion

**Decision**: User deletion triggers removal of all `oc_mydash_role_assignments` rows where `userId = <deleted-user>`. Group deletion triggers removal of all rows where `groupId = <deleted-group>`. Wired via `UserDeletedListener` and `GroupDeletedListener`, referencing the `dashboard-cascade-events` capability's listener infrastructure. No cross-deletion: removing an assignment does not affect the user or group.

**Rationale**: Stale assignments for deleted users or groups waste storage and could cause unexpected behavior if a user/group ID is reused. Listener-based cascade is the standard Nextcloud pattern and requires no schema-level foreign keys across apps.

### D6: Setup-wizard seeding (optional)

**Decision**: The setup-wizard sibling capability MAY include an optional step — "Bootstrap roles from existing NC groups?" — that creates one role assignment per role, binding each to a selected NC group. This is recommended for greenfield installs to align MyDash roles with organisational group structure, but is not required.

**Rationale**: Customers migrating from the source app already have NC groups corresponding to the three roles. The wizard step converts that group structure into explicit MyDash role assignments in one click, without requiring admins to manually re-enter each group via the role-assignment API.

## Spec changes implied

- **REQ-ROLE-001**: Add a NOTE confirming Dashboard Admin is a net-new MyDash capability with no source counterpart. Clarify that NC admin status is an implicit grant — no assignment row is created.
- **REQ-ROLE-005**: Tighten the effective-role resolution description to match D4: direct user assignment wins over group assignments (not simply "highest"); group assignments use highest-wins among groups; NC admin is unconditional override.
- **REQ-ROLE-009**: Reconcile scenario 1 (direct "viewer" beats group "admin") with the resolution algorithm in REQ-ROLE-005 — make explicit that direct user assignment is used as-is when present, groups consulted only when absent.
- **REQ-ROLE-010 / REQ-ROLE-011**: Reference `dashboard-cascade-events` capability by name in the cascade requirements so implementers know which listener infrastructure to extend.

## Open follow-ups

- Whether assigning a role to the literal `'default'` group (the multi-scope-dashboards `'default'` sentinel) should grant the role to every authenticated user — likely yes for symmetry, needs a REQ.
- Whether MyDash should expose a preview API ("what role would user X have if they joined group Y?") for admins planning assignments — out of scope for this spec, candidate for a future `admin-roles-v2` spec.
- Whether per-dashboard role overrides are ever needed (deliberately excluded as a Non-Goal above, but worth revisiting once the base role system is live).
