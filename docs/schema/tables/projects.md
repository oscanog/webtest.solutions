# projects

## Purpose

Stores organization-scoped project records used by the checklist module.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `org_id` | `INT(11)` | No | none | Owning organization. |
| `name` | `VARCHAR(160)` | No | none | Project display name, unique within an organization. |
| `code` | `VARCHAR(80)` | Yes | `NULL` | Optional short code. |
| `description` | `TEXT` | Yes | `NULL` | Project notes. |
| `status` | `ENUM('active','archived')` | No | `active` | Project lifecycle state. |
| `created_by` | `INT(11)` | No | none | User who created the project. |
| `updated_by` | `INT(11)` | Yes | `NULL` | Last user who changed the project. |
| `created_at` | `TIMESTAMP` | No | `CURRENT_TIMESTAMP` | Creation time. |
| `updated_at` | `TIMESTAMP` | Yes | `NULL` | Last update time. |

## Relationships

- `org_id -> organizations.id ON DELETE CASCADE`
- `created_by -> users.id`
- `updated_by -> users.id ON DELETE SET NULL`
- Referenced by `checklist_batches.project_id`
