#!/usr/bin/env bash
# deploy.sh — pull latest from GitHub and publish the KofC AI Agent into the Apache webroot.
#
# Run ON the EC2 box (Amazon Linux 2023) as ssm-user, from anywhere:
#   ./deploy.sh            # build SPA + publish frontend AND backend   [default]
#   ./deploy.sh frontend   # rebuild the SPA and publish web/dist only
#   ./deploy.sh backend    # publish api/ (+admin,sql if present) only — no build
#
# It mirrors the remote exactly (git reset --hard), so any uncommitted edits made
# directly on the box are discarded. The frontend is BUILT on the box (node_modules
# already live in web/), so it does not matter whether dist/ is committed.
#
# Secrets are NOT touched: they live at /etc/kofc/config.local.php, outside the webroot.
set -euo pipefail

REPO="${KOFC_REPO:-$HOME/kofc}"           # server clone   (default /home/ssm-user/kofc)
WEBROOT="${KOFC_WEBROOT:-/var/www/kofc}"  # docroot parent (DocumentRoot = $WEBROOT/web/dist)
OWNER="apache:apache"
TARGET="${1:-all}"

[ -d "$REPO/.git" ] || { echo "!! no git repo at $REPO (set KOFC_REPO=...)"; exit 1; }
command -v rsync >/dev/null 2>&1 || { echo "!! rsync missing: sudo dnf install -y rsync"; exit 1; }

cd "$REPO"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
echo "==> syncing $REPO to origin/$BRANCH (discards local box edits)"
git fetch --prune origin
git reset --hard "origin/$BRANCH"

build_frontend() {
  echo "==> building SPA (web/)"
  if [ ! -d "$REPO/web/node_modules" ]; then
    ( cd "$REPO/web" && npm install )
  fi
  ( cd "$REPO/web" && npm run build )
  [ -f "$REPO/web/dist/index.html" ] || { echo "!! build produced no web/dist/index.html"; exit 1; }
}

publish_frontend() {
  echo "==> publish web/dist -> $WEBROOT/web/dist"
  sudo mkdir -p "$WEBROOT/web/dist"
  # --delete: the SPA build is self-contained, so prune stale hashed assets.
  sudo rsync -a --delete "$REPO/web/dist/" "$WEBROOT/web/dist/"
}

publish_backend() {
  echo "==> publish api/ -> $WEBROOT/api  (no --delete; config.local.php lives in /etc/kofc)"
  sudo mkdir -p "$WEBROOT/api"
  sudo rsync -a "$REPO/api/" "$WEBROOT/api/"
  if [ -d "$REPO/admin" ]; then
    echo "==> publish admin/ -> $WEBROOT/admin"
    sudo rsync -a "$REPO/admin/" "$WEBROOT/admin/"
  fi
  if [ -d "$REPO/sql" ]; then
    echo "==> publish sql/ -> $WEBROOT/sql"
    sudo rsync -a "$REPO/sql/" "$WEBROOT/sql/"
  fi
}

case "$TARGET" in
  all)      build_frontend; publish_frontend; publish_backend ;;
  frontend) build_frontend; publish_frontend ;;
  backend)  publish_backend ;;
  *) echo "usage: $0 [all|frontend|backend]"; exit 2 ;;
esac

echo "==> chown $OWNER $WEBROOT"
sudo chown -R "$OWNER" "$WEBROOT"
if [ -d "$WEBROOT/storage" ]; then
  sudo chmod -R 0775 "$WEBROOT/storage"
fi

echo "==> apache configtest + reload"
sudo apachectl configtest
sudo systemctl reload httpd
echo "==> done ($TARGET)."