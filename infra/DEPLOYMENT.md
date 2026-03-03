# BugCatcher Deployment

This repo is prepared for release-based deployment on a Debian Google Compute Engine VM.

## Repo-side prerequisites

- Put your production config at `/var/www/bugcatcher/shared/config.php`
- Keep uploads in `/var/www/bugcatcher/shared/uploads/issues`
- Keep checklist uploads in `/var/www/bugcatcher/shared/uploads/checklists`
- Import the database in this order:
  1. `infra/database/schema.sql`
  2. `infra/database/seed_reference_data.sql`
  3. `infra/database/seed_admin.sql`

## First server bootstrap

1. Copy `infra/nginx/bugcatcher.conf` to `/etc/nginx/sites-available/bugcatcher`
2. Update the `server_name`, TLS paths, and PHP-FPM socket version if needed
3. Run `sudo bash infra/deploy/bootstrap_server.sh`
4. Configure MariaDB:
   - create database `bug_catcher`
   - create user `bugcatcher_app`
   - grant privileges on that database only
5. Copy `infra/config/shared-config.example.php` to `/var/www/bugcatcher/shared/config.php` and replace placeholders
   - set `CHECKLIST_BOT_SHARED_SECRET` before exposing the bot ingest endpoint
6. Import the SQL files listed above
7. Enable the nginx site and test configuration
8. Run `sudo bash infra/deploy/deploy_release.sh <git-tag-or-commit>`
9. Issue TLS with `sudo certbot --nginx -d your-domain -d www.your-domain`

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
