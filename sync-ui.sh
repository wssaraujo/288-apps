#!/usr/bin/env bash
# Copy the shared UI into every app. Each app is built on its own through
# --context-dir, so each folder needs its own copy of the page.
set -euo pipefail
cd "$(dirname "$0")"
for dest in nodejs-app/public ruby-app/public php-app/public java-app/src/main/resources/static; do
  mkdir -p "$dest"
  cp shared/index.html "$dest/index.html"
  echo "-> $dest/index.html"
done
