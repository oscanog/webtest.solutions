# checklist_batches

## Purpose

Stores one manual or bot-generated checklist batch under a project.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `org_id` | `INT(11)` | No | none | Owning organization. |
| `project_id` | `INT(11)` | No | none | Parent project. |
| `title` | `VARCHAR(255)` | No | none | Batch title shown in list/detail views. |
| `module_name` | `VARCHAR(160)` | No | none | Main module name. |
| `submodule_name` | `VARCHAR(160)` | Yes | `NULL` | Optional submodule name. |
| `source_type` | `ENUM('manual','bot')` | No | `manual` | Indicates whether the batch was user-created or imported. |
| `source_channel` | `ENUM('web','telegram','discord','api')` | No | `web` | Source entrypoint. |
| `source_reference` | `VARCHAR(255)` | Yes | `NULL` | External message or request reference. |
| `status` | `ENUM('draft','open','completed','archived')` | No | `open` | Batch state. |
| `created_by` | `INT(11)` | No | none | Creating user. |
| `updated_by` | `INT(11)` | Yes | `NULL` | Last updating user. |
| `assigned_qa_lead_id` | `INT(11)` | Yes | `NULL` | QA Lead responsible for the batch. |
| `notes` | `TEXT` | Yes | `NULL` | Freeform notes and deferred attachment references. |

## Relationships

- `org_id -> organizations.id ON DELETE CASCADE`
- `project_id -> projects.id ON DELETE CASCADE`
- `created_by -> users.id`
- `updated_by -> users.id ON DELETE SET NULL`
- `assigned_qa_lead_id -> users.id ON DELETE SET NULL`
- Referenced by `checklist_items.batch_id`
