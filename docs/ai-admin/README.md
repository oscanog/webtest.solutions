# AI Admin

Built-in BugCatcher AI runtime management now lives in the dedicated AI Admin surface instead of the old OpenClaw control plane.

## Entry Points

- Legacy PHP: `/super-admin/ai.php`
- Mobile web: `/app/ai-admin`
- API:
  - `GET|PUT|PATCH /api/v1/admin/ai/runtime`
  - `GET|POST|DELETE /api/v1/admin/ai/providers`
  - `GET|POST|DELETE /api/v1/admin/ai/models`

## What It Owns

- `ai_runtime_config` is the source of truth for built-in AI enablement, default provider/model, assistant name, and system prompt.
- `ai_provider_configs` and `ai_models` remain the shared provider/model registry.
- `/api/v1/ai-chat/*` reads from the AI Admin runtime config.

## Setup Order

1. Open `Super Admin > AI Admin`.
2. Create or update at least one enabled provider.
3. Create or update at least one enabled model for that provider.
4. Choose the default provider and model in the runtime section.
5. Confirm built-in AI chat loads without the `Super Admin > AI` configuration warning.

## Security Notes

- Provider API keys are encrypted before storage.
- `OPENCLAW_ENCRYPTION_KEY` is still required on the server because the existing provider-secret encryption helpers are reused here.
- Secrets should be entered through the admin UI or shared runtime config, never committed to the repo.

## Compatibility Notes

- `/super-admin/openclaw.php` now redirects to `/super-admin/ai.php`.
- `/api/v1/admin/openclaw/runtime`, `/providers`, and `/models` remain temporary aliases to the new AI Admin APIs.
- The old bridge-only OpenClaw link and runtime routes have been removed.
