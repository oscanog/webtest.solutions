# users

## Purpose

Stores application accounts, authentication data, the system-level role, and the last active organization pointer used for session restoration.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `username` | `VARCHAR(100)` | No | none | Unique login/display name. |
| `email` | `VARCHAR(255)` | No | none | Unique login identifier. |
| `password` | `VARCHAR(255)` | No | none | Password hash. |
| `role` | `ENUM('super_admin','admin','user')` | Yes | `user` | System-level role, not organization role. |
| `created_at` | `TIMESTAMP` | No | `CURRENT_TIMESTAMP` | Account creation time. |
| `last_active_org_id` | `INT(11)` | Yes | `NULL` | Remembers the organization restored on login. |

## Keys and indexes

- Primary key: `id`
- Unique key: `uniq_users_username (username)`
- Unique key: `uniq_users_email (email)`

## Relationships

- Referenced by `organizations.owner_id`
- Referenced by `password_reset_requests.user_id`
- Referenced by `org_members.user_id`
- Referenced by `issues.author_id`
- Used logically by several assignee columns in `issues`, but those columns do not currently have foreign keys
- `last_active_org_id` points logically to `organizations.id`, but there is no foreign key in `schema.sql`

## How the application uses it

- Authentication reads `email`, `password`, `role`, and `last_active_org_id` during login.
- Signup inserts new `user` records.
- `role` is used to distinguish super admin, system admin, and regular user behavior.
- `last_active_org_id` is restored at login and updated when the user selects a different active organization.
- Organization-specific permissions are not stored here. Those come from `org_members.role`.

## Known limitations

- `last_active_org_id` has no foreign key, so it can become stale if an organization is removed.
- The system role model is very small and separate from organization roles, which can be confusing without documentation.
