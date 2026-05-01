# Design — Per-language dashboard content

## Context

The `dashboard-language-content` change adds per-language content variants to MyDash dashboards.
The open design question was: how does the reference intranet app partition per-language content —
directories, JSON keys, or database rows — and does that evidence validate or challenge the
proposed `oc_mydash_dashboard_translations` table?

Source analysis covered:
- `intravox-source/lib/Command/MigrateToLanguageStructureCommand.php`
- `intravox-source/lib/Command/CreateLanguageHomepagesCommand.php`
- `intravox-source/lib/Command/CopyNavigationCommand.php`
- `intravox-source/lib/Service/PageService.php`
- `intravox-source/lib/Service/NavigationService.php`
- `intravox-source/lib/Service/FooterService.php`
- `intravox-source/lib/Service/FeedService.php`
- `intravox-source/lib/Search/PageSearchProvider.php`
- `intravox-source/lib/Controller/PageController.php`
- `intravox-source/lib/Migration/Version001300Date20260420000000.php` (and three earlier migrations)

## Goals / Non-Goals

**Goals:**
- Confirm the storage layout (option a/b/c/d) from primary source evidence.
- Pin the exact locale-resolution chain used in practice.
- Identify any mismatch between the proposed spec and the reference implementation's idioms.
- Enumerate spec scenarios that need adjustment.

**Non-Goals:**
- Copying the reference implementation's directory-based model into MyDash (domains differ).
- Re-evaluating REQ-DASH-028..031 (create/update/delete/promote) — those are independent of storage layout.

## Decisions

### D1: Storage layout — separate row per language (option c) is correct

**Decision**: The spec's `oc_mydash_dashboard_translations` table with one row per `(dashboardUuid, languageCode)` is the right-sized approach for MyDash. Option (a), per-language directories in a GroupFolder, is the actual mechanism used by the source app, but it is **filesystem-bound** and specific to an intranet wiki architecture — it is not a model MyDash should copy.

**Alternatives considered:**

- **(a) Per-language GroupFolder directories** (`IntraVox/nl/`, `IntraVox/en/`, …). This is what the source app uses. The `MigrateToLanguageStructureCommand` moves all existing flat content into a `<targetLang>/` subdirectory and creates empty sibling language folders. The `CreateLanguageHomepagesCommand` then writes `home.json` into each `<lang>/` folder. Content is a JSON file per page, stored on the Nextcloud filesystem. This approach is wholly inappropriate for MyDash, which stores dashboard widget trees in a relational database, not as GroupFolder files.

- **(b) Per-language keys inside one JSON blob** (e.g. `{"content": {"en": "…", "nl": "…"}}`). Not used anywhere in the source app. JSON is used inside each page file but as a flat content document, not as a multilingual container.

- **(c) Separate row per language** — the spec's proposed design. Matches MyDash's existing relational storage style; enables indexed lookups by `(dashboardUuid, languageCode)` without JSON parsing.

- **(d) Other**: The source app has a secondary `intravox_page_index` DB table (migration `Version001300Date20260420000000`) with a `language VARCHAR(8)` column and a `(unique_id, language)` unique index, plus `(language)` and `(language, status)` plain indexes. This is a *metadata index* over the filesystem tree — one index row per page per language. MyDash does not have a filesystem layer, so the index serves as confirmation that a `(entityId, languageCode)` composite key is the natural DB shape for multi-language data in this ecosystem.

**Rationale**: The source app moved away from flat storage to language directories because its content unit is a filesystem file. MyDash's content unit is already a DB row. The right analogy is the source app's `intravox_page_index` table schema — one row per `(entityId, language)` with a composite unique index — not the GroupFolder directory tree. The spec's proposed table matches that shape exactly.

**Source evidence:**
- `intravox-source/lib/Command/MigrateToLanguageStructureCommand.php:17,67–149` — migrates flat `IntraVox/` content INTO `IntraVox/<lang>/` subdirectories; proves option (a) is the source architecture but is filesystem-specific.
- `intravox-source/lib/Command/CreateLanguageHomepagesCommand.php:47–69` — creates `IntraVox/<lang>/home.json` per language; confirms the source app's unit of language partitioning is a directory, not a DB column.
- `intravox-source/lib/Migration/Version001300Date20260420000000.php:28–76` — `intravox_page_index` table: `language VARCHAR(8)`, `UNIQUE(unique_id, language)`, `INDEX(language)`, `INDEX(language, status)`. Confirms the DB idiom for multi-language indexing is a `(entityId, language)` composite key.
- `intravox-source/lib/Service/PageIndexService.php:27–66` — `indexPage($pageData, $language, $path)` writes one row per `(uniqueId, language)` pair; confirms the composite-key shape.

---

### D2: Locale resolution chain — two-step truncation, not three-tier prefix matching

**Decision**: The locale resolution used in practice is **two-step, not three-tier**:

1. Read `IConfig::getUserValue($userId, 'core', 'lang', 'en')` (or `IL10N::getLanguageCode()` in services wired with `IL10N`).
2. Truncate to the 2-character base code immediately: `explode('_', $lang)[0]` (or `substr($lang, 0, 2)`), discarding the regional variant.
3. Check if the base code is in `SUPPORTED_LANGUAGES = ['nl', 'en', 'de', 'fr']`; if not, fall back to `'en'` (PageService, FooterService, FeedService) or to `'nl'` (NavigationService).

The spec's three-tier matching — exact match → language-prefix match → primary fallback — conflates two distinct steps. The source app never stores `nl-BE`-style codes anywhere; it always truncates to two characters before the first lookup. The "language-part match" tier exists only to handle the format difference between what Nextcloud stores (`nl_NL` with underscore) and what the directory is named (`nl`).

**What this means for MyDash**: The `languageCode` column in `oc_mydash_dashboard_translations` should store the truncated 2-character code (`nl`, `en`, `de`, `fr`) — never a full BCP-47 locale like `nl-BE`. If MyDash wants to store `nl-BE` variants for customers who have them, that is a genuine product decision not validated by the source app, and needs an explicit spec decision.

**Resolution divergences discovered:**

| Context | Method | Default |
|---|---|---|
| `PageService::getUserLanguage()` | `IConfig::getUserValue($uid, 'core', 'lang', 'en')` + `explode('_', …)[0]` | `'en'` |
| `FooterService::getUserLanguage()` | Same call + 2-char truncation | `'en'` |
| `FeedService::getUserLanguage()` | Same call + `substr(…, 0, 2)` | `'en'` |
| `NavigationService::getCurrentLanguage()` | `IL10N::getLanguageCode()` + `substr(…, 0, 2)` | `'nl'` |
| `PageSearchProvider` | `IConfig::getUserValue($uid, 'core', 'lang', 'en')` (no truncation — passes full locale string to index query) | `'en'` |

Note: `PageSearchProvider` passes the raw value (e.g. `nl_NL`) directly to the `intravox_page_index` search without truncation — this is likely a latent bug in the source app, not intentional.

**Source evidence:**
- `intravox-source/lib/Service/PageService.php:350–356` — canonical resolution with `explode('_', $lang)[0]`.
- `intravox-source/lib/Service/NavigationService.php:239–246` — uses `IL10N` instead of `IConfig`; truncates via `substr(…, 0, 2)`. Default falls back to `'nl'`, not `'en'`.
- `intravox-source/lib/Service/FeedService.php:594–599` — same pattern as PageService, default `'en'`.
- `intravox-source/lib/Search/PageSearchProvider.php:68` — raw locale string passed without truncation (inconsistency).

---

### D3: Closed set of supported languages — not open-ended

**Decision**: The source app uses a **closed static list** `['nl', 'en', 'de', 'fr']`, hardcoded as a constant in every service. New languages require a code change. The spec should explicitly decide whether MyDash's translation table is open-ended (any BCP-47 code) or closed (admin-configured set).

**Rationale**: The spec's current `languageCode VARCHAR(16)` column and API are open-ended (any code can be posted). The source app is the opposite. MyDash is a generic dashboard tool, so open-ended is likely correct — but this should be an explicit spec decision, not an accidental omission. The `isPrimary` fallback mechanism compensates for open-ended by ensuring an unknown locale always has a safe landing.

**Source evidence:**
- `intravox-source/lib/Service/PageService.php:40` — `private const SUPPORTED_LANGUAGES = ['nl', 'en', 'de', 'fr'];`
- `intravox-source/lib/Command/MigrateToLanguageStructureCommand.php:17` — `private const LANGUAGE_FOLDERS = ['nl', 'en', 'de', 'fr'];`
- `intravox-source/lib/Service/NavigationService.php:20` — same constant repeated.

---

### D4: GroupFolder-based sharing has no analogue in MyDash

**Decision**: The source app uses a dedicated Nextcloud system user (`intravox`) and a GroupFolder (`IntraVox/`) as the content store, with per-language subdirectories inside. Nextcloud's GroupFolder ACL controls who can see which language tree. MyDash uses its own relational tables; there is no GroupFolder involved. The spec correctly does not attempt to mirror the GroupFolder pattern.

The `PageController::languagePage()` route (`/en/home`, `/nl/home`) is purely a Vue.js client-side routing shim — it returns the same template for all languages. Language resolution for actual content happens inside the API (PageService/NavigationService), not at the routing layer.

**Source evidence:**
- `intravox-source/lib/Controller/PageController.php:387–397` — `languagePage()` returns the same `TemplateResponse` as `index()` with no language-specific logic.
- `intravox-source/lib/Command/MigrateToLanguageStructureCommand.php:15–16` — hardcoded system user `'intravox'` and folder `'IntraVox'`.

## Spec changes implied

The following adjustments are needed in `specs/dashboards/spec.md` — do NOT edit the spec now; these are tracked for the next spec revision:

1. **REQ-DASH-026, locale resolution in backfill**: The scenario says `languageCode` is set to `getUserValue($userId, 'core', 'lang')`. Add a clarification that the raw value must be truncated to the base language code (`explode('_', $raw)[0]`) before storing. `nl_NL` → `nl`, not `nl-NL`. Otherwise the backfill writes a code the three-tier lookup will never match exactly.

2. **REQ-DASH-027, three-tier matching**: The "language-part match" scenario currently shows `nl-BE → nl` using a hyphen separator. The actual Nextcloud storage format is `nl_NL` (underscore). The scenario should note that both underscore and hyphen variants must be handled (Nextcloud uses underscore; HTTP Accept-Language uses hyphen; the resolution must normalise both to a 2-char base before matching stored codes).

3. **REQ-DASH-027, explicit `?lang=` strict mode**: The current spec stores `nl-BE`-style codes in translation rows (from the scenario in REQ-DASH-028). If language codes in the DB are always 2-character base codes (as validated here), the `?lang=de-DE` query parameter would need to be normalized before lookup. The spec should state whether `?lang=` accepts full BCP-47 and normalises, or only accepts stored codes exactly.

4. **REQ-DASH-026, `languageCode` format note**: Add a note to the translation schema scenario specifying that `languageCode` values stored in the DB are always the 2-character ISO 639-1 base code (e.g. `nl`, `en`, `de`, `fr`), not full locale strings. Rationale: aligns with how the source app resolves and stores language, and avoids duplicate rows for `nl` vs `nl-NL` vs `nl_NL`.

5. **REQ-DASH-032, backfill locale note**: The scenario says "dashboard owner's Nextcloud locale at migration time". Clarify this means the truncated 2-char base code from `getUserValue($userId, 'core', 'lang')` — not the raw value.

## Open follow-ups

- **NavigationService default mismatch**: `NavigationService::getCurrentLanguage()` defaults to `'nl'` while all other services default to `'en'`. MyDash should pick one canonical fallback and document it. Source evidence suggests `'en'` is the majority convention, and the spec already uses `'en'` as the primary fallback — confirm this is the intended MyDash default.

- **Open vs closed language set**: The spec is silent on whether admins can define arbitrary language codes or only a fixed set. This should be decided before implementation to avoid schema drift (e.g. someone storing `zh-Hans` which is 7 characters; the current `VARCHAR(16)` accommodates it, but the resolution logic needs to be defined).

- **`?lang=` normalisation**: If the `?lang` query parameter accepts `nl-BE` and the DB stores only `nl`, the controller must normalise before the lookup — otherwise the "strict" 404 path in REQ-DASH-027 fires incorrectly for a code that does exist in a normalised form. Decide and spec this explicitly.

- **`PageSearchProvider` raw-locale bug**: The source app's search provider passes the raw `nl_NL` string to the page index without truncation. If MyDash adopts `IL10N`-based resolution anywhere, verify consistency with the `IConfig` path to avoid similar silent mismatches.
