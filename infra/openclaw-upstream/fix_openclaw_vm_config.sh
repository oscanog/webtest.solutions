#!/usr/bin/env bash
set -euo pipefail

OPENCLAW_CONFIG_PATH="${OPENCLAW_CONFIG_PATH:-/opt/openclaw/config/openclaw.json}"
EXAMPLE_CONFIG_PATH="${EXAMPLE_CONFIG_PATH:-/home/m/bugcatcher-openclaw-deploy/integrations/openclaw-upstream/openclaw.json.example}"
BUGCATCHER_CONFIG_PATH="${BUGCATCHER_CONFIG_PATH:-/var/www/bugcatcher/config/local.php}"

SHARED_SECRET="$(php -r "echo (require '$BUGCATCHER_CONFIG_PATH')['OPENCLAW_INTERNAL_SHARED_SECRET'];")"
GATEWAY_TOKEN="${OPENCLAW_GATEWAY_TOKEN:-$(openssl rand -hex 24)}"

env \
  OPENCLAW_CONFIG_PATH="$OPENCLAW_CONFIG_PATH" \
  EXAMPLE_CONFIG_PATH="$EXAMPLE_CONFIG_PATH" \
  SHARED_SECRET="$SHARED_SECRET" \
  GATEWAY_TOKEN="$GATEWAY_TOKEN" \
  python3 - <<'PY'
import json
import os
import pathlib

config_path = pathlib.Path(os.environ["OPENCLAW_CONFIG_PATH"])
example_path = pathlib.Path(os.environ["EXAMPLE_CONFIG_PATH"])
shared_secret = os.environ["SHARED_SECRET"]
gateway_token = os.environ["GATEWAY_TOKEN"]

data = json.loads(example_path.read_text())
data["plugins"]["entries"]["bugcatcher-openclaw"]["config"]["bugcatcherSharedSecret"] = shared_secret
data["gateway"]["auth"]["token"] = gateway_token

config_path.write_text(json.dumps(data, indent=2) + "\n")
PY

chown openclaw:openclaw "$OPENCLAW_CONFIG_PATH"
chmod 0640 "$OPENCLAW_CONFIG_PATH"

echo "Updated $OPENCLAW_CONFIG_PATH"
echo "Gateway token written to config."
