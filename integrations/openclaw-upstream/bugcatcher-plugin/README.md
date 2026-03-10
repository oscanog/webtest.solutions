# BugCatcher OpenClaw Plugin

This plugin connects upstream OpenClaw to BugCatcher's internal OpenClaw APIs so a Discord conversation can end in a real BugCatcher checklist batch.

## What it provides

- one agent tool: `bugcatcher_create_bulk_checklist`
- one skill: `bugcatcher_bulk_checklist`
- attachment staging into `OPENCLAW_TEMP_UPLOAD_DIR`
- HTTPS calls to the existing BugCatcher endpoints under `/api/openclaw/`

## Tool actions

- `health`
- `confirm_link`
- `load_context`
- `stage_attachments`
- `cleanup_attachments`
- `check_duplicates`
- `submit_batch`

## Local plugin install

Install this plugin on the machine running the OpenClaw gateway:

```bash
openclaw plugins install --link /path/to/bugcatcher/integrations/openclaw-upstream/bugcatcher-plugin
```

Restart the gateway after install.

## Required OpenClaw config

Configure the plugin under `plugins.entries.bugcatcher-openclaw.config`:

```json
{
  "plugins": {
    "entries": {
      "bugcatcher-openclaw": {
        "enabled": true,
        "config": {
          "bugcatcherBaseUrl": "https://bugcatcher.online",
          "bugcatcherSharedSecret": "replace-me",
          "tempUploadDir": "/var/www/bugcatcher/uploads/openclaw-tmp",
          "requestTimeoutMs": 30000,
          "maxAttachments": 10,
          "maxAttachmentBytes": 20971520,
          "cleanupMaxAgeMinutes": 240
        }
      }
    }
  }
}
```

## Required BugCatcher config

BugCatcher must already expose these endpoints:

- `POST /api/openclaw/link_confirm.php`
- `POST /api/openclaw/link_context.php`
- `POST /api/openclaw/checklist_duplicates.php`
- `POST /api/openclaw/checklist_batches.php`
- `GET /api/openclaw/health.php`

BugCatcher must also have:

- `OPENCLAW_INTERNAL_SHARED_SECRET`
- `OPENCLAW_TEMP_UPLOAD_DIR`
- the OpenClaw tables migrated in the live DB

## Attachment staging

`stage_attachments` downloads each attachment URL and writes the content to the configured `tempUploadDir` under a random token filename. `submit_batch` expects those tokens to be forwarded as:

```json
[
  {
    "temp_file_token": "random-token",
    "original_name": "screenshot.png"
  }
]
```

Preferred submit call shape:

```json
{
  "action": "submit_batch",
  "payload": {
    "org_id": 12,
    "project_id": 9,
    "requested_by_user_id": 22,
    "discord_user_id": "1010931769794642073",
    "batch": {
      "title": "Checkout QA",
      "module_name": "Checkout"
    },
    "items": [],
    "batch_attachments": [
      {
        "temp_file_token": "random-token",
        "original_name": "screenshot.png"
      }
    ]
  }
}
```

For compatibility, the tool also accepts the same submit fields at the top level (legacy mode), but `payload` should be used going forward.
