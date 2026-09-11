# Scenario Map: Unblocked Editor Actions

| ID | Rule | Preconditions | Action | Observable outcomes | Prohibited side effects | Automation |
| --- | --- | --- | --- | --- | --- | --- |
| SCN-01 | FR-001, FR-002 | Clean editable question | Add answer, type unique text, add another answer | Both answers visible; first text retained; controls remain available | No hidden authored persistence | Feature + browser, all contexts |
| SCN-02 | FR-003–FR-005 | Dirty root, record, question and option values | Add/reorder applicable structure | Structure persists; values retain stable identity; revision refreshes; Save/reload exact | No authored value persists before Save | Unit + feature + browser |
| SCN-03 | FR-003–FR-005 | Dirty unrelated values and a destructive target | Decline, then confirm removal | Decline no-op; confirmation removes exact target; unrelated values retained | No unrelated staged subtree dropped | Feature + browser |
| SCN-04 | FR-001, FR-003, FR-007 | Dirty content and authorized media access | Open/insert/attach/upload applicable media | Correct target and private boundary; dirty content retained | No public URL or hidden authored Save | Feature + browser |
| SCN-05 | FR-006 | Dirty editor with stale/revoked/unknown/conflicting state | Attempt immediate operation | Cause-specific failure; exact no-write snapshot; values retained | No automatic retry or false Saved | Unit + feature + JS + browser |
| SCN-06 | FR-004 | Operation request in flight | Type a newer value before response | Newer value wins after morph and Save | No stale response overwrite | JS + browser |
| SCN-07 | FR-004, FR-006 | Malformed/duplicated/cross-parent identity | Apply canonical refresh | Fail safe; no value attaches elsewhere | No identity substitution | Unit |

Dimensions: authorized positive, denial, boundary identity, lifecycle, concurrency, retry, ordering, desktop/mobile rendering, and provider failure are applicable. New roles, schema migration, billing, pagination, and destructive production data are N/A because this change introduces none.
