# Design — Org-wide navigation editor

## Context

The `navigation-editor-org` spec (REQ-ONAV-001 through REQ-ONAV-012) was drafted assuming that
the org-wide navigation tree would be stored as a single JSON blob in a Nextcloud app config key
(`mydash.org_navigation_tree`, max 64 KB). This design document resolves that open question by
examining how the reference intranet product actually stores and serves its navigation tree.

## Goals / Non-Goals

**Goals:**
- Decide the storage backend for the org-wide navigation tree
- Align the API shape (wholesale PUT vs per-node CRUD) with the chosen backend
- Clarify per-language navigation, group-visibility encoding, depth enforcement, and active-item detection

**Non-Goals:**
- Defining the page-content storage (pages live in Nextcloud GroupFolders — unchanged)
- Per-user personalisation of the org-nav (out of scope for this capability)
- Rewriting the spec in this document; flag only which REQ-ONAV-NNN items need amendment

---

## Decisions

### D1: Storage — JSON blob in app config vs file on disk vs DB table

**Decision:** Store the org-nav tree as a **JSON file on the Nextcloud filesystem** (`navigation.json`)
inside a dedicated GroupFolder path, one file per language. Do **not** use a monolithic app-config key.

**Source evidence:**
- `intravox-source/lib/Service/NavigationService.php:44–91` — `getNavigation()` opens
  `IntraVox/{lang}/navigation.json` from the user's mounted GroupFolder view.
- `intravox-source/lib/Service/NavigationService.php:122–143` — `saveNavigation()` writes or
  creates `navigation.json` in the language folder via `file->putContent()`.
- `intravox-source/lib/Service/SystemFileService.php:29` — fallback reads the same JSON file at
  up to 5 MB; `ALLOWED_SHARED_FILES = ['navigation.json', 'footer.json']`.
- `intravox-source/lib/Controller/NavigationController.php:51–103` — single `GET /api/navigation`
  reads the file; no DB or app-config call anywhere in the call chain.
- `intravox-source/lib/Migration/` — no migration creates a `navigation` table. The two DB tables
  that do exist (`intravox_lms_tokens` from Version001200, `intravox_page_index` from Version001300)
  serve OAuth tokens and a page-metadata search index, respectively.

**Implication for MyDash:** The spec's `mydash.org_navigation_tree` Nextcloud app-config key and
64 KB cap are **wrong**. A file-based approach inside a dedicated Nextcloud GroupFolder (or an
equivalent user-accessible storage location) scales to megabytes and separates concerns cleanly.
For MyDash's simpler use-case (admin-only tree, no per-user GroupFolder ACL complexity), storing
`navigation.json` in a well-known Nextcloud app-data folder (e.g. `appdata_<instanceid>/mydash/`)
is equivalent in principle and avoids GroupFolder dependency. The write API must still be
wholesale-replace per file write, but the file itself is not bounded by the 64 KB app-config limit.

---

### D2: Per-language navigation

**Decision:** Separate `navigation.json` file per language under a language subfolder
(`IntraVox/{lang}/navigation.json`). Languages supported: `nl`, `en`, `de`, `fr`.

**Source evidence:**
- `intravox-source/lib/Service/NavigationService.php:20` — `SUPPORTED_LANGUAGES = ['nl', 'en', 'de', 'fr']`
- `intravox-source/lib/Service/NavigationService.php:220–233` — `getLanguageFolder()` navigates
  to `IntraVox/{lang}/`; creates the folder if absent.
- `intravox-source/lib/Command/CopyNavigationCommand.php:15–138` — `intravox:copy-navigation`
  copies one language's JSON file to another. Arguments are `source` and `target` language codes
  (or `all`). This proves trees are independent per-language files, not a single tree with
  language tags embedded in node objects.

**Implication for MyDash:** The spec makes no mention of per-language trees. REQ-ONAV-001 (node
schema) and REQ-ONAV-003 (PUT API) define a single tree with no language dimension. If MyDash needs
multi-language org-nav in the future, the storage format must add a language segment. For the
current scope (NL + EN), the recommended approach is to store two files:
`appdata/mydash/org-navigation-{lang}.json`. A copy/sync CLI command modelled on
`CopyNavigationCommand` should be considered as a follow-up.

---

### D3: Group-visibility encoding

**Decision:** Group visibility is **not** encoded in the navigation JSON in the reference
implementation. Filtering is done by file-system ACL (GroupFolder read permissions), not by
per-node group arrays.

**Source evidence:**
- `intravox-source/lib/Service/PermissionService.php:535–582` — `filterNavigation()` checks
  whether the user can read the page the nav item points to (`canRead($pagePath)`), using
  Nextcloud's native filesystem ACL. There is no `groupVisibility` field in the node schema.
- `intravox-source/lib/Service/NavigationService.php:180–196` — validated node schema has fields:
  `id`, `title`, `uniqueId`, `url`, `target`, `children`. No `groupVisibility` field.
- The `intravox_page_index` table (`parent_id`, `unique_id`, `language`, `path`, `status`) also
  has no group-visibility column.

**Implication for MyDash:** The spec's `groupVisibility: ["g1","g2"]` per-node array field is a
**MyDash invention** with no reference-implementation counterpart. This is a valid design choice
for an admin-curated tree that has no corresponding filesystem ACL to piggyback on. The per-node
array approach (null = all, array = restrict) is simpler than a join table for a tree stored in a
single JSON file. The spec's approach should be **retained** but the implementation must add
explicit group-membership checking logic that the reference source delegates to the filesystem.

No join table is needed; the array-per-node approach is correct for file-based storage.

---

### D4: Write API shape

**Decision:** Wholesale replacement (`POST /api/navigation` with the full tree) — identical in
effect to a PUT-replace. The reference source does not expose per-node CRUD endpoints.

**Source evidence:**
- `intravox-source/appinfo/routes.php:57–58`:
  ```
  ['name' => 'navigation#get',  'url' => '/api/navigation', 'verb' => 'GET'],
  ['name' => 'navigation#save', 'url' => '/api/navigation', 'verb' => 'POST'],
  ```
- `intravox-source/lib/Controller/NavigationController.php:109–138` — `save()` accepts the
  entire navigation object and calls `saveNavigation()` which overwrites `navigation.json`.
- No PATCH, no per-node PUT, no DELETE by id.

**Implication for MyDash:** The spec's `PUT /api/admin/org-navigation` wholesale-replace design
is **confirmed correct**. The 64 KB limit in REQ-ONAV-001/003 is wrong (see D1); remove it. File
size is bounded only by practical tree size (hundreds of nodes are well under 1 MB).

---

### D5: Depth limit

**Decision:** Enforced in **both** backend service (silent truncation) and frontend editor (visual
block).

**Source evidence:**
- `intravox-source/lib/Service/NavigationService.php:166–197` — `validateNavigationItems()` takes
  a `$level` argument; silently returns `[]` when `$level > 3`; recursion stops at `$level < 3`
  for children. No HTTP 400 — excess depth is silently discarded.
- `intravox-source/src/components/NavigationItem.vue:80` — `v-if="level < 3"` hides the
  "Add sub-item" button at level 3 in the editor.

**Implication for MyDash:** The spec's REQ-ONAV-003 returns HTTP 400 on depth violation — that is
a **stricter and safer** approach than silent truncation. Retain the 400 response. The frontend
depth guard (`v-if="level < 3"`) matches REQ-ONAV-007 exactly.

---

### D6: Active-item detection

**Decision:** Entirely **client-side** in the frontend Vue component. The backend returns the full
(filtered) tree; the active node is computed in JavaScript by comparing `window.location` to each
node's URL or `uniqueId` hash.

**Source evidence:**
- `intravox-source/lib/Controller/NavigationController.php` — response contains `navigation`
  (tree), `canEdit`, `language`, `permissions`. No `activeNodeId` or server-side resolved active
  flag.
- `intravox-source/src/components/Navigation.vue:330–364` — `getItemKey()` and `getItemUrl()`
  derive the key from `uniqueId` or a slug; click handlers call `$emit('navigate', item)`.
  Active item tracking (`activeDropdown`, `activeMegaMenu`) is local component state for
  hover/focus, not a URL-match-based active class.
- No backend route that returns "the active node for the current URL."

**Implication for MyDash:** The spec's REQ-ONAV-009 (client-side URL prefix match for `active`
CSS class) is **confirmed correct**. Nothing needs to change.

---

### D7: Mobile vs desktop rendering

**Decision:** A single component handles both; mobile hamburger + expand/collapse is CSS-class
and `v-if` driven, not a separate component or route.

**Source evidence:**
- `intravox-source/src/components/Navigation.vue:11–82` — `<!-- Mobile hamburger menu -->` block
  with `mobile-nav`, `mobile-nav-level-2`, `mobile-nav-level-3`, `mobile-nav-level-4` CSS classes.
  The desktop dropdown/megamenu is a sibling block in the same template.
- `intravox-source/src/components/Navigation.vue:489–530` — CSS defines `.mobile-nav` and indent
  classes. No responsive breakpoint JS; toggling is via Vue `data` state (`mobileExpandedItems`).

**Implication for MyDash:** REQ-ONAV-010's 800 px breakpoint + hamburger/drawer is correct in
principle. The reference uses CSS classes rather than a JS `window.resize` listener; MyDash can
use either. No spec change needed.

---

## Spec changes implied

The following REQ-ONAV-NNN items contain assumptions that contradict the file-based storage model
or reference-source evidence and should be rewritten when the spec moves to `review`:

1. **REQ-ONAV-001** — Remove `mydash.org_navigation_tree` app-config key and 64 KB cap. Replace
   with file-based storage path (e.g. `appdata/mydash/org-navigation-{lang}.json`). Update node
   schema to add `groupVisibility` explicitly (it is a MyDash addition, not inherited).

2. **REQ-ONAV-003** — Remove mention of persisting to `mydash.org_navigation_tree`. Reference the
   file path from REQ-ONAV-001. Keep HTTP 400 on depth violation (stricter than reference, but
   intentionally so). Remove the 64 KB mention.

3. **REQ-ONAV-001 / REQ-ONAV-003** — Add a per-language dimension: the API should accept an
   optional `?lang=` query parameter (default: organisation default language). Alternatively,
   define a separate endpoint per language. If single-language-only is acceptable for v1, document
   the constraint explicitly rather than silently ignoring it.

4. **REQ-ONAV-004** — `mydash.org_navigation_position` as an app-config key is fine (it is a
   scalar, not a tree). No change needed for this requirement.

5. **REQ-ONAV-002** — Backend group-filtering logic must be added explicitly; it does not come
   from filesystem ACL in MyDash's architecture (no GroupFolder ACL to piggyback on). The spec
   already describes this correctly. Confirm the `IGroupManager` dependency is included in the
   service.

---

## Open follow-ups

- **Multi-language v1 scope:** Decide before implementation whether MyDash v1 ships one tree
  (default language) or two (NL + EN). If two: add `?lang=` to GET/PUT and store two files.
- **File size practical limit:** With no 64 KB cap, define a practical upper bound in validation
  (e.g. 512 KB or 1000 nodes) to prevent runaway JSON accumulation.
- **CLI copy command:** Consider a `mydash:copy-org-navigation <source> <target>` OCC command
  modelled on `CopyNavigationCommand` for admin workflows.
- **`groupVisibility` cascade rule edge case:** Spec says hidden parents cascade to children
  (REQ-ONAV-002). Confirm whether a child with explicit `groupVisibility: null` overrides a
  hidden parent or stays hidden. Reference implementation hides children unconditionally if the
  parent page is inaccessible.
- **`uniqueId` vs UUID:** Reference source uses arbitrary `id: uniqid('nav_')` strings (not UUID
  v4). REQ-ONAV-001 requires UUID format. Clarify whether UUIDs are generated client-side (editor)
  or server-side (on POST).
- **SystemFileService fallback pattern:** Reference uses a system-context file reader for users
  with restricted GroupFolder ACL. MyDash stores nav in `appdata` (admin-written), so all
  authenticated users read the same file — no ACL fallback needed. Confirm and document this
  simplification.
