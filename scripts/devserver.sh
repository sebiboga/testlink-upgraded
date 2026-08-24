#!/usr/bin/env bash
# Start the PHP built-in dev server with the configuration values TestLink's
# installer expects (see GitHub issue #484):
#   max_execution_time    = 120  (recommended >= 120s for large TC operations)
#   session.gc_maxlifetime= 2880 (48 min idle timeout; installer wants > 30 min)
#   memory_limit          = 64M  (recommended minimum)
# Usage: scripts/devserver.sh [port]   (default 8082, docroot = repo root)
set -euo pipefail

PORT="${1:-8082}"
TL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if command -v fuser >/dev/null 2>&1; then
  fuser -k "${PORT}"/tcp 2>/dev/null || true
fi

echo "TestLink dev server on http://localhost:${PORT} (docroot: ${TL_ROOT})"
exec php \
  -d display_errors=1 \
  -d error_reporting=E_ALL \
  -d max_execution_time=120 \
  -d session.gc_maxlifetime=2880 \
  -d memory_limit=64M \
  -S "0.0.0.0:${PORT}" -t "${TL_ROOT}"
