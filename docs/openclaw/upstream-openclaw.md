# Upstream OpenClaw Integration

This document describes the upstream OpenClaw deployment path for BugCatcher.

## Runtime choice

Use upstream OpenClaw on the GCloud VM for:

- Discord connectivity
- model execution
- agent orchestration

Do not use `services/openclaw/` for this path.

## Repo artifacts

The local integration package lives at:

- `integrations/openclaw-upstream/bugcatcher-plugin/`

Supporting deployment artifacts live at:

- `integrations/openclaw-upstream/openclaw.json.example`
- `infra/openclaw-upstream/openclaw-gateway.service`
- `infra/openclaw-upstream/openclaw.env.example`

## VM install outline

1. Install Node 22+ and upstream OpenClaw on the VM.
2. Create `/opt/openclaw` and run the gateway as user `openclaw`.
3. Install the local plugin from `integrations/openclaw-upstream/bugcatcher-plugin/` with `openclaw plugins install --link ...`.
4. Configure OpenClaw with Discord plus the plugin config.
5. Keep the gateway bound to `127.0.0.1`.
6. Access the OpenClaw UI through SSH tunneling.

Current upstream config notes:

- set `gateway.mode` to `local`
- configure `gateway.auth.mode` and `gateway.auth.token`
- use top-level `tools.allow`, not `agents.defaults.tools`
- put `requireMention` under a Discord guild/channel rule, not under `channels.discord`

## BugCatcher contract

The plugin talks only to these BugCatcher endpoints:

- `POST /api/openclaw/link_confirm.php`
- `POST /api/openclaw/link_context.php`
- `POST /api/openclaw/checklist_duplicates.php`
- `POST /api/openclaw/checklist_batches.php`
- `GET /api/openclaw/health.php`
- `GET /api/openclaw/runtime_config.php`
- `POST /api/openclaw/runtime_reload.php`
- `POST /api/openclaw/runtime_status.php`

It also writes staged images into `OPENCLAW_TEMP_UPLOAD_DIR`, which should be shared between the OpenClaw service user and the BugCatcher web process.

## Control plane

BugCatcher `super-admin/openclaw.php` is the control plane for:

- runtime enablement
- Discord bot token
- provider credentials and base URLs
- model inventory/defaults
- enabled Discord channel bindings

Upstream OpenClaw should keep only bootstrap config locally:

- BugCatcher base URL
- BugCatcher shared secret
- temp upload directory
- polling intervals and file limits
- plugin load path

The upstream plugin now polls `runtime_config.php`, consumes queued reload requests, and reports heartbeat/runtime state back through `runtime_status.php`.
