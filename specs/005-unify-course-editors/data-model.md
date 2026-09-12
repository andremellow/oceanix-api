# Data Model: Unified Course Editors

No schema change is planned. Existing records and constraints remain authoritative.

## EditorContext (application value)

- `kind`: company course, shared course, or standalone shared module; server-resolved.
- `root`: authorized Course/CourseVersion or Module/ModuleVersion.
- `capabilities`: operations derived from authorization/lifecycle, never client ownership flags.
- `collaborators`: applicable snapshot/save/structure/media/preview/discard/publish handlers.

## EditorDraftSnapshot (application value)

- Canonical scalar fields and ordered lesson/module/question/option graph.
- Stable persisted IDs and temporary UI UUIDs.
- Media status and content-dirty markers.
- Expected root/nested revision hashes.
- Serialization excludes secrets, public video URLs, and answer keys outside the authorized editor.

## PendingEditSet (browser/Livewire state)

- Current authored values and monotonically increasing local edit generation.
- State: clean, dirty, saving, saved, validation-error, conflict, or network-error.
- Error provenance by atomic Save/staging request versus immediate media transfer.
- Active upload tokens bound to stable record identity.

## Existing records

- `Course` / `CourseVersion`: explicit owner combination; immutable publication/history.
- `Lesson` / `Module` / `ModuleVersion`: shared physical lineage with published protection.
- `CourseVersionModule`: ordered immutable references; mirror rows do not imply reusable modules.
- `Question` / `QuestionOption`: stable IDs, contiguous positions, single-choice correctness.
- `Video` / `ContentImage`: private/provider identity and ownership; no permanent public URL.

## State transitions

```text
canonical -> dirty -> saving -> saved -> canonical
                    |-> validation-error (dirty retained)
                    |-> conflict (dirty retained)
                    |-> network-error (dirty retained)

clean/dirty + confirmed structural action -> staged dirty graph -> Save
                                            |-> visible staging error/retry

media transfer -> uploading/processing/ready
draft media association -> staged dirty graph -> Save
```

Published versions never become editable. Discard/publish retains existing context-specific actions, audit, lineage, and history effects.

## Validation and concurrency

- Validate the complete applicable authored graph before writes.
- Recompute root/nested revisions after stable row locks.
- Persisted payload identities exactly match current authorized sets; temporary UUIDs may create rows.
- Reorders require exact unique sibling IDs before temporary/final positions.
- Invalid, stale, unauthorized, or failed save rolls back completely.
- Validation and authorization cover the complete visible staged graph, including structural changes and media associations, before any draft row changes.
