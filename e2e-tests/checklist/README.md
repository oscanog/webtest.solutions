# Checklist API E2E

Playwright API E2E suite for WebTest checklist v1 endpoints.

## Folder Rules

All checklist E2E files are intentionally isolated under `e2e-tests/checklist`.

## Environment Profiles

Choose profile with `E2E_ENV`:

- `local` loads `.env.local`
- `production` loads `.env.production`

`E2E_BASE_URL` and credentials live in each profile file.

## Setup

1. Install dependencies:

```powershell
npm install
```

2. Fill profile values:

- `.env.local`
- `.env.production`

Required keys:

- `E2E_BASE_URL`
- `E2E_EMAIL`
- `E2E_PASSWORD`
- `E2E_ORG_ID`
- `E2E_PROJECT_ID`

Optional:

- `E2E_ASSIGNED_QA_LEAD_ID`
- `E2E_ASSIGNED_USER_ID`

## Run

```powershell
npm run test:local
npm run test:prod
```

Headed mode:

```powershell
npm run test:local:headed
npm run test:prod:headed
```
