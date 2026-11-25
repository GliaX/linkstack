#!/usr/bin/env bash
# Install/refresh the LDAP login + page team patches inside the running pod.
# Safe to re-run: patches/apply.php is idempotent.
set -euo pipefail
NS=${NS:-linkstack}
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

tar czf - -C "$DIR" patches \
  | kubectl -n "$NS" exec -i deploy/linkstack -- sh -c \
      'rm -rf /tmp/lnk-patch && mkdir -p /tmp/lnk-patch && tar xzf - -C /tmp/lnk-patch && php83 /tmp/lnk-patch/patches/apply.php'

kubectl -n "$NS" exec deploy/linkstack -- php83 /htdocs/artisan view:clear >/dev/null
echo "patches applied; verify with ./scripts/check.sh"
