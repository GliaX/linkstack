#!/usr/bin/env bash
# Smoke test: app reachable, LDAP extension present, patches installed.
set -euo pipefail
NS=${NS:-linkstack}
HOST=${HOST:-link.glia.org}
IP=${IP:-$(kubectl -n "$NS" get svc linkstack -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null)}

fail=0
for path in / /login; do
  code=$(curl -sS --resolve "$HOST:443:${IP:-159.203.50.191}" -o /dev/null -w '%{http_code}' "https://$HOST$path" || echo 000)
  printf '%-26s %s\n' "$path" "$code"
  [ "$code" = "200" ] || fail=1
done

ldap=$(kubectl -n "$NS" exec deploy/linkstack -- php83 -m 2>/dev/null | grep -c '^ldap$' || true)
echo "php83-ldap               $ldap"
[ "$ldap" = "1" ] || fail=1

for f in app/LdapAuth.php app/Http/Controllers/TeamController.php app/Http/Middleware/TeamGuard.php; do
  if kubectl -n "$NS" exec deploy/linkstack -- test -f "/htdocs/$f"; then printf '%-26s present\n' "$f"; else printf '%-26s MISSING\n' "$f"; fail=1; fi
done

# team page must require authentication
code=$(curl -sS --resolve "$HOST:443:${IP:-159.203.50.191}" -o /dev/null -w '%{http_code}' "https://$HOST/studio/team" || echo 000)
echo "/studio/team (anon)       $code (302 expected)"
[ "$code" = "302" ] || fail=1

exit "$fail"
