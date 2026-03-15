# Schema Documentation

Use this section to understand the current database shape, the runtime workflow built on top of it, and the recommended follow-up improvements.

## Current schema

- [Overview](overview.md)
- [Issue workflow](workflow.md)
- [users](tables/users.md)
- [password_reset_requests](tables/password_reset_requests.md)
- [organizations](tables/organizations.md)
- [org_members](tables/org_members.md)
- [labels](tables/labels.md)
- [issues](tables/issues.md)
- [projects](tables/projects.md)
- [checklist_batches](tables/checklist_batches.md)
- [checklist_items](tables/checklist_items.md)
- [checklist_attachments](tables/checklist_attachments.md)
- [issue_labels](tables/issue_labels.md)
- [issue_attachments](tables/issue_attachments.md)
- [contact](tables/contact.md)

## Recommendations

- [Recommendation guide](recommendations/README.md)

## Maintenance rule

- Update the table reference files when `infra/database/schema.sql` changes.
- Update [workflow.md](workflow.md) when issue status or assignment-state literals in PHP change.
- Keep deployment and bootstrap instructions in `infra/` as the authoritative source.
