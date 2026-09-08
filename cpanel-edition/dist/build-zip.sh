#!/usr/bin/env bash
# Legacy wrapper — prefer build-release.sh
exec "$(cd "$(dirname "$0")" && pwd)/build-release.sh" "$@"
