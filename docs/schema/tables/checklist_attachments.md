# checklist_attachments

## Purpose

Stores image/video attachments uploaded for checklist items by users or bot ingestion.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `checklist_item_id` | `INT(11)` | No | none | Parent checklist item. |
| `file_path` | `VARCHAR(255)` | No | none | Relative checklist upload path. |
| `original_name` | `VARCHAR(255)` | No | none | Original filename, sanitized before persistence. |
| `mime_type` | `VARCHAR(100)` | No | none | Detected MIME type. |
| `file_size` | `INT(11)` | No | none | Stored size in bytes. |
| `uploaded_by` | `INT(11)` | Yes | `NULL` | User uploader, nullable for bot/system uploads. |
| `source_type` | `ENUM('manual','bot')` | No | `manual` | Upload origin. |
| `created_at` | `DATETIME` | No | `CURRENT_TIMESTAMP` | Upload time. |

## Relationships

- `checklist_item_id -> checklist_items.id ON DELETE CASCADE`
- `uploaded_by -> users.id ON DELETE SET NULL`
