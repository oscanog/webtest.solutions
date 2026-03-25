# BugCatcher API v1 E2E

Role-based API E2E suite for the modular `/api/v1/*` surface.

## What this covers
- Auth + session
- Organization lifecycle
- Projects lifecycle
- Issues role workflow (PM -> Senior Dev -> Junior Dev -> QA -> Senior QA -> QA Lead -> PM)
- Checklist alias parity (`/api/v1/checklist/*`)
- AI Admin runtime/providers/models API
- Remaining OpenClaw compatibility aliases and retained checklist ingest behavior
- Checklist ingest aliases (with bot token)

## Structure
- `src/config.ts`: environment profiles and role credentials.
- `tests/helpers/`: shared API client + auth helpers.
- `tests/*.spec.ts`: module-by-module scenarios.

## Usage
```bash
npm install
npm run test:local
npm run test:prod
```

## Environment
Set `E2E_ENV=local` or `E2E_ENV=production`.

Optional profile files:
- `.env.local`
- `.env.production`

Use `.env.local.example` as baseline.

## Notes
- Local defaults are aligned with `local_dev_full.sql` seeded users and roles.
- `openclaw-internal.spec.ts` uses `E2E_OPENCLAW_INTERNAL_TOKEN` only for positive checks against the deprecated internal aliases that now return `410 Gone`.
- Bot ingest alias checks still require `E2E_CHECKLIST_BOT_TOKEN`.
