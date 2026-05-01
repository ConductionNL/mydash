#!/usr/bin/env bash
#
# update.sh  (.claude/skills/skill-creator/update.sh)
# ---------------------------------------------------
# Pull the latest skill-creator from anthropics/skills (upstream) and re-apply
# our local modifications.
#
# This is "Option B-style" (subtree-equivalent) but works around the fact that
# upstream keeps skill-creator as a SUBDIRECTORY of the repo — pure git subtree
# can't track an upstream subdirectory cleanly without commit gymnastics.
#
# How it works:
#   1. Sparse-clone anthropics/skills to a tempdir
#   2. Save current local modifications (anything in local-mods.patch, plus
#      preserved files like .upstream-version and the patch file itself)
#   3. rsync the upstream skill-creator/ over our local copy
#   4. Re-apply local-mods.patch
#   5. Update .upstream-version with the new commit hash
#
# Files preserved across updates (never overwritten):
#   - local-mods.patch        (the diff representing our local customizations)
#   - .upstream-version       (commit hash of last applied upstream)
#
# To add a new local modification:
#   1. Edit the file directly (e.g. SKILL.md)
#   2. Regenerate the patch:
#      diff -u <upstream-snapshot>/SKILL.md ./SKILL.md > local-mods.patch
#      (or just append a new hunk by hand — `patch` accepts unified diff format)
#
# Usage:
#   bash .claude/skills/skill-creator/update.sh                 # update in-place
#   bash .claude/skills/skill-creator/update.sh --dry-run       # just report what would change

set -euo pipefail

SKILL_DIR="$(cd "$(dirname "$0")" && pwd)"
UPSTREAM_REPO="https://github.com/anthropics/skills.git"
UPSTREAM_SUBPATH="skills/skill-creator"
DRY_RUN=0

[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

if [[ ! -d "$SKILL_DIR" ]]; then
  echo "ERROR: $SKILL_DIR does not exist. Run a fresh install first." >&2
  exit 1
fi

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

echo "==> Cloning $UPSTREAM_REPO (sparse: $UPSTREAM_SUBPATH)"
git clone --depth 1 --filter=blob:none --sparse "$UPSTREAM_REPO" "$TMPDIR/skills" >/dev/null 2>&1
( cd "$TMPDIR/skills" && git sparse-checkout set "$UPSTREAM_SUBPATH" >/dev/null )

NEW_COMMIT=$(cd "$TMPDIR/skills" && git rev-parse HEAD)
OLD_COMMIT=$(cat "$SKILL_DIR/.upstream-version" 2>/dev/null || echo "unknown")

echo "==> Old upstream: $OLD_COMMIT"
echo "==> New upstream: $NEW_COMMIT"

if [[ "$OLD_COMMIT" == "$NEW_COMMIT" ]]; then
  echo "==> Already up to date. Nothing to do."
  exit 0
fi

if [[ $DRY_RUN -eq 1 ]]; then
  echo "==> Dry-run: would rsync these changes:"
  rsync -avn --delete \
    --exclude='local-mods.patch' \
    --exclude='.upstream-version' \
    "$TMPDIR/skills/$UPSTREAM_SUBPATH/" \
    "$SKILL_DIR/"
  exit 0
fi

# Backup before modifying (in case patch fails)
BACKUP="/tmp/hydra-skill-creator-backup-$(date +%s)"
cp -r "$SKILL_DIR" "$BACKUP"
echo "==> Backup written to $BACKUP"

echo "==> Syncing upstream files into $SKILL_DIR"
rsync -a --delete \
  --exclude='local-mods.patch' \
  --exclude='.upstream-version' \
  "$TMPDIR/skills/$UPSTREAM_SUBPATH/" \
  "$SKILL_DIR/"

if [[ -s "$SKILL_DIR/local-mods.patch" ]]; then
  echo "==> Re-applying local-mods.patch"
  if ( cd "$SKILL_DIR" && patch -p1 --dry-run < local-mods.patch >/dev/null 2>&1 ); then
    ( cd "$SKILL_DIR" && patch -p1 < local-mods.patch )
    echo "==> Patch applied cleanly"
  else
    echo "==> WARNING: local-mods.patch did NOT apply cleanly against the new upstream."
    echo "==> Backup is at: $BACKUP"
    echo "==> Inspect the rejected hunks (look for *.rej files), update local-mods.patch by hand,"
    echo "==> then re-run patch manually. Or restore the backup with:"
    echo "==>   rm -rf $SKILL_DIR && mv $BACKUP $SKILL_DIR"
    exit 2
  fi
else
  echo "==> No local modifications to re-apply"
fi

echo "$NEW_COMMIT" > "$SKILL_DIR/.upstream-version"
echo "==> Updated .upstream-version → $NEW_COMMIT"
echo "==> Done. You can remove the backup if everything looks right:"
echo "==>   rm -rf $BACKUP"
