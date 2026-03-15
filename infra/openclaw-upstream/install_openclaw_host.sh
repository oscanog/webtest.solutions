#!/usr/bin/env bash
set -euo pipefail

OPENCLAW_USER="${OPENCLAW_USER:-openclaw}"
OPENCLAW_GROUP="${OPENCLAW_GROUP:-openclaw}"
OPENCLAW_HOME="${OPENCLAW_HOME:-/opt/openclaw}"
OPENCLAW_CONFIG_DIR="${OPENCLAW_CONFIG_DIR:-$OPENCLAW_HOME/config}"
OPENCLAW_STATE_DIR="${OPENCLAW_STATE_DIR:-$OPENCLAW_HOME/state}"
OPENCLAW_WORKSPACE_DIR="${OPENCLAW_WORKSPACE_DIR:-$OPENCLAW_HOME/workspace}"
OPENCLAW_PLUGIN_SOURCE="${OPENCLAW_PLUGIN_SOURCE:-$OPENCLAW_HOME/bugcatcher-plugin}"
BUGCATCHER_REPO_ROOT="${BUGCATCHER_REPO_ROOT:-/var/www/bugcatcher}"
BUGCATCHER_TEMP_DIR="${BUGCATCHER_TEMP_DIR:-/var/www/bugcatcher/uploads/openclaw-tmp}"
BUGCATCHER_CHECKLIST_DIR="${BUGCATCHER_CHECKLIST_DIR:-/var/www/bugcatcher/uploads/checklists}"
ENV_FILE="${ENV_FILE:-/etc/openclaw/openclaw.env}"
SERVICE_FILE="${SERVICE_FILE:-/etc/systemd/system/openclaw-gateway.service}"
SYNC_SERVICE_FILE="${SYNC_SERVICE_FILE:-/etc/systemd/system/openclaw-runtime-sync.service}"
SYNC_TIMER_FILE="${SYNC_TIMER_FILE:-/etc/systemd/system/openclaw-runtime-sync.timer}"

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y ca-certificates curl gnupg xz-utils

if ! getent group "$OPENCLAW_GROUP" >/dev/null 2>&1; then
    groupadd --system "$OPENCLAW_GROUP"
fi

if ! id -u "$OPENCLAW_USER" >/dev/null 2>&1; then
    useradd \
        --system \
        --gid "$OPENCLAW_GROUP" \
        --home-dir "$OPENCLAW_HOME" \
        --create-home \
        --shell /usr/sbin/nologin \
        "$OPENCLAW_USER"
fi

install -d -m 0755 "$OPENCLAW_HOME" "$OPENCLAW_CONFIG_DIR" "$OPENCLAW_STATE_DIR" "$OPENCLAW_WORKSPACE_DIR"
chown -R "$OPENCLAW_USER:$OPENCLAW_GROUP" "$OPENCLAW_HOME"

if getent group www-data >/dev/null 2>&1; then
    usermod -a -G www-data "$OPENCLAW_USER"
fi

install -d -m 2775 "$BUGCATCHER_TEMP_DIR"
install -d -m 2775 "$BUGCATCHER_CHECKLIST_DIR"
if getent group www-data >/dev/null 2>&1; then
    chgrp www-data "$BUGCATCHER_TEMP_DIR"
    chgrp www-data "$BUGCATCHER_CHECKLIST_DIR"
fi

if ! command -v node >/dev/null 2>&1 || ! node -e "process.exit(Number(process.versions.node.split('.')[0]) >= 22 ? 0 : 1)"; then
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update
    apt-get install -y nodejs
fi

if [ ! -x "$OPENCLAW_HOME/bin/openclaw" ]; then
    npm install -g openclaw@latest
    install -d -m 0755 "$OPENCLAW_HOME/bin"
    ln -sf "$(command -v openclaw)" "$OPENCLAW_HOME/bin/openclaw"
fi

install -d -m 0755 /etc/openclaw
if [ ! -f "$ENV_FILE" ]; then
    install -m 0640 "$BUGCATCHER_REPO_ROOT/infra/openclaw-upstream/openclaw.env.example" "$ENV_FILE"
fi

install -m 0644 "$BUGCATCHER_REPO_ROOT/infra/openclaw-upstream/openclaw-gateway.service" "$SERVICE_FILE"
install -m 0644 "$BUGCATCHER_REPO_ROOT/infra/openclaw-upstream/openclaw-runtime-sync.service" "$SYNC_SERVICE_FILE"
install -m 0644 "$BUGCATCHER_REPO_ROOT/infra/openclaw-upstream/openclaw-runtime-sync.timer" "$SYNC_TIMER_FILE"
install -m 0755 "$BUGCATCHER_REPO_ROOT/infra/openclaw-upstream/sync_openclaw_runtime.py" "$OPENCLAW_HOME/bin/sync_openclaw_runtime.py"
chown "$OPENCLAW_USER:$OPENCLAW_GROUP" "$OPENCLAW_HOME/bin/sync_openclaw_runtime.py"

if [ ! -f "$OPENCLAW_CONFIG_DIR/openclaw.json" ]; then
    install -m 0640 "$BUGCATCHER_REPO_ROOT/integrations/openclaw-upstream/openclaw.json.example" "$OPENCLAW_CONFIG_DIR/openclaw.json"
    chown "$OPENCLAW_USER:$OPENCLAW_GROUP" "$OPENCLAW_CONFIG_DIR/openclaw.json"
fi

rm -rf "$OPENCLAW_PLUGIN_SOURCE"
cp -R "$BUGCATCHER_REPO_ROOT/integrations/openclaw-upstream/bugcatcher-plugin" "$OPENCLAW_PLUGIN_SOURCE"
chown -R "$OPENCLAW_USER:$OPENCLAW_GROUP" "$OPENCLAW_PLUGIN_SOURCE"

runuser -u "$OPENCLAW_USER" -- env \
    HOME="$OPENCLAW_HOME" \
    OPENCLAW_HOME="$OPENCLAW_HOME" \
    OPENCLAW_STATE_DIR="$OPENCLAW_STATE_DIR" \
    OPENCLAW_CONFIG_PATH="$OPENCLAW_CONFIG_DIR/openclaw.json" \
    "$OPENCLAW_HOME/bin/openclaw" plugins install --link "$OPENCLAW_PLUGIN_SOURCE"

systemctl daemon-reload
systemctl enable openclaw-gateway.service
systemctl enable openclaw-runtime-sync.timer

cat <<EOF
OpenClaw host install complete.

Next steps:
1. Edit $OPENCLAW_CONFIG_DIR/openclaw.json with the real Discord and BugCatcher plugin config.
2. Edit $ENV_FILE if you need to override OPENCLAW_HOME or OPENCLAW_CONFIG_PATH.
3. Start the service with: systemctl start openclaw-gateway.service
4. Check logs with: journalctl -u openclaw-gateway.service -n 200 --no-pager
EOF
