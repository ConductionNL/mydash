# Dashboard RSS Feeds Specification

## Problem

Users need to integrate their dashboards with external systems, monitoring tools, and RSS readers without requiring full Nextcloud browser authentication. Currently, dashboard access is restricted to Nextcloud-authenticated sessions, preventing integration with third-party monitoring and RSS readers that cannot handle Nextcloud login flows.

## Proposed Solution

Implement a per-user RSS feed token system that enables public access to a user's accessible dashboards via RSS 2.0 or Atom feeds. Users explicitly opt-in by requesting a feed token. Each token is cryptographically random, single-per-user, and revocable. The feed respects dashboard ACLs so only accessible dashboards appear in the feed. Administrators configure the maximum feed item count.

## Scope

This change covers:
- Feed token management API (`/api/feed/token`, `/api/feed/token/regenerate`, `/DELETE /api/feed/token`)
- Public feed rendering (`GET /feed/{token}.xml`)
- Dashboard ACL filtering (only token-owner accessible dashboards appear)
- Feed item cap (admin-configurable, default 50)
- Token storage (`oc_mydash_feed_tokens` table)
- Per-user opt-in behavior (feeds disabled by default)

## Success Criteria

- Users can request and receive feed tokens via `GET /api/feed/token`
- Users can regenerate tokens via `POST /api/feed/token/regenerate` (invalidates old token)
- Users can revoke tokens via `DELETE /api/feed/token` (soft-revoke, idempotent)
- Public clients can fetch feeds via `GET /feed/{token}.xml` without authentication
- Feeds include RSS/Atom items with dashboard title, link, description, pubDate, guid, and author
- Feeds respect dashboard ACLs (only accessible dashboards included)
- Feeds include at most N dashboards (admin-configurable, default 50)
- Feed tokens are cryptographically random and non-enumerable
- Token revocation prevents feed access (returns 404)
