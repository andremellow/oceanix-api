# UI Contract: Company Course Creation and Editor

## Creation modal

- Code, title, and description retain visible labels.
- Code is normalized before validation and checked within the current company.
- The primary action disables while running and exposes a text loading state.
- Validation remains inside the modal; cancellation closes and resets transient values/errors.

## Editor header

- Shows one of: unsaved changes, saving, saved time, or save error.
- Back navigation asks before leaving only when known work is unsaved.
- Publish remains permission-aware and disabled while a conflicting mutation runs.

## Course and version identity

- Course code is visible and read-only.
- Course title/description and employee-facing version description retain visible labels and inline errors.

## Composition

- The active composition mode is named in accessible text.
- Direct-lessons mode disables module addition with a specific explanation.
- Modules mode hides or disables direct-lesson creation with a specific explanation.
- Mixed mode shows both preserved inventories, a blocking warning, and instructions to remove one complete set; publication remains unavailable.

## Direct lessons and assessments

- Each lesson exposes title, description, required flag, watch threshold, passing score, content, video state, and questions; threshold help states that it is for tracking/reporting and does not block assessment access.
- Lessons, questions, and alternatives each expose a pointer drag handle and keyboard move-up/move-down controls.
- Every repeated answer field is visibly labelled by position; correct-answer selectors announce the same position.
- Destructive lesson/question actions retain confirmation; invalid assessment state remains visible inline and in publication readiness.

## Video state

- Uploading, processing, ready, and failed appear as text-labelled status near the lesson.
- Upload/search/use actions disable independently while running.

## Publication

- The modal explains immutability and displays affected assignment counts.
- Keeping current assignments is selected by default.
- Replacement requires explicit selection and preserves the existing audited cancellation/supersession behavior.

## Responsive and accessibility states

- At 320 CSS pixels, action groups wrap/stack without page-level horizontal overflow.
- Focus remains visible, icon-only controls have unique names, status is not color-only, and loading/error announcements use appropriate live regions.
