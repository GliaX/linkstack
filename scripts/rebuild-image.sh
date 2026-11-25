#!/usr/bin/env bash
# Build and push the LDAP-enabled image for a given LinkStack version.
# usage: ./scripts/rebuild-image.sh v4.8.8
set -euo pipefail
VER=${1:?usage: rebuild-image.sh <linkstack-version, e.g. v4.8.8>}
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="ghcr.io/gliax/linkstack:${VER}-ldap"

docker build --build-arg LINKSTACK_VERSION="$VER" -t "$TAG" "$DIR/image"
docker push "$TAG"
echo "pushed $TAG — update the image field in deploy/linkstack-cluster.yaml"
