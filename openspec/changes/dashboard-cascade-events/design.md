# Design — Dashboard Cascade Events

## Context

The spec was drafted by analogy with an event-dispatch pattern observed in the IntraVox source
tree. Before committing to 10 listeners, a migration table, and per-listener failure recording,
this document establishes what IntraVox actually does at delete time and which parts of the spec
reflect genuine need versus over-engineering.

Source examined:
- `intravox-source/lib/Service/PageService.php` (the canonical delete path)
- `intravox-source/lib/Controller/ApiController.php` (`deletePage` action)
- `intravox-source/lib/Event/PageDeletedEvent.php`
- `intravox-source/lib/Listener/PageDeletedListener.php`
- `intravox-source/lib/Listener/UserDeletedListener.php`
- `intravox-source/lib/Listener/CommentsEntityListener.php`
- `intravox-source/lib/AppInfo/Application.php`
- `intravox-source/lib/Service/PageIndexService.php`
- `intravox-source/lib/Service/AnalyticsService.php`
- `intravox-source/lib/Service/PageLockService.php`
- All migration files under `intravox-source/lib/Migration/`

## Goals / Non-Goals

**Goals:** Make the spec match the complexity that IntraVox's actual design warrants — neither
under-specifying (leaving tables orphaned) nor over-engineering (failure tables nobody uses).

**Non-Goals:** Change the spec's core event-dispatch architecture, which IntraVox confirms is
correct.

---

## Decisions

### D1: Architecture — sync vs event-dispatch

**Decision:** Event-dispatch, but synchronous within the same PHP request. There is no deferred
job, background queue, or async mechanism involved. `dispatchTyped()` blocks until all listeners
return; the event fires **before** the filesystem folder is deleted (so listeners can still access
page metadata if needed) and **before** the HTTP response is returned.

**Source evidence:**
- `intravox-source/lib/Service/PageService.php:1693–1699` — `dispatchTyped(new PageDeletedEvent(…))`
  is called inside a try/catch inside `deletePage()`, before `$result['folder']->delete()` at
  line 1712. The catch only logs a warning — it does not abort the folder delete.
- `intravox-source/lib/Controller/ApiController.php:372` — `$this->pageService->deletePage($id)`
  is a void call; the controller simply returns `['success' => true]` with no cascade stats.
- There is no job queue, `IJobList`, `BackgroundJob`, or deferred-dispatch anywhere in the
  delete path.

**Implication for spec:** The synchronous design means that if a listener throws, IntraVox logs a
warning and continues — the folder delete still happens. This matches REQ-CSC-006 (failure
isolation) but also means the `oc_mydash_cascade_failures` retry table is speculative; IntraVox
does not have one.

---

### D2: Tables cleaned on page delete (definitive list)

IntraVox fires one event (`PageDeletedEvent`) and has exactly **one listener** for it
(`PageDeletedListener`). That listener cleans exactly **one data target**:

| Target | Mechanism | Listener |
|---|---|---|
| NC comments (objectType `intravox_page`, objectId = `uniqueId`) | `ICommentsManager::deleteCommentsAtObject()` | `PageDeletedListener` |

Additionally, `PageService::deletePage()` performs two direct (non-event) cleanup steps inline:

| Target | Mechanism | Location |
|---|---|---|
| `intravox_page_index` rows (metadata index) | `PageIndexService::removePage($uniqueId)` | `PageService::deletePage()` lines 1702–1709 |
| Physical page folder (`.json`, `_media/`, child subfolders) | `$folder->delete()` | `PageService::deletePage()` line 1712 |

**Tables confirmed absent from delete path:**
- `intravox_page_locks` — cleaned only on user delete, not on page delete. A lock row for a
  deleted page becomes orphaned until the user is deleted or the lock expires via heartbeat TTL.
- `intravox_page_stats` / `intravox_uv` (analytics) — not cleaned on page delete. Only cleaned
  on user delete (by user_hash, not page_unique_id). Page-level stats rows orphan on page delete.
- `intravox_feed_tokens` — not page-scoped; user-scoped only.
- NC `oc_comments` reactions — IntraVox stores reactions as comments (objectType `intravox_page`),
  so `deleteCommentsAtObject()` covers them. There is no separate reactions table.

**What this means for the spec:** The spec's 10-listener list includes several tables (reactions,
locks, versions, public shares, metadata values, translations, view analytics, tree children) that
do not exist as separate tables in IntraVox. MyDash has its own schema which may include more
tables, but the spec's table list must be validated against MyDash's actual migrations — not
inferred from IntraVox.

---

### D3: User-deletion cascade scope

**Decision:** IntraVox's `UserDeletedListener` does NOT cascade through page deletes. It does not
call `PageService::deletePage()` per page. Instead it deletes user-scoped DB rows directly and
removes IConfig preferences in bulk.

Actual cleanup performed by `UserDeletedListener` (lines 43–79):

| Target | Mechanism | Note |
|---|---|---|
| `intravox_analytics_views` rows WHERE `user_hash = sha256(userId)` | Direct DELETE query | Hard delete |
| `intravox_page_locks` rows WHERE `user_id = userId` | Direct DELETE query | Hard delete |
| All IConfig app preferences for the app | `IConfig::deleteAppFromAllUsers(APP_ID)` | Bulk wipe |

**Pages owned by the deleted user are NOT deleted.** IntraVox pages live in GroupFolder filesystem
paths and are not ownership-coupled to NC user accounts in the DB. The page files persist after
user deletion.

**Implication for spec:** REQ-CSC-004 scenario "Personal dashboards are deleted on user deletion"
differs from what IntraVox does. If MyDash dashboards are DB-row-owned by user (likely, given the
`ownerUserId` on `DashboardDeletedEvent`), then the spec's design is correct for MyDash even
though it diverges from IntraVox's filesystem approach. This is a MyDash-specific requirement,
not a transcription of IntraVox behavior.

---

### D4: Group-deletion cascade scope

**Decision:** No `GroupDeletedListener` exists in IntraVox. The file is absent entirely.

```
intravox-source/lib/Listener/
  CommentsEntityListener.php
  PageDeletedListener.php
  UserDeletedListener.php
  (no GroupDeletedListener)
```

`Application::register()` registers listeners only for `CommentsEntityEvent`, `PageDeletedEvent`,
and `UserDeletedEvent`. `GroupDeletedEvent` is not imported or registered.

**Implication for spec:** REQ-CSC-005 (group lifecycle cleanup) is a MyDash-only requirement with
no IntraVox precedent. It must be designed from scratch. The IConfig JSON-mutation scenarios
(removing group from `org_navigation_tree` and `group_order`) are MyDash-specific features.

---

### D5: Failure handling pattern

**Decision:** Log-and-continue at warning level. There is no failure recording table, no retry
mechanism, and no per-listener isolation wrapper.

IntraVox's pattern (from `PageDeletedListener:35–42`):
```php
try {
    $this->commentsManager->deleteCommentsAtObject('intravox_page', $uniqueId);
    $this->logger->info('...');
} catch (\Exception $e) {
    $this->logger->error('...');
}
```

The outer `PageService::deletePage()` wraps the entire dispatch in its own try/catch
(lines 1697–1699) that also only logs a warning on failure.

Two divergences from spec:
1. IntraVox logs at `error` level on listener failure (spec says `warning`). Spec's WARN-level
   requirement is a conscious design choice — it is not derived from IntraVox behavior.
2. There is no `oc_intravox_cascade_failures` table. The spec's `oc_mydash_cascade_failures`
   table adds complexity that IntraVox does not carry.

**Recommendation:** Reconsider whether `oc_mydash_cascade_failures` (REQ-CSC-007) is worth the
migration cost. IntraVox's log-and-continue approach relies on the orphaned-data cleanup job to
catch stragglers. If the orphan-cleanup job already handles residual rows, the failures table is
redundant. If it is kept, consider whether it should be part of this spec or the
`orphaned-data-cleanup` change.

---

### D6: Tree cascade mechanism

**Decision:** Implicit filesystem cascade. IntraVox stores child pages as subfolders inside the
parent page folder. When `$folder->delete()` is called on line 1712, NC's virtual filesystem
deletes the entire subtree recursively — all child pages vanish with the parent folder in one
operation.

There is no `TreeListener`, no recursive event dispatch, no DB foreign-key CASCADE, and no
cascade flag on the delete API. The child pages are deleted as filesystem artifacts, which means:

- `PageDeletedEvent` is NOT fired for child pages — their comments and locks are NOT cleaned up
  by listeners.
- `intravox_page_index` rows for child pages are NOT removed (only the direct parent's uniqueId
  is passed to `PageIndexService::removePage()`).

This is a known gap in IntraVox. The spec's `TreeListener` (REQ-CSC-003 / REQ-CSC-010) explicitly
addresses this gap and is **more correct than IntraVox**, not a copy of it.

**Implication for spec:** The cascade-flag guard (REQ-CSC-010) and `TreeListener` are justified
additions that fix a real IntraVox shortcoming. They should be retained as designed.

---

### D7: API response shape

**Decision:** IntraVox returns `['success' => true]` with no cascade stats.

```php
// ApiController.php line 372–373
$this->pageService->deletePage($id);
return new DataResponse(['success' => true]);
```

There is no `deletedAt`, no `cascadeStats`, and no per-table counts in the response. The spec's
`cascadeStats` response (REQ-CSC-009) is a new capability, not a port from IntraVox.

---

## Spec changes implied

The following adjustments are recommended when the spec is next edited (do NOT apply during this
design phase — this section is for the author's reference):

- **REQ-CSC-007 (failure recording table):** Reconsider whether `oc_mydash_cascade_failures` is
  needed given that IntraVox's simpler log-and-continue pattern works in practice. If retained,
  clarify that retry logic lives in `orphaned-data-cleanup`, not here.

- **REQ-CSC-003 (listener group, table list):** The 10-table list should be validated against
  MyDash's actual migration files before implementation. Reactions, locks, versions, public
  shares, metadata values, translations, and view analytics are all plausible MyDash tables but
  are not confirmed from IntraVox. Only NC comments are confirmed present.

- **REQ-CSC-004 (user lifecycle):** The scenario "Personal dashboards are deleted on user
  deletion" is a MyDash design choice (dashboard rows are DB-owned), not inherited from IntraVox.
  Mark it as MyDash-specific so implementers understand it requires dashboard enumeration logic,
  not a direct port.

- **REQ-CSC-005 (group lifecycle):** Entirely MyDash-specific — no IntraVox precedent. The
  `GroupDeletedListener` must be designed from scratch. The IConfig JSON-mutation scenarios
  (org_navigation_tree, group_order) are valid requirements but need MyDash schema confirmation.

- **REQ-CSC-006 (failure isolation):** The log level should be WARN not ERROR per the spec, which
  is a deliberate downgrade from IntraVox's error-level logging. Add a note that IntraVox uses
  ERROR — this is an intentional divergence.

- **REQ-CSC-009 (cascade stats):** The `cascadeStats` response is additive and new. Note that
  tree-child deletions should contribute to the aggregate counts (e.g., total
  `widgetPlacementsDeleted` across parent + all children), which requires the `TreeListener` to
  propagate counts back up — a coordination mechanism IntraVox does not need.

---

## Open follow-ups

1. **MyDash table inventory:** Confirm which of the 10 spec-listed tables actually exist in
   MyDash's migrations (`oc_mydash_widget_placements`, `oc_mydash_dashboard_reactions`, etc.)
   before writing listener code. At least one spec table (reactions) may be stored differently.

2. **cascadeStats aggregation across tree:** If `TreeListener` dispatches child events
   synchronously, child listener counts must be accumulated and returned to the parent's
   `DashboardService::delete()` call. Define how listeners return row counts — the current spec
   leaves the aggregation mechanism unspecified.

3. **Page-lock orphan gap (confirmed from IntraVox):** IntraVox does not clean `page_locks` on
   page delete — only on user delete. Verify that `oc_mydash_dashboard_locks` in MyDash IS
   cleaned by `LocksListener` on dashboard delete (the spec says yes; confirm the migration and
   table schema support page/dashboard-keyed lookup).

4. **Failure table vs log-only:** Decide before implementation whether `oc_mydash_cascade_failures`
   is required. If the `orphaned-data-cleanup` job can identify residual rows without a failures
   table, the migration can be dropped, saving schema complexity.

5. **GroupDeletedListener analytics data:** The spec (REQ-CSC-005) doesn't mention analytics rows
   associated with a deleted group. If MyDash tracks per-group or group-member analytics,
   `GroupDeletedListener` should also clean those rows (analogous to how `UserDeletedListener`
   cleans analytics by user_hash).
