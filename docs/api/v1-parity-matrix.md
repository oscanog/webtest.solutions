# API v1 Parity Matrix

This matrix maps every currently discovered backend function to its `/api/v1/*` equivalent.

## Conventions
- Canonical paths use kebab-case where available.
- Compatibility aliases are intentionally retained (snake_case and old shapes) to avoid breaking web and bot callers.
- Auth modes:
  - `Bearer/Session` = accepts v1 Bearer token or existing web session cookie.
  - `Internal Bearer` = OpenClaw internal shared secret (`Authorization: Bearer ...`).
  - `Bot Token` = checklist bot ingest header (`X-BUGCRAWLER-TOKEN`).
- Status:
  - `live` = implemented and routed in `api/v1/lib/routes.php`.
  - `alias` = v1 endpoint delegates to legacy handler.

## Auth + Session
| Legacy source | Legacy flow | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| `rainier/login.php` | form login | `/api/v1/auth/login` | `POST` | none | live |
| `rainier/signup.php` | form signup | `/api/v1/auth/signup` | `POST` | none | live |
| `rainier/logout.php` | page logout | `/api/v1/auth/logout` | `POST` | optional | live |
| `rainier/login.php` + session profile | whoami/session check | `/api/v1/auth/me` | `GET` | Bearer/Session | live |
| new token flow | refresh access | `/api/v1/auth/refresh` | `POST` | refresh token | live |
| `rainier/forgot_password.php?action=request_otp` | request OTP | `/api/v1/auth/forgot/request-otp` | `POST` | none | live |
| `rainier/forgot_password.php?action=resend_otp` | resend OTP | `/api/v1/auth/forgot/resend-otp` | `POST` | none | live |
| `rainier/forgot_password.php?action=verify_otp` | verify OTP | `/api/v1/auth/forgot/verify-otp` | `POST` | none | live |
| `rainier/forgot_password.php?action=reset_password` | reset password | `/api/v1/auth/forgot/reset-password` | `POST` | none | live |
| `zen/organization.php?org_id=...` + session | switch active org | `/api/v1/session/active-org` | `PUT` | Bearer/Session | live |

## Organization
| Legacy source | Legacy flow | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| `zen/organization.php` | list memberships/joinables | `/api/v1/orgs` | `GET` | Bearer/Session | live |
| `zen/organization.php?action=create` | create org | `/api/v1/orgs` | `POST` | Bearer/Session | live |
| `zen/organization.php?action=join` | join org | `/api/v1/orgs/{id}/join` | `POST` | Bearer/Session | live |
| `zen/organization.php?action=leave` | leave org | `/api/v1/orgs/{id}/leave` | `POST` | Bearer/Session | live |
| `zen/organization.php?action=transfer_owner` | transfer owner | `/api/v1/orgs/{id}/transfer-owner` | `POST` | Bearer/Session | live |
| `zen/organization.php?action=delete_org` | delete org | `/api/v1/orgs/{id}` | `DELETE` | Bearer/Session | live |
| `zen/organization.php?action=change_role` | change member role | `/api/v1/orgs/{id}/members/{userId}/role` | `PATCH` | Bearer/Session | live |
| `zen/organization.php?action=kick_member` | kick member | `/api/v1/orgs/{id}/members/{userId}` | `DELETE` | Bearer/Session | live |

## Issues
| Legacy source | Legacy flow | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| `zen/dashboard.php` | list/filter issues | `/api/v1/issues` | `GET` | Bearer/Session | live |
| `zen/create_issue.php` | create issue | `/api/v1/issues` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=delete_issue` | delete issue | `/api/v1/issues/{id}` | `DELETE` | Bearer/Session | live |
| `zen/dashboard.php?action=assign_dev` | PM assign senior dev | `/api/v1/issues/{id}/assign-dev` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=assign_junior` | senior assign junior | `/api/v1/issues/{id}/assign-junior` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=junior_done` | junior marks done | `/api/v1/issues/{id}/junior-done` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=assign_qa` | senior assign QA | `/api/v1/issues/{id}/assign-qa` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=report_senior_qa` | QA report to senior QA | `/api/v1/issues/{id}/report-senior-qa` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=report_qa_lead` | senior QA report to QA lead | `/api/v1/issues/{id}/report-qa-lead` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=qa_lead_approve` | QA lead approve | `/api/v1/issues/{id}/qa-lead-approve` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=qa_lead_reject` | QA lead reject | `/api/v1/issues/{id}/qa-lead-reject` | `POST` | Bearer/Session | live |
| `zen/dashboard.php?action=pm_close` | PM close approved issue | `/api/v1/issues/{id}/pm-close` | `POST` | Bearer/Session | live |

Issue read behavior:
- `GET /api/v1/issues` and `GET /api/v1/issues/{id}` are organization-wide for active organization members.
- Workflow mutations remain role/state/assignment-gated even when the viewer can read the issue.

## Projects
| Legacy source | Legacy flow | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| `melvin/project_list.php` | list projects | `/api/v1/projects` | `GET` | Bearer/Session | live |
| `melvin/project_form.php` | create project | `/api/v1/projects` | `POST` | Bearer/Session | live |
| `melvin/project_detail.php` | project detail + batches | `/api/v1/projects/{id}` | `GET` | Bearer/Session | live |
| `melvin/project_form.php` | update project | `/api/v1/projects/{id}` | `PATCH` | Bearer/Session | live |
| `melvin/project_list.php?action=archive` | archive project | `/api/v1/projects/{id}/archive` | `POST` | Bearer/Session | live |
| `melvin/project_list.php?action=activate` | activate project | `/api/v1/projects/{id}/activate` | `POST` | Bearer/Session | live |

## Checklist (JSON + page parity)

### Canonical v1 checklist aliases to existing checklist JSON handlers
| Legacy endpoint | v1 route | Method(s) | Auth | Status |
|---|---|---|---|---|
| `/api/checklist/v1/batches.php` | `/api/v1/checklist/batches` | `ANY` (legacy enforces `GET/POST`) | Bearer/Session | alias |
| `/api/checklist/v1/batch.php?id=...` | `/api/v1/checklist/batch` | `ANY` (legacy enforces `GET/PATCH/DELETE`) | Bearer/Session | alias |
| `/api/checklist/v1/batch.php?id=...` | `/api/v1/checklist/batches/{id}` | `ANY` | Bearer/Session | alias |
| `/api/checklist/v1/items.php` | `/api/v1/checklist/items` | `ANY` (legacy enforces `POST`) | Bearer/Session | alias |
| `/api/checklist/v1/item.php?id=...` | `/api/v1/checklist/item` | `ANY` (legacy enforces `GET/PATCH/DELETE`) | Bearer/Session | alias |
| `/api/checklist/v1/item.php?id=...` | `/api/v1/checklist/items/{id}` | `ANY` | Bearer/Session | alias |
| `/api/checklist/v1/item_status.php` | `/api/v1/checklist/item_status` | `ANY` (legacy enforces `POST`) | Bearer/Session | alias |
| `/api/checklist/v1/item_attachments.php` | `/api/v1/checklist/item_attachments` | `ANY` (legacy enforces `POST`) | Bearer/Session | alias |
| `/api/checklist/v1/item_attachment.php?id=...` | `/api/v1/checklist/item_attachment` | `ANY` (legacy enforces `DELETE`) | Bearer/Session | alias |
| `/api/checklist/v1/item_attachment.php?id=...` | `/api/v1/checklist/item-attachments/{id}` | `ANY` | Bearer/Session | alias |
| `/api/checklist/v1/batch_attachments.php` | `/api/v1/checklist/batch_attachments` | `ANY` (legacy enforces `POST`) | Bearer/Session | alias |
| `/api/checklist/v1/batch_attachment.php?id=...` | `/api/v1/checklist/batch_attachment` | `ANY` (legacy enforces `DELETE`) | Bearer/Session | alias |
| `/api/checklist/v1/batch_attachment.php?id=...` | `/api/v1/checklist/batch-attachments/{id}` | `ANY` | Bearer/Session | alias |

### Checklist page handlers covered by these APIs
- `melvin/checklist_batch.php`: `save_batch`, `add_item`
- `melvin/checklist_item.php`: `save_item`, `change_status`, `upload_attachment`, `delete_attachment`

### Checklist mutation notes
- `POST /api/v1/checklist/item_status` is the canonical workflow-status endpoint.
- `PATCH /api/v1/checklist/item` and `PATCH /api/v1/checklist/items/{id}` remain the definition-edit path for checklist managers.
- Assigned non-manager assignees may send a status-only `PATCH` payload as a temporary compatibility path; any other item field edits still require checklist-manager access.

## Discord Link
| Legacy source | Legacy flow | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| `discord-link.php` | view link state | `/api/v1/discord/link` | `GET` | Bearer/Session | live |
| `discord-link.php?action=generate_code` | generate link code | `/api/v1/discord/link-code` | `POST` | Bearer/Session | live |
| `discord-link.php?action=unlink` | unlink account | `/api/v1/discord/link` | `DELETE` | Bearer/Session | live |

## OpenClaw Internal
| Legacy endpoint | v1 route | Method | Auth | Status |
|---|---|---|---|---|
| `/api/openclaw/health.php` | `/api/v1/openclaw/health` | `GET` | Internal Bearer | alias |
| `/api/openclaw/link_prepare.php` | `/api/v1/openclaw/link-prepare` | `POST` | Bearer/Session | alias |
| `/api/openclaw/link_prepare.php` | `/api/v1/openclaw/link_prepare` | `POST` | Bearer/Session | alias |
| `/api/openclaw/link_confirm.php` | `/api/v1/openclaw/link-confirm` | `POST` | Internal Bearer | alias |
| `/api/openclaw/link_confirm.php` | `/api/v1/openclaw/link_confirm` | `POST` | Internal Bearer | alias |
| `/api/openclaw/link_context.php` | `/api/v1/openclaw/link-context` | `POST` | Internal Bearer | alias |
| `/api/openclaw/link_context.php` | `/api/v1/openclaw/link_context` | `POST` | Internal Bearer | alias |
| `/api/openclaw/checklist_duplicates.php` | `/api/v1/openclaw/checklist-duplicates` | `POST` | Internal Bearer | alias |
| `/api/openclaw/checklist_duplicates.php` | `/api/v1/openclaw/checklist_duplicates` | `POST` | Internal Bearer | alias |
| `/api/openclaw/checklist_batches.php` | `/api/v1/openclaw/checklist-batches` | `POST` | Internal Bearer | alias |
| `/api/openclaw/checklist_batches.php` | `/api/v1/openclaw/checklist_batches` | `POST` | Internal Bearer | alias |
| `/api/openclaw/runtime_config.php` | `/api/v1/openclaw/runtime-config` | `GET` | Internal Bearer | alias |
| `/api/openclaw/runtime_config.php` | `/api/v1/openclaw/runtime_config` | `GET` | Internal Bearer | alias |
| `/api/openclaw/runtime_reload.php` | `/api/v1/openclaw/runtime-reload` | `POST` | Internal Bearer | alias |
| `/api/openclaw/runtime_reload.php` | `/api/v1/openclaw/runtime_reload` | `POST` | Internal Bearer | alias |
| `/api/openclaw/runtime_status.php` | `/api/v1/openclaw/runtime-status` | `POST` | Internal Bearer | alias |
| `/api/openclaw/runtime_status.php` | `/api/v1/openclaw/runtime_status` | `POST` | Internal Bearer | alias |

## Legacy Bot Ingest
| Legacy endpoint | v1 route | Method | Auth | Status |
|---|---|---|---|---|
| `/melvin/checklist_bot_ingest.php` | `/api/v1/openclaw/checklist-ingest` | `POST` | Bot Token | alias |
| `/melvin/checklist_bot_ingest.php` | `/api/v1/openclaw/checklist_bot_ingest` | `POST` | Bot Token | alias |

## Super Admin OpenClaw
| Legacy source (`super-admin/openclaw.php`) | Legacy action | v1 route | Method | Auth | Status |
|---|---|---|---|---|---|
| overview load | runtime snapshot | `/api/v1/admin/openclaw/runtime` | `GET` | Bearer/Session (super_admin) | live |
| `save_runtime` | save runtime | `/api/v1/admin/openclaw/runtime` | `PUT` | Bearer/Session (super_admin) | live |
| `save_runtime` partial updates | patch runtime | `/api/v1/admin/openclaw/runtime` | `PATCH` | Bearer/Session (super_admin) | live |
| `reload_runtime` | queue reload | `/api/v1/admin/openclaw/runtime/reload` | `POST` | Bearer/Session (super_admin) | live |
| `test_snapshot` | runtime snapshot test | `/api/v1/admin/openclaw/snapshot` | `POST` | Bearer/Session (super_admin) | live |
| providers tab | list providers | `/api/v1/admin/openclaw/providers` | `GET` | Bearer/Session (super_admin) | live |
| `save_provider` | save provider | `/api/v1/admin/openclaw/providers` | `POST` | Bearer/Session (super_admin) | live |
| `delete_provider` | delete provider | `/api/v1/admin/openclaw/providers/{id}` | `DELETE` | Bearer/Session (super_admin) | live |
| models tab | list models | `/api/v1/admin/openclaw/models` | `GET` | Bearer/Session (super_admin) | live |
| `save_model` | save model | `/api/v1/admin/openclaw/models` | `POST` | Bearer/Session (super_admin) | live |
| `delete_model` | delete model | `/api/v1/admin/openclaw/models/{id}` | `DELETE` | Bearer/Session (super_admin) | live |
| channels tab | list channels | `/api/v1/admin/openclaw/channels` | `GET` | Bearer/Session (super_admin) | live |
| `save_channel` | save channel | `/api/v1/admin/openclaw/channels` | `POST` | Bearer/Session (super_admin) | live |
| `delete_channel` | delete channel | `/api/v1/admin/openclaw/channels/{id}` | `DELETE` | Bearer/Session (super_admin) | live |
| users tab | list linked users | `/api/v1/admin/openclaw/users` | `GET` | Bearer/Session (super_admin) | live |
| requests tab | list requests | `/api/v1/admin/openclaw/requests` | `GET` | Bearer/Session (super_admin) | live |

## E2E Coverage Map
- `e2e-tests/api-v1/tests/auth.spec.ts`: root, health, auth, refresh, forgot flows, session active-org.
- `e2e-tests/api-v1/tests/orgs.spec.ts`: full organization lifecycle and membership management.
- `e2e-tests/api-v1/tests/projects.spec.ts`: list/create/detail/update/archive/activate.
- `e2e-tests/api-v1/tests/issues-workflow.spec.ts`: full role chain + approve/close + reject/reassign + delete.
- `e2e-tests/api-v1/tests/checklist-alias.spec.ts`: all checklist v1 aliases including path/query id forms and attachment aliases.
- `e2e-tests/api-v1/tests/discord.spec.ts`: link state, code generation, unlink.
- `e2e-tests/api-v1/tests/openclaw-internal.spec.ts`: internal auth guards + canonical/alias OpenClaw endpoints + bot ingest aliases.
- `e2e-tests/api-v1/tests/admin-openclaw.spec.ts`: super-admin guards + runtime/providers/models/channels/users/requests APIs.

## Maintainability Notes
- Legacy paths remain active and mapped through aliases to preserve web compatibility.
- Canonical mobile/integration target should be `/api/v1/*` paths.
- Remove alias routes only after zero-traffic confirmation for old callers.
