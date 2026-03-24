# checklist_items

## Purpose

Stores individual checklist rows under a checklist batch, including assignment, workflow state, and optional issue linkage.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `batch_id` | `INT(11)` | No | none | Parent checklist batch. |
| `org_id` | `INT(11)` | No | none | Owning organization. |
| `project_id` | `INT(11)` | No | none | Parent project for filtering. |
| `sequence_no` | `INT(11)` | No | none | Unique order within a batch. |
| `title` | `VARCHAR(255)` | No | none | Checklist item title only. |
| `module_name` | `VARCHAR(160)` | No | none | Stored module name. |
| `submodule_name` | `VARCHAR(160)` | Yes | `NULL` | Stored submodule name. |
| `full_title` | `VARCHAR(255)` | No | none | Display format `Module | Submodule | Title`. |
| `description` | `LONGTEXT` | Yes | `NULL` | Main textarea content for steps, expected result, and notes. |
| `status` | `ENUM('open','in_progress','passed','failed','blocked')` | No | `open` | Checklist workflow state. Canonical API mutations use `/api/v1/checklist/item_status`. |
| `priority` | `ENUM('low','medium','high')` | No | `medium` | Priority marker. |
| `required_role` | role enum | No | `QA Tester` | Role mainly expected to perform the item. |
| `assigned_to_user_id` | `INT(11)` | Yes | `NULL` | Current assignee. Non-manager checklist work access is based on this assignment. |
| `issue_id` | `INT(11)` | Yes | `NULL` | Linked issue created after failure/blocking. |
| `started_at` | `DATETIME` | Yes | `NULL` | First in-progress timestamp. |
| `completed_at` | `DATETIME` | Yes | `NULL` | Completion timestamp for passed/failed/blocked. |

## Relationships

- `batch_id -> checklist_batches.id ON DELETE CASCADE`
- `org_id -> organizations.id ON DELETE CASCADE`
- `project_id -> projects.id ON DELETE CASCADE`
- `assigned_to_user_id -> users.id ON DELETE SET NULL`
- `created_by -> users.id`
- `updated_by -> users.id ON DELETE SET NULL`
- `issue_id -> issues.id ON DELETE SET NULL`
- Referenced by `checklist_attachments.checklist_item_id`

## Dashboard Usage

- The QA Lead dashboard workload summary derives its tester counts from `project_id`, `required_role`, `assigned_to_user_id`, and `status`.
- The summary only includes QA Tester workload:
  - assigned rows require the assignee to be an org member with role `QA Tester`
  - unassigned rows require `required_role = 'QA Tester'` and no current assignee
- The dashboard's "open" workload count maps directly to `status = 'open'`.
