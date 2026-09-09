#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CPANEL_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$CPANEL_ROOT/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$CPANEL_ROOT/VERSION" 2>/dev/null || echo '12.1.0')"
DIST="$CPANEL_ROOT/dist"
mkdir -p "$DIST"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/sameh-12.1-professional"
mkdir -p "$STAGE"
echo "==> Building SAMEH cPanel Edition $VERSION"

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
  --exclude='local-ai-worker/worker.pid' \
  --exclude='local-ai-worker/worker.log' \
  --exclude='local-ai-worker/config.json' \
  -cf - . | tar -C "$STAGE" -xf -

mkdir -p "$STAGE/storage/nonces" "$STAGE/storage/rate" "$STAGE/storage/mail-outbox"
touch "$STAGE/storage/mail-outbox/.gitkeep" "$STAGE/storage/nonces/.gitkeep" "$STAGE/storage/rate/.gitkeep"

# Connector
CONN_ZIP="$DIST/SAMEH-connector-final.zip"
rm -f "$CONN_ZIP" "$DIST/SAMEH-connector.zip"
( cd "$STAGE/connector-plugin" && zip -qr "$CONN_ZIP" sameh-connector )
mkdir -p "$STAGE/dist"
cp "$CONN_ZIP" "$STAGE/dist/SAMEH-connector-final.zip"
cp "$CONN_ZIP" "$STAGE/dist/SAMEH-connector.zip"

# Local AI Worker separate zip
WORKER_ZIP="$DIST/SAMEH-local-ai-worker-final.zip"
rm -f "$WORKER_ZIP"
( cd "$STAGE" && zip -qr "$WORKER_ZIP" local-ai-worker -x 'local-ai-worker/config.json' 'local-ai-worker/worker.pid' 'local-ai-worker/worker.log' )
cp "$WORKER_ZIP" "$STAGE/dist/SAMEH-local-ai-worker-final.zip"

# Main package
MAIN_ZIP="$DIST/SAMEH-12.1-professional-final.zip"
rm -f "$MAIN_ZIP" "$DIST/SAMEH-12.1-professional.zip"
( cd "$TMP" && zip -qr "$MAIN_ZIP" sameh-12.1-professional )

cp -f "$MAIN_ZIP" "$REPO_ROOT/SAMEH-12.1-professional-final.zip"
cp -f "$CONN_ZIP" "$REPO_ROOT/SAMEH-connector-final.zip"
cp -f "$WORKER_ZIP" "$REPO_ROOT/SAMEH-local-ai-worker-final.zip"
# also legacy-friendly names at root
cp -f "$MAIN_ZIP" "$REPO_ROOT/SAMEH-12.1-professional.zip"
cp -f "$CONN_ZIP" "$REPO_ROOT/SAMEH-connector.zip"

echo "Wrote $MAIN_ZIP ($(wc -c < "$MAIN_ZIP") bytes)"
echo "Wrote $CONN_ZIP ($(wc -c < "$CONN_ZIP") bytes)"
echo "Wrote $WORKER_ZIP ($(wc -c < "$WORKER_ZIP") bytes)"
echo "VERSION=$VERSION"
