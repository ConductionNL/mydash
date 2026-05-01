# Design — Demo data showcases

## Context

The spec (`REQ-DEMO-001` through `REQ-DEMO-009`) was written before the source app's showcase structure was examined in detail. This document resolves the open design question about how bundled showcase templates are stored and what their content looks like, so the spec can be grounded in the real file format rather than a hypothetical one.

Source examined: `intravox-source/showcases/`, `intravox-source/demo-data/`, `intravox-source/lib/Service/DemoDataService.php`, `intravox-source/lib/Controller/DemoDataController.php`, `intravox-source/lib/Command/ImportDemoDataCommand.php`, `intravox-source/lib/Command/AddDemoFieldsCommand.php`.

## Goals / Non-Goals

**Goals:**
- Resolve the open question about file format and storage location for bundled showcases.
- Identify which widget types the reference showcases use (confirming what MyDash must support at install time).
- Clarify the idempotency, localization, and asset bundling mechanisms as actually implemented.

**Non-Goals:**
- Spec edits (follow-up task).
- Changing which showcases ship (that is a product decision).
- Mirroring the source app's group-folder-based storage model — MyDash uses a different storage pattern.

---

## Decisions

### D1: Showcase storage location + format

**Decision**: Showcases are stored as a **ZIP archive** containing a machine-readable `export.json` and a `{locale}/` directory tree with per-page JSON files, per-page `_media/` image assets, a root `navigation.json`, and a root `footer.json`. The `appdata/demo/` path assumed in the spec does not match the source — the actual path is `showcases/{id}/` with the canonical delivery artifact being `{id}.zip`.

**Source evidence**:
- `intravox-source/showcases/de-bron/` contains `export.json`, `de-bron.zip`, loose JPEG assets, and a `nl/` directory.
- `unzip -l de-bron.zip` reveals: `export.json` (machine-readable manifest + full page dump), `nl/` (locale tree), `nl/home.json`, `nl/navigation.json`, `nl/footer.json`, `nl/_media/*.jpg`.
- `intravox-source/lib/Service/ImportService.php:57-63` — `importFromZip()` opens `export.json` inside the ZIP as the canonical entry point.
- `intravox-source/lib/Service/DemoDataService.php:786-817` — `getBundledDemoDataPath()` resolves the path at `{appPath}/demo-data/{language}/`, which is a different (flat locale tree) path used only for the generic product demo data, NOT for showcases.

The two systems are distinct:
- **`demo-data/`** — product tour content (generic IntraVox pages, multi-locale `nl/`, `en/`, `de/`, `fr/`). Installed via `importBundledDemoData()`.
- **`showcases/`** — persona-specific intranet examples (healthcare org, university, municipality, tech company, law firm). Installed by importing the showcase ZIP through the same `importFromZip()` pathway.

**MyDash implication**: MyDash should adopt the ZIP + `export.json` format as the canonical showcase bundle format, not a flat JSON file at `appdata/demo/{id}.json`. The spec's assumed schema is mostly compatible but the delivery artifact is a ZIP, not a bare JSON.

---

### D2: Schema shape

**Decision**: The per-showcase schema is a **two-level structure**. The `export.json` wraps one or more pages; individual page files (`home.json` etc.) carry the actual layout. The spec's widget schema shape (`type`, `position`, `config`) is wrong — the real schema uses `type`, `column`, `order`, plus type-specific flat fields.

**Top-level `export.json` shape** (`intravox-source/showcases/de-bron/export.json:1-20`):
```json
{
  "exportVersion": "1.3",
  "schemaVersion": "1.3",
  "exportDate": "2026-03-07T12:00:00.000Z",
  "exportedBy": "IntraVox Showcase - ...",
  "requiresMinVersion": "0.8.11",
  "language": "nl",
  "pages": [
    {
      "_exportPath": "home",
      "uniqueId": "page-<stable-uuid>",
      "title": "...",
      "content": { /* full page object */ }
    }
  ],
  "navigation": { "type": "megamenu", "items": [ ... ] },
  "footer": { "content": "..." },
  "comments": []
}
```

**Per-page content object** (`nl/home.json` in each showcase):
```json
{
  "uniqueId": "page-<stable-uuid>",
  "title": "...",
  "language": "nl",
  "layout": {
    "columns": 1,
    "headerRow": {            /* optional — only van-der-berg uses it */
      "enabled": true,
      "backgroundColor": "...",
      "widgets": [ ... ]
    },
    "rows": [
      {
        "columns": 1,           /* 1-4 */
        "backgroundColor": "var(--color-...) | \"\"",
        "collapsible": true,    /* optional */
        "sectionTitle": "...",  /* required if collapsible */
        "defaultCollapsed": true,
        "widgets": [
          {
            "type": "heading|text|image|links|divider|video|news|people|file",
            "column": 1,
            "order": 1,
            /* type-specific fields inline (see D3) */
          }
        ]
      }
    ],
    "sideColumns": {
      "left":  { "enabled": false, "backgroundColor": "", "widgets": [] },
      "right": { "enabled": true,  "backgroundColor": "...", "widgets": [ ... ] }
    }
  }
}
```

The spec schema (`gridColumns`, `metadataFields`, `widgets[].position.{x,y,w,h}`, `widgets[].config`) does not match. The actual layout is row-based (not grid-coordinate-based), uses `column` + `order` integers, and embeds widget configuration inline rather than in a `config` sub-object.

---

### D3: Widget types referenced

**Decision**: 8 distinct widget types appear across all 5 showcases and the `demo-data/` locale trees. All 8 must be supported at install time or handled by the skip-on-missing mechanism.

| Widget type | Count (showcases) | Count (demo-data) | Notes |
|---|---|---|---|
| `heading`   | 60 | 376 | `content`, `level` (1-5) |
| `text`      | 42 | 313 | `content` (markdown) |
| `divider`   | 39 | 149 | `style`, `color`, `height` |
| `links`     | 16 | 114 | `layout` (tiles\|list), `columns`, `items[]` |
| `image`     | 14 | 165 | `src` (relative filename), `alt`, `objectFit`, optional `caption` |
| `file`      | 7  | 0   | `path` (groupfolder-relative), `name` |
| `news`      | 4  | 7   | `sourcePath`, `layout` (carousel\|grid), `columns`, `limit`, `sortBy`, `sortOrder`, `showImage`, `showDate`, `showExcerpt`, `autoplayInterval`, `filters` |
| `video`     | 2  | 1   | `provider` (embed), `src` (URL), `title`, `autoplay`, `loop`, `muted` |
| `people`    | 2  | 0   | `selectionMode`, `filters`, `layout` (card), `columns`, `limit`, `sortBy`, `showFields{}` |

Source: grep of all `"type":` lines in `intravox-source/showcases/*/export.json` and `intravox-source/demo-data/**/*.json`.

Widget types `calendar`, `welcome`, `recent-activity` referenced in the spec scenarios do NOT appear in any showcase. They are spec fiction; remove them from examples.

**Spec-critical finding**: `people` and `news` are the two highest-complexity widgets. `people` queries live Nextcloud user data (role, department, custom LDAP fields). `news` cross-references other pages by `sourcePath`. Both require runtime resolution at display time — they do NOT embed their data in the showcase JSON.

---

### D4: Idempotency mechanism

**Decision**: The source app tracks idempotency via `IConfig::getAppValue(APP_ID, 'demo_data_imported', 'false')` — a single boolean per app, not per showcase. There is NO per-showcase tracking. The `--force` CLI flag bypasses the guard; without it, the command exits early if the flag is set.

Source: `intravox-source/lib/Service/DemoDataService.php:69-78` (`isDemoDataImported` / `markDemoDataImported`), `intravox-source/lib/Command/ImportDemoDataCommand.php:69-73`.

**MyDash implication**: The spec's approach (query existing `group_shared` dashboards with `metadata.showcaseId`) is more granular and correct for a multi-showcase system. Adopt it. The source app's single-flag approach is a simplification that only works when there is one "demo dataset" being installed.

---

### D5: Localization handling

**Decision**: Showcases are **NL-only**. All 5 showcase directories have exactly one locale subdirectory (`nl/`). There are no `en/`, `de/`, or `fr/` variants for any showcase. The `export.json` carries `"language": "nl"` in all 5 cases.

The separate `demo-data/` product tour content IS multi-locale: `nl/`, `en/`, `de/`, `fr/` directories exist, but `de/` and `fr/` are marked as non-full (`"full": false`) in `LANGUAGE_META`. English and Dutch are full; German and French are partial stubs.

**MyDash implication**: The spec assumes each showcase ships `{id}-en.json` and `{id}-nl.json`. Reality: showcases are NL-only. The spec's REQ-DEMO-001 scenario "Localized showcase files are provided" and REQ-DEMO-007 localization requirements should be rewritten to reflect that v1 showcases are NL-only, with a language fallback path documented for future expansion. The `?lang=` parameter on the install endpoint can remain for forward compatibility but will always resolve to NL in v1.

---

### D6: Asset bundling

**Decision**: Images are **bundled as real JPEG files** inside the showcase ZIP under `{locale}/_media/`. They are NOT external URLs and NOT base64-embedded. The JPEG filenames are referenced in widget `src` fields as bare filenames (e.g., `"src": "zorgteam.jpg"`), not paths. Resolution to actual file happens at display time by resolving relative to the page's `_media/` folder.

Source: `unzip -l de-bron.zip` shows `nl/_media/locatie.jpg`, `nl/_media/verpleging.jpg`, `nl/_media/zorgteam.jpg`. The `export.json` widget entry reads `"src": "zorgteam.jpg"`.

The ZIP also contains loose JPEG copies at the root of the showcase directory (e.g., `intravox-source/showcases/de-bron/zorgteam.jpg`) — these appear to be build-time artifacts or convenience copies, not the canonical location.

**MyDash implication**: On install, the service must extract `{locale}/_media/` from the ZIP and store the images alongside the installed page data (or in Nextcloud Files). The spec does not currently specify where installed media lands — this needs to be decided.

---

## Spec changes implied

The following REQ-DEMO-NNN items need rewriting based on this investigation:

- **REQ-DEMO-001 (Bundled showcase files)**: Change storage format from `appdata/demo/{id}-{lang}.json` to `showcases/{id}/{id}.zip` (ZIP archive with `export.json` manifest). Change schema example from grid-coordinate `position.{x,y,w,h}` + `config` to row-based `column`/`order` inline flat fields. Update showcase ID set: replace `marketing`, `engineering`, `all-staff`, `project`, `community` with the actual 5: `de-bron` (healthcare), `de-linden` (university), `gemeente-duin` (municipality), `horizon-labs` (tech/startup), `van-der-berg` (legal). Alternatively keep fictional IDs but be explicit the bundled themes differ. Remove `metadataFields` from the schema — no showcase uses it.

- **REQ-DEMO-001 scenarios "Localized showcase files are provided"** and **REQ-DEMO-007**: Rewrite to reflect NL-only for v1; the `?lang=` fallback path exists but always resolves to NL in v1. Mark multi-locale as a v2 goal.

- **REQ-DEMO-003 / REQ-DEMO-005 (install scenarios with widget types `welcome`, `calendar`, `recent-activity`)**: Replace example widget types with actual ones: `heading`, `text`, `image`, `links`, `divider`, `video`, `news`, `people`, `file`.

- **REQ-DEMO-003**: Add a sub-scenario for media asset extraction — when a showcase is installed, images in `_media/` must be stored somewhere accessible. The spec currently says nothing about this.

- **REQ-DEMO-004 (idempotency)**: The mechanism described (query by `metadata.showcaseId`) is sound and more correct than the source app's boolean flag. Keep it, but document that the source app used a cruder approach.

- **REQ-DEMO-009 (CLI)**: Command name shape is fine. Add a `--force` flag to allow reinstall without UUID-return shortcut (matches source app pattern).

---

## Open follow-ups

1. **Media storage on install**: Where do extracted `_media/` images live in MyDash? Options: (a) Nextcloud Files under a shared folder, (b) app-level storage path, (c) DB as blob. Must be decided before implementation.

2. **`file` widget install behavior**: The `file` widget takes a groupfolder-relative path (`"path": "Protocollen/Medicatieprotocol_2026.pdf"`). These files do not exist in a fresh install. Does MyDash skip these silently (like unknown widget types), create placeholder entries, or omit the widget? The skip-on-missing mechanism in REQ-DEMO-005 applies to unknown widget *types*, not to missing *files referenced by known widget types* — a gap in the spec.

3. **`news` widget cross-page resolution**: The `news` widget references `sourcePath` (e.g., `"sourcePath": "nieuws"`). This is a sibling page path within the same locale tree. When installing a single-page showcase (only `home.json` in the ZIP), the `news` widget's source will resolve to nothing. Either multi-page showcases should be added, or the `news` widget should gracefully show empty state, or the spec should note this caveat.

4. **`people` widget live data**: The `people` widget renders live Nextcloud user directory data. It will work in any installation (it just shows real users filtered by configured criteria), but the showcase's demo filters (e.g., `"selectionMode": "filter", "filters": []`) may show unexpected results in non-demo environments. No spec change needed, but worth noting in the admin UI install confirmation.

5. **`headerRow` layout key**: One showcase (`van-der-berg`) uses `layout.headerRow` (a special full-width row rendered above the main grid). The spec and the schema example omit this. If MyDash adopts the same layout model, `headerRow` needs to be in the layout schema.

6. **Showcase naming for MyDash**: The source showcases use Dutch fictional organization names. MyDash may want locale-neutral IDs (e.g., `healthcare`, `education`, `government`, `technology`, `legal`) with themed content that can be translated. Decide before writing the actual showcase JSON files.

7. **`requiresMinVersion` field in `export.json`**: All 5 export manifests carry `"requiresMinVersion": "0.8.11"`. MyDash should define a minimum app version check at import time and return a clear error if the running version is older.
