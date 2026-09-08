#!/usr/bin/env bash
set -euo pipefail
exec "$(cd "$(dirname "$0")/.." && pwd)/cpanel-edition/dist/build-release.sh" "$@"
