# Database Migrations

This directory holds production-safe incremental SQL migrations.

## OpenClaw rollout

- [20260304_openclaw_rollout.sql](/C:/capstone2/bugcatcher/infra/database/migrations/20260304_openclaw_rollout.sql)
- [20260305_openclaw_control_plane.sql](/C:/capstone2/bugcatcher/infra/database/migrations/20260305_openclaw_control_plane.sql)
- [20260315_password_reset_requests.sql](/C:/xampp/htdocs/bugcatcher/infra/database/migrations/20260315_password_reset_requests.sql)
- [20260325_attachment_storage_providers.sql](/C:/xampp/htdocs/bugcatcher/infra/database/migrations/20260325_attachment_storage_providers.sql)
- [20260325_ai_runtime_split_and_integration_cleanup.sql](/C:/xampp/htdocs/bugcatcher/infra/database/migrations/20260325_ai_runtime_split_and_integration_cleanup.sql)

Apply this migration to an existing BugCatcher production database instead of re-importing the full [schema.sql](/C:/capstone2/bugcatcher/infra/database/schema.sql).

## Rollback notes

OpenClaw rollout rollback is manual and should start from a database backup taken immediately before the migration.

If you must reverse it without restoring a backup:

1. Drop the OpenClaw tables in dependency order:
   - `openclaw_reload_requests`
   - `openclaw_runtime_status`
   - `openclaw_control_plane_state`
   - `openclaw_request_attachments`
   - `openclaw_request_items`
   - `openclaw_requests`
   - `ai_runtime_config`
   - `ai_models`
   - `ai_provider_configs`
   - `openclaw_runtime_config`
   - `checklist_batch_attachments`
2. Change `users.role` back to `ENUM('admin','user')` only after confirming no `super_admin` rows remain.

Preferred rollback remains restoring the pre-migration backup.
