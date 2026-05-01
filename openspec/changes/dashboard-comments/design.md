# Design — Dashboard Comments

## Context

This capability adds threaded comments to dashboards. The spec (`dashboard-comments`) pins the
NC `ICommentsManager` delegation model, `mydash_dashboard` object type, one-level-deep threading,
and admin + per-dashboard toggle. Sibling spec `dashboard-cascade-events` owns the
`DashboardDeletedEvent → ICommentsManager::deleteCommentsAtObject` call — not duplicated here.

Source confirms the approach: `intravox-source/lib/Controller/CommentController.php` and
`intravox-source/lib/Listener/CommentsEntityListener.php` both use `\OCP\Comments\ICommentsManager`
directly with no custom comment table. We match this. This design documents object-type binding,
depth enforcement, NC hooks, and toggle precedence.

## Goals / Non-Goals

**Goals:**
- Confirm storage delegation to NC `oc_comments` table (no MyDash comment table)
- Document object-type string and NC entity registration
- Specify one-level-deep enforcement mechanism
- Define toggle precedence (global vs. per-dashboard)
- Document cascade delete hook ownership

**Non-Goals:**
- Comment search / full-text indexing (NC activity feed handles cross-app)
- Email notification content (NC notification framework handles delivery)
- Moderation / hiding individual comments (future scope)

## Decisions

### D1: Storage delegation to NC ICommentsManager
**Decision**: All comment persistence via `\OCP\Comments\ICommentsManager`; no `oc_mydash_comments` table.
**Source evidence**: `intravox-source/lib/Controller/CommentController.php:~30-80` — all reads/writes
through injected `ICommentsManager`; no custom DB mapper.
**Alternatives considered**: Own table — rejected; loses NC activity feed, @-mention notifications,
and unread-count badge at zero cost.
**Rationale**: NC core delegation gives activity feed and notifications for free.

### D2: Object-type binding
**Decision**: Object type string is `mydash_dashboard`; object ID is the dashboard UUID.
**Source evidence**: `intravox-source/lib/Listener/CommentsEntityListener.php:~15` — uses
`'page'` as object type; we use `mydash_dashboard` to avoid collision with other NC apps.
**Alternatives considered**:
- `files` object type sharing — rejected; semantically wrong, would pollute file comment queries
**Rationale**: Unique object type ensures clean separation. Register via `ICommentsManagerFactory`
in `Application::register()` so NC's comment UI components know how to resolve the entity.

### D3: NC entity registration
**Decision**: Implement `ICommentsEntityInterface` in `DashboardCommentsEntity` and register
via `ICommentsManagerFactory::registerDisplayNameResolver()` in `Application.php`.
**Alternatives considered**:
- Skip entity registration — rejected; NC comment UI shows raw UUIDs instead of dashboard titles
**Rationale**: Entity registration resolves dashboard UUIDs to human-readable titles in the NC
activity feed and notification emails.

### D4: One-level-deep threading enforcement
**Decision**: `CommentController::create()` validates: if `parentId` is set, fetch the parent
comment and reject (HTTP 422 `thread_depth_exceeded`) if `parent.parentId IS NOT NULL`.
**Source evidence**: `intravox-source/lib/Controller/CommentController.php:~95` — depth check
is explicit, not delegated to `ICommentsManager` (which allows arbitrary depth).
**Alternatives considered**:
- Allow arbitrary depth and prune at read — rejected; inconsistent write state is confusing
**Rationale**: NC `ICommentsManager` does not enforce depth natively. Write-time enforcement keeps
the data model consistent and matches the spec contract.

### D5: Toggle precedence
**Decision**: Global `mydash.comments_enabled` takes absolute precedence; per-dashboard
`commentsEnabled` only applies when global is on. Globally disabled → HTTP 403 on all endpoints.
**Alternatives considered**: Per-dashboard override of global — rejected; admin needs kill-switch.
**Rationale**: Standard NC admin-override pattern; mirrors NC global sharing toggle.

### D6: Cascade delete ownership
**Decision**: `DashboardDeletedEvent` listener in `dashboard-cascade-events` calls
`ICommentsManager::deleteCommentsAtObject('mydash_dashboard', $uuid)`. No separate listener here.
**Alternatives considered**: Own listener — rejected; `dashboard-cascade-events` owns all cascades.
**Rationale**: Single listener per event avoids double-delete races.

## Risks / Trade-offs

- **ICommentsManager API stability** → OCP interface is stable across NC 25+; pin to `\OCP\Comments` namespace not `\OCA\Comments`
- **Comment count at scale** → `ICommentsManager::count()` issues a COUNT query per dashboard in list view; consider batching or caching if dashboards list is large

## Open follow-ups

- Add `?unread=true` filter to comment list once NC unread-comment tracking API is confirmed available
- Evaluate comment reactions (emoji on comments) — out of scope now, but `dashboard-reactions` pattern could extend here
- Document whether NC's existing comment search app (`comments` app) indexes `mydash_dashboard` objects automatically
