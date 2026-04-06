# Frontend Mobileweb GCloud Deploy Guide

This guide is for deploying the separate `bugcatcher-mobileweb` frontend to the production Google Compute Engine VM.

As of March 24, 2026, the production VM used in this workspace is:

- project: `tarlac-backup01`
- zone: `asia-southeast1-c`
- instance: `instance-20260218-175107`

The mobileweb frontend is deployed from a separate repository than this backend repo.

## Current Production Assumptions

- frontend repo: `https://github.com/oscanog/bugcatcher-mobileweb.git`
- frontend branch: `main`
- VM repo path: `/var/www/bugcatcher-mobileweb`
- canonical public hosts: `m.webtest.solutions`, `mobile.webtest.solutions`
- legacy redirect hosts: `m.bugcatcher.online`, `mobile.bugcatcher.online`

If any of those change later, update this document together with the server scripts.

## Golden Rules

- Deploy backend API changes first if the frontend depends on new endpoints or new response fields.
- Push the frontend changes to GitHub before deploying.
- Use the frontend repo's deploy script instead of manually copying `dist/` files.
- Keep nginx and service changes separate from a normal frontend release unless they are actually required.
- The mobileweb attachment flows still post files to the backend API. No Cloudinary secret or signed upload config is required in the frontend repo or nginx config.

## 1. SSH Into the VM

From your local machine:

```powershell
gcloud compute ssh instance-20260218-175107 --project tarlac-backup01 --zone asia-southeast1-c
```

## 2. Ensure the Frontend Repo Exists on the VM

First-time setup only:

```bash
if [ ! -d /var/www/bugcatcher-mobileweb/.git ]; then
  sudo mkdir -p /var/www
  sudo git clone --branch main https://github.com/oscanog/bugcatcher-mobileweb.git /var/www/bugcatcher-mobileweb
  sudo chown -R "$USER:$USER" /var/www/bugcatcher-mobileweb
fi
```

## 3. Pull the Latest Frontend Changes

Update the checkout to the latest `main` branch:

```bash
cd /var/www/bugcatcher-mobileweb
git fetch origin
git checkout main
git pull --ff-only origin main
```

If you need to deploy a specific commit instead of the latest `main`:

```bash
cd /var/www/bugcatcher-mobileweb
git fetch origin
git checkout <commit-or-tag>
```

## 4. Run the Frontend Deploy Script

From the frontend repo:

```bash
cd /var/www/bugcatcher-mobileweb
bash scripts/deploy-mobileweb.sh
```

This should be the standard path for frontend releases. The script is expected to build the app and update the deployed site on the VM.

## 5. Verify the Frontend Deploy

Check the canonical mobile hosts and the legacy redirects through nginx locally on the VM:

```bash
curl -skI https://127.0.0.1/ -H "Host: m.webtest.solutions" | head -n 5
curl -skI https://127.0.0.1/ -H "Host: mobile.webtest.solutions" | head -n 5
curl -sk https://127.0.0.1/api/v1/health -H "Host: m.webtest.solutions"
curl -skI https://127.0.0.1/app/dashboard?tab=1 -H "Host: m.bugcatcher.online" | head -n 5
curl -skI https://127.0.0.1/login?next=%2Fapp -H "Host: mobile.bugcatcher.online" | head -n 5
```

Expected result:

- the `m.webtest.solutions` and `mobile.webtest.solutions` root requests return `HTTP/1.1 200 OK`
- the health endpoint returns a JSON success payload with `status` set to `ok`
- `m.bugcatcher.online` redirects to `https://m.webtest.solutions/app/dashboard?tab=1`
- `mobile.bugcatcher.online` redirects to `https://mobile.webtest.solutions/login?next=%2Fapp`

Then open the live site in a browser and verify the changed screen manually:

```text
https://mobile.webtest.solutions/
```

## Suggested Release Order When Both Repos Changed

1. Deploy backend `bugcatcher` first.
2. Verify `https://webtest.solutions/api/v1/health`.
3. Deploy frontend `bugcatcher-mobileweb`.
4. Verify `https://mobile.webtest.solutions/`.

## Troubleshooting

### `npm` or `node` is missing on the VM

Install the required Node.js runtime before running the frontend deploy script.

### The deploy script fails after pulling new code

Read the script output carefully. Most failures are one of:

- missing frontend environment/config on the VM
- failed package install
- failed production build
- nginx path mismatch

### The site loads old assets

Hard refresh in the browser after a successful deploy. If it still serves old files, inspect the frontend deploy script and nginx/static root on the VM.
