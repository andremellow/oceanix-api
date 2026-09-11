# Data Model: Reliable Course Authoring

No schema migration is required. Existing records and relationships remain authoritative.

## Course

- `company_id` plus normalized `code` remains the unique company-scoped identity.
- `code` is immutable after creation through the supported UI/application path.
- Existing authorization and ownership rules are unchanged.

## CourseVersion

- Drafts remain editable; published and retired versions remain immutable.
- Effective composition mode is derived:
  - `modules` when module-composition rows exist and direct lessons do not.
  - `direct_lessons` when direct lessons exist and module-composition rows do not.
  - `empty` when neither exists.
  - `mixed` when both exist; this state is preserved but cannot publish.

## CourseVersionModule

- Remains the ordered reference to an immutable reusable module version.
- Positions remain contiguous after add, remove, and move operations.
- Adding the first module is rejected when direct lessons already exist.

## Lesson

- Existing fields remain unchanged.
- `minimum_watch_percentage` is editable from 1 through 100 for direct lessons and remains tracking/reporting data; it does not gate assessment availability.
- `passing_score` remains editable from 1 through 100.
- Position remains contiguous after move or removal.

## Question

- Existing type, prompt, attempts, and position remain unchanged.
- Position becomes explicitly reorderable and is resequenced after removal.

## QuestionOption

- Existing answer text, correctness, and position remain unchanged.
- Position becomes explicitly reorderable and is resequenced after removal.
- Single-choice correctness continues to permit exactly one correct option at publication.

## UserTrainingAssignment

- Existing assignments remain frozen to their current `course_version_id` by default.
- Explicit replacement retains the existing cancel-and-supersede audit behavior.

## State transitions

```text
empty draft -> direct_lessons  (add first direct lesson)
empty draft -> modules         (add first reusable module)
direct_lessons -> empty        (remove all direct lessons explicitly)
modules -> empty               (remove all modules explicitly)
mixed -> direct_lessons        (remove all modules explicitly)
mixed -> modules               (remove all direct lessons explicitly)
mixed -> published             (forbidden)
```
