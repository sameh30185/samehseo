#!/usr/bin/env bash
# Reproducible SAMEH 12.1 Professional (cPanel) release builder
# Outputs:
#   cpanel-edition/dist/SAMEH-12.1-professional.zip
#   cpanel-edition/dist/SAMEH-connector.zip
# Also copies both to repo root when invoked from a git checkout.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CPANEL_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$CPANEL_ROOT/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$CPANEL_ROOT/VERSION" 2>/dev/null || echo '12.0.0-rc1')"

DIST="$CPANEL_ROOT/dist"
mkdir -p "$DIST"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

STAGE="$TMP/sameh-12.1-professional"
mkdir -p "$STAGE"

echo "==> Building SAMEH cPanel Edition $VERSION from $CPANEL_ROOT"

# Copy source excluding secrets, logs, temp, git, prior zips
tar -C "$CPANEL_ROOT" \
  --exclude='.git' \
  --exclude='config.local.php' \
  --exclude='config.php' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  --exclude='storage/nonces/*' \
  --exclude='storage/rate/*' \
  --exclude='tests/last-run.json' \
  --exclude='*.log' \
  --exclude='.env' \
  -cf - . | tar -C "$STAGE" -xf -

# Ensure empty storage dirs exist in package
mkdir -p "$STAGE/storage/nonces" "$STAGE/storage/rate" "$STAGE/storage/mail-outbox"
touch "$STAGE/storage/mail-outbox/.gitkeep"
touch "$STAGE/storage/nonces/.gitkeep" "$STAGE/storage/rate/.gitkeep"

# Connector zip (WP plugin uploadable)
CONN_ZIP="$DIST/SAMEH-connector.zip"
rm -f "$CONN_ZIP"
( cd "$STAGE/connector-plugin" && zip -qr "$CONN_ZIP" sameh-connector )
# Also place connector zip inside package dist for cPanel users
mkdir -p "$STAGE/dist"
cp "$CONN_ZIP" "$STAGE/dist/SAMEH-connector.zip"
# Keep legacy name too
cp "$CONN_ZIP" "$STAGE/dist/sameh-connector.zip"

# Main package zip
MAIN_ZIP="$DIST/SAMEH-12.1-professional.zip"
rm -f "$MAIN_ZIP"
( cd "$TMP" && zip -qr "$MAIN_ZIP" sameh-12.1-professional )

# Copy to repo root (docs historically expect this)
cp -f "$MAIN_ZIP" "$REPO_ROOT/SAMEH-12.1-professional.zip"
cp -f "$CONN_ZIP" "$REPO_ROOT/SAMEH-connector.zip"

# Sync sibling tree if present (cpanel-edition is source of truth)
SIBLING="/workspace/sameh-12.1-professional"
if [[ -d "$SIBLING" ]]; then
  echo "==> Syncing source of truth → $SIBLING"
  # Prefer rsync; fall back to tar overwrite
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude='config.local.php' \
      --exclude='config.php' \
      --exclude='.git' \
      --exclude='storage/nonces/*' \
      --exclude='storage/rate/*' \
      "$CPANEL_ROOT/" "$SIBLING/"
  else
    tar -C "$CPANEL_ROOT" \
      --exclude='config.local.php' \
      --exclude='config.php' \
      --exclude='.git' \
      --exclude='storage/nonces/*' \
      --exclude='storage/rate/*' \
      -cf - . | tar -C "$SIBLING" --overwrite -xf -
  fi
fi

echo "Wrote $MAIN_ZIP ($(wc -c < "$MAIN_ZIP") bytes)"
echo "Wrote $CONN_ZIP ($(wc -c < "$CONN_ZIP") bytes)"
echo "Copied to $REPO_ROOT/"
echo "VERSION=$VERSION"
