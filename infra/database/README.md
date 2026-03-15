# Database Bootstrap

Production import order:

1. `infra/database/schema.sql`
2. `infra/database/seed_reference_data.sql`
3. `infra/database/seed_admin.sql`

Optional demo data:

1. Import the three files above.
2. Import `infra/database/demo.sql`.

`infra/database/seed_admin.sql` is intentionally a template. Replace the username, email, and password hash before you import it.

Legacy SQL artifacts that are not part of the production bootstrap live under `infra/database/legacy/`.

Local development one-shot import:

1. Import `local_dev_full.sql` from the repository root.
2. Log in with `superadmin@local.dev` and password `DevPass123!`.

New checklist/project foundations are included in `schema.sql`:

- `projects`
- `checklist_batches`
- `checklist_items`
- `checklist_attachments`

New related runtime config keys:

- `CHECKLIST_UPLOADS_DIR`
- `CHECKLIST_UPLOADS_URL`
- `CHECKLIST_BOT_SHARED_SECRET`
- `OPENCLAW_INTERNAL_SHARED_SECRET`
- `OPENCLAW_ENCRYPTION_KEY`
- `OPENCLAW_TEMP_UPLOAD_DIR`

Password reset mail config keys:

- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_ENCRYPTION`
- `MAIL_FROM_EMAIL`
- `MAIL_FROM_NAME`
- `PASSWORD_RESET_OTP_TTL_SECONDS`
- `PASSWORD_RESET_RESEND_COOLDOWN_SECONDS`
- `PASSWORD_RESET_MAX_VERIFY_ATTEMPTS`
- `PASSWORD_RESET_MAX_RESENDS`

Generate a bcrypt hash with:

```bash
php -r "echo password_hash('ChangeThisPasswordNow!', PASSWORD_DEFAULT), PHP_EOL;"
```
