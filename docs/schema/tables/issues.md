# issues

## Purpose

Stores the main issue record plus the current workflow assignees and milestone timestamps used by the application workflow.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `title` | `VARCHAR(255)` | No | none | Issue title. |
| `description` | `TEXT` | Yes | `NULL` | Issue body. |
| `status` | `ENUM('open','closed')` | Yes | `open` | High-level issue open/closed state. |
| `author_id` | `INT(11)` | Yes | `NULL` | User who created the issue. |
| `org_id` | `INT(11)` | No | none | Organization that owns the issue. |
| `assigned_dev_id` | `INT(11)` | Yes | `NULL` | Current Senior Developer assignee. |
| `assign_status` | `VARCHAR(20)` | No | `unassigned` | Workflow stage string enforced by PHP. |
| `assigned_at` | `DATETIME` | Yes | `NULL` | Time the Project Manager assigned the senior developer. |
| `created_at` | `TIMESTAMP` | No | `CURRENT_TIMESTAMP` | Issue creation time. |
| `assigned_junior_id` | `INT(11)` | Yes | `NULL` | Current Junior Developer assignee. |
| `assigned_qa_id` | `INT(11)` | Yes | `NULL` | Current QA Tester assignee. |
| `assigned_senior_qa_id` | `INT(11)` | Yes | `NULL` | Current Senior QA assignee. |
| `assigned_qa_lead_id` | `INT(11)` | Yes | `NULL` | Current QA Lead assignee. |
| `junior_assigned_at` | `DATETIME` | Yes | `NULL` | Time the junior developer was assigned. |
| `qa_assigned_at` | `DATETIME` | Yes | `NULL` | Time the QA tester was assigned. |
| `senior_qa_assigned_at` | `DATETIME` | Yes | `NULL` | Time the Senior QA was assigned. |
| `qa_lead_assigned_at` | `DATETIME` | Yes | `NULL` | Time the QA Lead was assigned. |
| `junior_done_at` | `DATETIME` | Yes | `NULL` | Time the junior developer marked the issue done. |
| `pm_id` | `INT(11)` | Yes | `NULL` | Project Manager who owns the assignment cycle. |

## Keys and indexes

- Primary key: `id`
- Index: `idx_issues_author (author_id)`
- Index: `idx_issues_org (org_id)`
- Index: `idx_issues_assigned_dev (assigned_dev_id)`
- Index: `idx_issues_assigned_qa_id (assigned_qa_id)`
- Index: `idx_issues_assign_status (assign_status)`

## Relationships

- Foreign key: `author_id -> users.id`
- Foreign key: `org_id -> organizations.id ON DELETE CASCADE`
- Logical user references without foreign keys:
  - `assigned_dev_id -> users.id`
  - `assigned_junior_id -> users.id`
  - `assigned_qa_id -> users.id`
  - `assigned_senior_qa_id -> users.id`
  - `assigned_qa_lead_id -> users.id`
  - `pm_id -> users.id`
- Referenced by `issue_labels.issue_id`
- Referenced by `issue_attachments.issue_id`

## How the application uses it

- `/zen/create_issue.php` inserts the base issue row with `title`, `description`, `author_id`, and `org_id`.
- `/zen/dashboard.php` and `/api/v1/issues` expose issue list/detail reads to all members of the active organization.
- The legacy dashboard keeps the author filter for system admins only, while label filters remain available to everyone.
- The workflow uses the assignee columns to represent the current holder of each stage rather than storing a separate assignment history.
- Workflow actions still rely on the assignee columns and member roles, so read access is broader than mutation access.
- The current observed `assign_status` set is:
  - `unassigned`
  - `rejected`
  - `with_senior`
  - `with_junior`
  - `done_by_junior`
  - `with_qa`
  - `with_senior_qa`
  - `with_qa_lead`
  - `approved`
  - `closed`
- Rejection clears most assignee and workflow timestamp columns so the Project Manager can restart the cycle.
- Closing an issue sets both `status='closed'` and `assign_status='closed'`.

## Known limitations

- `assign_status` is a free-form `VARCHAR(20)` even though the application treats it as a finite state machine.
- The workflow uses both `status` and `assign_status` to represent closure, which overlaps responsibilities.
- Most assignee columns and `pm_id` do not have foreign keys, so orphaned user references are possible.
- Several workflow lookup paths used by the app do not have dedicated indexes, especially `assigned_junior_id`, `assigned_senior_qa_id`, `assigned_qa_lead_id`, and `pm_id`.
- One column is added for each workflow role, which makes the table wider and harder to extend over time.
