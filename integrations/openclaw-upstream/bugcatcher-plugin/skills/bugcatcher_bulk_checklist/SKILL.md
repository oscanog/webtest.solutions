---
name: bugcatcher_bulk_checklist
description: Create BugCatcher checklist batches from Discord conversations by using the bugcatcher_create_bulk_checklist tool.
metadata: {"openclaw":{"emoji":"🪲","homepage":"https://bugcatcher.online"}}
---

# BugCatcher Bulk Checklist

Use `bugcatcher_create_bulk_checklist` whenever the user wants BugCatcher checklist items created from a Discord conversation.

## Required workflow

1. Start by checking whether the Discord user is already linked.
2. If link context is missing, tell the user to generate a BugCatcher link code from `/discord-link.php`, then accept `link <code>` and call `action: "confirm_link"`.
3. Require at least one image attachment before any draft generation.
4. Stage image attachments with `action: "stage_attachments"` before final submission.
5. If the linked user belongs to multiple organizations or projects, ask the user to choose explicitly.
6. Draft checklist items in a senior-QA style before calling duplicate detection.
7. Run duplicate detection with `action: "check_duplicates"` before submission.
8. Present duplicate decisions clearly:
   - skip duplicates
   - include duplicates
   - review duplicates one by one
9. Submit only the final approved items with `action: "submit_batch"`.
10. If submission succeeds, report the created batch and item counts.
11. If staging succeeded but submission fails, call `action: "cleanup_attachments"` for any unused temp tokens when practical.

## Draft quality rules

Every drafted checklist item description should include:

- test intent
- setup or prerequisites when needed
- user action
- expected result
- verification note when needed

Keep item titles short and concrete. Put the detail in the description.

## Input normalization

Preferred `submit_batch` call shape:

```json
{
  "action": "submit_batch",
  "payload": {
    "org_id": 12,
    "project_id": 9,
    "requested_by_user_id": 22,
    "discord_user_id": "1010931769794642073",
    "batch": {
      "title": "Checkout QA",
      "module_name": "Checkout"
    },
    "items": [],
    "batch_attachments": []
  }
}
```

Inside `payload`, BugCatcher expects:

- `org_id`
- `project_id`
- `requested_by_user_id`
- `discord_user_id`
- `batch`
- `items`
- `batch_attachments`

`batch_attachments` must come from staged attachment tokens:

```json
[
  {
    "temp_file_token": "token-from-stage_attachments",
    "original_name": "original-filename.png"
  }
]
```

Legacy top-level submit fields are still accepted temporarily for compatibility, but use `payload` for all new calls.

## Rejections

Reject or pause the flow clearly when:

- there is no image attachment
- the attachment type is unsupported
- the link code is invalid or expired
- the selected organization or project is invalid
- BugCatcher API auth fails
- the model output is malformed

## Tool actions

- `load_context`: find the linked BugCatcher user, orgs, and projects
- `confirm_link`: attach a Discord user to a BugCatcher account using a link code
- `stage_attachments`: download image URLs and store them in BugCatcher's temp upload directory
- `check_duplicates`: compare candidate items against an existing BugCatcher project
- `submit_batch`: create the final checklist batch
- `cleanup_attachments`: remove temporary staged files after a failed run or cancellation
- `health`: inspect the BugCatcher OpenClaw integration state
