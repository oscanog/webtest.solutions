#!/usr/bin/env python3
import argparse
import json
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path


PLUGIN_ID = "bugcatcher-openclaw"


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def fetch_runtime_snapshot(base_url: str, shared_secret: str, timeout_ms: int) -> dict:
    request = urllib.request.Request(
        f"{base_url.rstrip('/')}/api/openclaw/runtime_config.php",
        headers={
            "Authorization": f"Bearer {shared_secret}",
            "Accept": "application/json",
        },
        method="GET",
    )
    with urllib.request.urlopen(request, timeout=max(timeout_ms / 1000, 1)) as response:
        payload = response.read().decode("utf-8")
    return json.loads(payload)


def update_discord_runtime(config: dict, snapshot: dict) -> bool:
    runtime = snapshot.get("runtime") or {}
    desired_enabled = bool(runtime.get("is_enabled"))
    desired_token = str(runtime.get("discord_bot_token") or "").strip()

    channels = config.setdefault("channels", {})
    discord = channels.setdefault("discord", {})
    current_enabled = bool(discord.get("enabled"))
    current_token = str(discord.get("token") or "")

    next_enabled = desired_enabled and desired_token != ""
    changed = False

    if current_enabled != next_enabled:
        discord["enabled"] = next_enabled
        changed = True

    if current_token != desired_token:
        discord["token"] = desired_token
        changed = True

    return changed


def run_systemctl(args: list[str]) -> None:
    subprocess.run(["systemctl", *args], check=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="Sync OpenClaw runtime config from BugCatcher.")
    parser.add_argument("--config", required=True, help="Path to openclaw.json")
    parser.add_argument("--restart-service", default="", help="Systemd service to restart when config changes")
    parser.add_argument("--timeout-ms", type=int, default=30000, help="HTTP timeout in milliseconds")
    args = parser.parse_args()

    config_path = Path(args.config)
    if not config_path.is_file():
        print(f"Config file not found: {config_path}", file=sys.stderr)
        return 1

    config = load_json(config_path)
    plugin_cfg = (
        config.get("plugins", {})
        .get("entries", {})
        .get(PLUGIN_ID, {})
        .get("config", {})
    )

    base_url = str(plugin_cfg.get("bugcatcherBaseUrl") or "").strip()
    shared_secret = str(plugin_cfg.get("bugcatcherSharedSecret") or "").strip()
    if not base_url or not shared_secret:
        print("BugCatcher plugin config is missing bugcatcherBaseUrl or bugcatcherSharedSecret.", file=sys.stderr)
        return 1

    try:
        snapshot = fetch_runtime_snapshot(base_url, shared_secret, args.timeout_ms)
    except (urllib.error.URLError, urllib.error.HTTPError, json.JSONDecodeError) as exc:
        print(f"Failed to fetch runtime snapshot: {exc}", file=sys.stderr)
        return 1

    changed = update_discord_runtime(config, snapshot)
    if not changed:
        print("OpenClaw runtime sync: no changes.")
        return 0

    config_path.write_text(json.dumps(config, indent=2) + "\n", encoding="utf-8")
    print("OpenClaw runtime sync: updated Discord token/enabled state from BugCatcher.")

    if args.restart_service:
        run_systemctl(["restart", args.restart_service])
        print(f"OpenClaw runtime sync: restarted {args.restart_service}.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
