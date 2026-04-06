# BugCatcher Deployment

This repo is prepared for release-based deployment on a Debian Google Compute Engine VM.

## Repo-side prerequisites

- Put your production config at `/var/www/bugcatcher/shared/config.php`
- Keep uploads in `/var/www/bugcatcher/shared/uploads/issues`
- Keep checklist uploads in `/var/www/bugcatcher/shared/uploads/checklists`
- Keep checklist ingest temp uploads in `/var/www/bugcatcher/shared/uploads/openclaw-tmp`
- Ensure the web server group can write to both checklist uploads and checklist ingest temp uploads
- Import the database in this order:
  1. `infra/database/schema.sql`
  2. `infra/database/seed_reference_data.sql`
  3. `infra/database/seed_admin.sql`

## First server bootstrap

1. Copy `infra/nginx/bugcatcher.conf` to `/etc/nginx/sites-available/bugcatcher`
2. Confirm the TLS paths and PHP-FPM socket version match the VM if they differ from the tracked production setup
3. Run `sudo bash infra/deploy/bootstrap_server.sh`
4. Configure MariaDB:
   - create database `bug_catcher`
   - create user `bugcatcher_app`
   - grant privileges on that database only
5. Copy `infra/config/shared-config.example.php` to `/var/www/bugcatcher/shared/config.php` and replace placeholders
   - keep `APP_BASE_URL` set to `https://webtest.solutions` so generated links and emails use the new canonical domain
   - set `CHECKLIST_BOT_SHARED_SECRET` before exposing the bot ingest endpoint
   - set `OPENCLAW_ENCRYPTION_KEY` before using AI Admin provider secrets
   - set `OPENCLAW_INTERNAL_SHARED_SECRET` and `OPENCLAW_TEMP_UPLOAD_DIR` only if you still use the internal checklist-ingest aliases
6. Import the SQL files listed above
7. Enable the nginx site and test configuration
8. Run `sudo bash infra/deploy/deploy_release.sh <git-tag-or-commit>`
9. Issue or expand TLS with `sudo certbot --nginx --cert-name webtest.solutions -d webtest.solutions -d www.webtest.solutions -d bugcatcher.online`

## Hostname policy

- `https://webtest.solutions` is the canonical backend host
- `https://bugcatcher.online` is legacy and should redirect to `https://webtest.solutions`
- `https://www.webtest.solutions` should redirect to `https://webtest.solutions`

## Post-deploy verification

Run these checks on the VM after deploying nginx and the backend release:

```bash
curl -skI https://127.0.0.1/ -H "Host: webtest.solutions" | head -n 5
curl -sk https://127.0.0.1/api/v1/health -H "Host: webtest.solutions"
curl -skI https://127.0.0.1/some/path?x=1 -H "Host: bugcatcher.online" | head -n 5
```

Expected result:

- `webtest.solutions` returns `HTTP/1.1 200 OK` or the expected app response
- `/api/v1/health` returns a JSON success payload with `status` set to `ok`
- `bugcatcher.online` returns a redirect to `https://webtest.solutions/some/path?x=1`

## Rollback

To roll back to the previous release:

```bash
sudo bash infra/deploy/rollback_release.sh
```

To roll back to a specific release directory name:

```bash
sudo bash infra/deploy/rollback_release.sh 20260302153000
```

## Backups

`infra/deploy/backup_nightly.sh` creates:

- a SQL dump in `/var/backups/bugcatcher`
- a tarball of shared uploads

Provide `DB_PASS` when you run it from cron or a systemd timer.
