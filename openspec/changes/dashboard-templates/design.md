# Design — Dashboard Templates

## Context

The existing `admin-templates` capability (REQ-TMPL-001..011, status: implemented) stores dashboard
templates as rows in `oc_mydash_dashboards` with `type='admin_template'`. Admins create templates,
target them at groups, and users receive personal copies on first access. The full storage model,
distribution logic, and permission resolution are already live.

This change (`dashboard-templates`) adds three capabilities on top of that foundation: a self-service
gallery where any logged-in user can browse available templates; a save-as-template action that lets
dashboard owners convert their work into a reusable blueprint; and three metadata fields
(`templateCategory`, `templatePreviewImage`, `templateDescription`) that make templates discoverable.
The new requirements are REQ-TMPL-012..015; existing REQ-TMPL-001..011 are untouched.

A deep-dive into the reference implementation was conducted to understand how it handles templates.
The reference implementation stores templates as filesystem folders under `/{lang}/_templates/`
within a GroupFolder, with template membership inferred from path convention rather than a type
discriminator. This design document records that divergence explicitly and explains why MyDash keeps
its current DB enum approach.

## Goals / Non-Goals

**Goals:**
- Expose `GET /api/templates/gallery` — indexed, fast, list-only (no widget tree in response).
- Expose `POST /api/dashboards/{uuid}/save-as-template` — owner-only, creates a deep-copied
  `admin_template` row with a fresh UUID.
- Add three nullable metadata columns to `oc_mydash_dashboards`: `templateCategory VARCHAR(64)`,
  `templatePreviewImage TEXT`, `templateDescription TEXT`.
- Reuse the `custom-icon-upload-pattern` for preview image upload; no parallel mechanism.
- Keep the gallery query single-pass: `WHERE type='admin_template'` with optional
  `AND templateCategory=?`, ordered by `(templateCategory, name)` or `lastUpdatedAt DESC`.

**Non-Goals:**
- Do NOT switch template storage to filesystem folders under `_templates/` — this is a deliberate
  and permanent divergence from the reference implementation.
- Do NOT change existing REQ-TMPL-001..011 endpoints, data model fields, or distribution logic.
- Do NOT introduce a fixed enum for `templateCategory`; values are free-form admin-curated strings.
- Do NOT add pagination to the gallery endpoint in this change (100+ templates within 500ms is the
  performance target; pagination can be a follow-up if needed).
- Do NOT modify the reference implementation's approach to templates — it is recorded as evidence
  only, not as a target to converge on.

## Decisions

### D1: Storage model — keep DB type enum, deliberately diverge from reference implementation

**Decision**: Templates remain `type='admin_template'` rows in `oc_mydash_dashboards`. The three new
metadata fields are nullable columns on the same table. No filesystem folder convention is
introduced.

**Alternatives considered:**

- **Filesystem folders under `_templates/` per language (the reference implementation's approach)**:
  Rejected for five reasons:
  1. The existing `admin-templates` capability (REQ-TMPL-001..011) is already shipped and live.
     Switching storage models now is a breaking change — all existing templates, the distribution
     chain, and permission resolution would need to be re-implemented.
  2. DB enum lookup is a single indexed query (`WHERE type='admin_template'`). The filesystem
     approach requires walking the full page tree and string-matching each path for the `/_templates/`
     segment — worse performance at scale.
  3. The DB enum cleanly separates concerns: one row per dashboard, one `type` column. The filesystem
     path convention conflates location with kind, which makes the invariant harder to enforce and
     test.
  4. MyDash has two content-storage backends: database rows and groupfolder-backed dashboards (see
     the `groupfolder-storage-backend` change). The filesystem `_templates/` approach only makes
     sense in a GroupFolder world. DB-backed dashboards have no GroupFolder and therefore no
     `_templates/` folder; a cross-backend representation requires the DB type column.
  5. The reference implementation's filesystem approach grants templates GroupFolder ACL inheritance
     "for free". MyDash achieves equivalent access control via the existing `dashboard-sharing`
     capability; no ACL advantage is lost.

**Rationale**: The DB enum approach was the correct design when `admin-templates` was built and
remains correct here. The reference implementation's path convention is a reasonable fit for a
purely GroupFolder-backed system with no `type` column; MyDash's architecture differs on both counts.
Recording this as a deliberate divergence rather than a discrepancy ensures future contributors do
not attempt convergence.

**Source evidence (what the reference implementation does, not what we are adopting):**

- `intravox-source/lib/Service/SetupService.php:337-341` — `_templates` subfolder is created under
  each language folder (`nl/_templates/`, `en/_templates/`) during initial setup. Code:
  ```php
  $langFolder->newFolder('_templates');
  $this->logger->info("Created _templates folder in {$lang}");
  ```
- `intravox-source/lib/Service/SetupService.php:664-699` — `migrateTemplatesFolders()` is an
  idempotent migration that adds `_templates/` to existing installs that lack it. Runs per language.
- `intravox-source/lib/Service/SetupService.php:711-784` — `installDefaultTemplates()` reads
  `demo-data/templates/*.json`, creates a subfolder per template name under each language's
  `_templates/`, writes the JSON, and copies a `_media/` folder alongside it. Template identity is
  the subfolder name, not a DB row.
- `intravox-source/lib/Service/PageService.php:564` — `navigation.json` and `footer.json` at the
  language root are excluded from page listings; these are config files, not pages.
- `intravox-source/lib/Service/PageService.php:1973` — The same exclusion is applied when scanning
  for media: files named `navigation.json` or `footer.json` are skipped. Template membership is
  inferred from path segment (`/_templates/`), not from any field inside the JSON.

### D2: Gallery query

**Decision**: `GET /api/templates/gallery` executes:
```sql
SELECT ... FROM oc_mydash_dashboards
WHERE type = 'admin_template'
[AND templateCategory = :cat]
ORDER BY templateCategory IS NULL, templateCategory, name
```
Nulls sort last. Optional `?sort=updatedAt` switches to `ORDER BY updatedAt DESC`. Single query, no
per-row joins. Widget placements are NOT fetched (gallery is list-only; instantiation fetches them).

**Rationale**: Leverages the existing index on `type`; adding a composite index on
`(type, templateCategory)` is noted in the spec delta (see REQ-TMPL-012). No N+1 risk because
placements are excluded from the list response.

### D3: Save-as-template

**Decision**: `POST /api/dashboards/{uuid}/save-as-template` deep-copies the source dashboard's
widget placements into a NEW row with `type='admin_template'`, a fresh UUID v4, and `userId=null`.
The source dashboard is not modified. `basedOnTemplate` on the new template is set to null —
templates do not chain.

**Rationale**: A copy rather than a link means the new template is immediately independent. Edits
to the source dashboard after save-as-template do not propagate to the template, matching the
existing REQ-TMPL-006 independence guarantee that the distribution chain already provides for user
copies.

### D4: Preview image storage

**Decision**: Reuse the `custom-icon-upload-pattern` from `openspec/changes/custom-icon-upload-pattern/`
for `POST /api/admin/templates/{uuid}/preview-image`. Same upload endpoint shape, same storage path
convention, same URL-return contract. The stored URL is written to `templatePreviewImage`.

**Rationale**: Pattern reuse avoids inventing a parallel image-persistence mechanism. The
custom-icon-upload pattern already handles file-type validation, Nextcloud Files storage, and
public-share URL generation — all requirements for preview images are covered.

### D5: Template categories — free-form string, not fixed enum

**Decision**: `templateCategory` is `VARCHAR(64)` with no server-side enum constraint. Admins type
whatever category label fits their organisation. The gallery groups by exact-match string; unknown
categories appear as their own group.

**Alternatives considered:**

- **Fixed enum** (`marketing`, `engineering`, `hr`, …): Rejected — adding new categories would
  require a schema migration, and customer category needs vary too widely to enumerate in the spec.
  Free-form is consistent with how MyDash handles other admin-curated labels (e.g., `description`).

**Rationale**: Free-form strings give admins immediate flexibility at the cost of no server-enforced
taxonomy. The gallery UI can suggest existing category values (from a `DISTINCT templateCategory`
query) to reduce accidental duplicates without requiring a fixed schema.

## Spec changes implied

- Add a NOTE to REQ-TMPL-012 that the gallery query should be backed by a composite index on
  `(type, templateCategory)` to keep the optional category-filter path indexed.
- Confirm REQ-TMPL-013 specifies deep-copy semantics (not a link or symlink) and that
  `basedOnTemplate` on the new template is null.
- Confirm REQ-TMPL-015 references the `custom-icon-upload-pattern` endpoint shape explicitly.
- The existing `specs/admin-templates/spec.md` contains no filesystem-folder language; no removals
  needed there. The thin `design.md` this document replaces did contain filesystem-folder guidance —
  that is now superseded and should not be re-introduced.

## Open follow-ups

- `basedOnTemplate` on user copies: cascade-nullify vs leave stale when the referenced template is
  deleted. Current behaviour (catch `DoesNotExistException`, fall back to own `permissionLevel`) is
  functional but implicit; explicit nullify-on-delete may be cleaner.
- `templateDescription` max length: REQ-TMPL-014 accepts 500 chars; no upper bound is set. Decide
  whether TEXT is uncapped or capped (e.g. 2000 chars) before migration is written.
- `demo-data-showcases` seeding: should showcase templates populate `templateCategory` automatically
  or leave it null for manual admin curation?
- Gallery filter UX: return a `categories` array in the gallery response (from `DISTINCT
  templateCategory`) or expose a separate `GET /api/templates/gallery/categories` endpoint?
