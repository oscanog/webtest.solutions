# Backend GCloud Deploy Guide

This guide is for teammates who need to deploy new WebTest backend changes to the production Google Compute Engine VM.

As of March 24, 2026, the production VM used in this workspace is:

- project: `tarlac-backup01`
- zone: `asia-southeast1-c`
- instance: `instance-20260218-175107`

The backend deploy flow is release-based. Do not treat `/var/www/webtest/current` like a normal long-lived git checkout.

## Golden Rules

- Push your backend changes to GitHub first. The server deploy pulls from the remote repo, not from your local machine.
- Do not hot-edit production files unless it is an emergency.
- Do not run a normal `git pull` inside `/var/www/webtest/current` for the PHP app deploy. Use the release script instead.
- If a deploy includes SQL changes, take a backup first and apply the migration carefully.
- If frontend and backend must ship together, deploy the backend first, then deploy the mobileweb frontend.

## Relevant Production Paths

- app root: `/var/www/webtest`
- live symlink: `/var/www/webtest/current`
- release directories: `/var/www/webtest/releases`
- shared config: `/var/www/webtest/shared/config.php`
- shared uploads: `/var/www/webtest/shared/uploads`
- git mirror used by the release script: `/opt/webtest/repo.git`

## Runtime Config Before Deploy

If this release includes the Cloudinary migration, make sure `/var/www/webtest/shared/config.php` contains:

- `APP_BASE_URL` set to `https://webtest.solutions` so generated links and emails use the new canonical host
- `CLOUDINARY_CLOUD_NAME`
- `CLOUDINARY_API_KEY`
- `CLOUDINARY_API_SECRET`
- optional `CLOUDINARY_BASE_FOLDER` override if production should not use the default `webtest`
- optional temporary `UPLOADTHING_TOKEN` only if you still want best-effort cleanup for old UploadThing-hosted rows during the transition
- `OPENCLAW_ENCRYPTION_KEY` so AI provider secrets remain decryptable in the AI Admin surface

Do not commit those secrets to the repo.

## 1. Prepare the Change Locally

Before touching production:

1. Merge or push the branch/commit you want to deploy.
2. Make sure the target ref exists on GitHub.
3. If the deploy includes a migration, identify the exact SQL file under `infra/database/migrations/`.
4. If the deploy changes realtime notification service code, note that you may need a service restart after the main release.

Use a tag, commit SHA, or branch name that already exists on `origin`.

## 2. SSH Into the VM

From your local machine:

```powershell
gcloud compute ssh instance-20260218-175107 --project tarlac-backup01 --zone asia-southeast1-c
```

Optional quick checks after login:

```bash
pwd
readlink -f /var/www/webtest/current
ls -1 /var/www/webtest/releases | tail
```

## 3. Apply Database Migration If Needed

Only do this when the deploy actually includes a new SQL migration.

First, take a backup if the change is risky:

```bash
sudo -i
cd /var/www/webtest/current
DB_PASS='your-db-password' bash infra/deploy/backup_nightly.sh
```

Then apply the migration. Examples:

```bash
cd /var/www/webtest/current
sudo mysql web_test < infra/database/migrations/20260325_attachment_storage_providers.sql
sudo mysql web_test < infra/database/migrations/20260325_ai_runtime_split_and_integration_cleanup.sql
```

Notes:

- Use the exact migration file for the change you are shipping.
- Prefer migrations that are backward-compatible with the currently running code during the short deploy window.
- Do not re-import `schema.sql` on production for an incremental release.

## 4. Deploy the Backend Release

Run the release script from the current checkout:

```bash
cd /var/www/webtest/current
sudo bash infra/deploy/deploy_release.sh <git-ref>
```

Example:

```bash
cd /var/www/webtest/current
sudo bash infra/deploy/deploy_release.sh main
```

What this script does:

- fetches the latest refs from the mirror at `/opt/webtest/repo.git`
- creates a new timestamped directory under `/var/www/webtest/releases`
- extracts the requested git ref into that release directory
- re-links shared uploads
- lint-checks PHP files
- repoints `/var/www/webtest/current`
- reloads PHP-FPM and nginx

## 5. Verify the Deploy

Confirm the live symlink moved:

```bash
readlink -f /var/www/webtest/current
ls -1 /var/www/webtest/releases | tail
```

Check the canonical site, API health, and legacy redirect behavior through nginx locally on the VM:

```bash
curl -skI https://127.0.0.1/ -H "Host: webtest.solutions" | head -n 5
curl -sk https://127.0.0.1/api/v1/health -H "Host: webtest.solutions"
curl -skI https://127.0.0.1/some/path?x=1 -H "Host: webtest.online" | head -n 5
```

Expected result:

- the `webtest.solutions` request returns `HTTP/1.1 200 OK` or the expected app response
- the health endpoint returns a JSON success payload with `status` set to `ok`
- the `webtest.online` request returns a redirect to `https://webtest.solutions/some/path?x=1`

If the certificate still only covers the old hostname set, expand it before verification:

```bash
sudo certbot --nginx --cert-name webtest.solutions -d webtest.solutions -d www.webtest.solutions -d webtest.online
```

## 6. Migrate Existing UploadThing Rows If Needed

Run this after the backend release is live and Cloudinary credentials are already present in shared config:

```bash
cd /var/www/webtest/current
php scripts/migrate_uploadthing_to_cloudinary.php
php scripts/migrate_uploadthing_to_cloudinary.php --execute
```

Notes:

- The first command is a dry run and should be used before any live migration.
- The execute command downloads each UploadThing-backed file, re-uploads it to Cloudinary, and updates the attachment row in place.
- Add `--table=<table>` to scope the run or `--limit=<count>` to migrate in smaller batches.
- Leave `--delete-uploadthing-source` off for the initial migration so rollback stays simple while you verify production.

## 7. Attachment Verification

After the migration run:

- create an issue with image evidence and verify the evidence loads in the report detail view
- upload checklist evidence from the mobile item detail screen and verify it appears immediately
- open an AI chat thread with screenshots and confirm attachments still render
- delete one migrated attachment-bearing record and confirm the row disappears without breaking the page

## 8. Verify AI Admin If Needed

If this release includes the AI Admin split and legacy bridge cleanup:

- open `Super Admin > AI Admin` and confirm runtime, providers, and models load
- verify the default provider/model still match production expectations after the backfill migration
- open built-in AI chat and confirm the bootstrap request no longer points admins to OpenClaw

## 9. Restart Extra Services Only If Your Change Touched Them

### Realtime Notifications

If your deploy changed `services/realtime-notifications/`, redeploy that service too:

```bash
cd /var/www/webtest/current
sudo bash scripts/deploy-realtime-notifications.sh
```

Important:

- the realtime notifications unit currently points at `/var/www/webtest/services/realtime-notifications`
- the main PHP app uses the `/var/www/webtest/current` release symlink
- if production still has an older direct-checkout layout for this service, verify the unit paths before changing them

## Rollback

Roll back to the previous backend release:

```bash
cd /var/www/webtest/current
sudo bash infra/deploy/rollback_release.sh
```

Roll back to a specific release directory:

```bash
cd /var/www/webtest/current
sudo bash infra/deploy/rollback_release.sh 20260302153000
```

After rollback, re-run the same verification commands from the previous section.

If the release included attachment migration work:

- do not immediately delete UploadThing originals during the first production pass
- if you roll back the code after running the migration script, existing rows will still point at Cloudinary URLs unless you also restore the database backup

## Troubleshooting

### `deploy_release.sh` says the git mirror is missing

The VM bootstrap is incomplete. The mirror should exist at `/opt/webtest/repo.git`.

### The release deployed but the app still looks old

Check the live symlink:

```bash
readlink -f /var/www/webtest/current
```

Then verify nginx and PHP-FPM reloaded correctly.

### The deploy needs config changes

Production runtime config is loaded from:

```text
/var/www/webtest/shared/config.php
```

Do not store production secrets in the repo.
