#!/usr/bin/env bash
# Apply the LinkStack manifests and wait for the rollout.
set -euo pipefail
NS=${NS:-linkstack}
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

kubectl apply -f "$DIR/deploy/linkstack-cluster.yaml"
# Recreate + RWO volume: the first wait can sit while the old volume detaches.
kubectl -n "$NS" rollout status deploy/linkstack --timeout=300s || {
  echo "rollout still in progress; check: kubectl -n $NS get pods -o wide" >&2
  exit 1
}
kubectl -n "$NS" get pods,svc,ingress -o wide
