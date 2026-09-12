# Research: Unblocked Editor Actions

## Decision: preserve staged values around immediate Actions

The current immediate structure/media Actions remain the canonical bounded write boundary. The coordinator captures only whitelisted authored values by stable identity before the Action and rebases them onto the post-operation snapshot. This avoids hidden Save and avoids rebuilding the entire structural persistence model.

Alternatives rejected: autosaving before an operation violates explicit Save; purely client-side temporary structure materially expands Save persistence; restoring duplicated platform editors reverses the unification.

## Decision: protect edits newer than the request

Use the existing client generation/state module to retain stable-keyed values changed after dispatch and reapply them after Livewire morph. Server rebase protects request-start values; the browser overlay protects later keystrokes.

## Decision: retain real safety locks

Dirty state and authored validation errors do not block editing Actions. Stale conflict, unknown response, permission/lifecycle denial, a conflicting in-flight operation, missing destructive confirmation, and applicable same-target upload remain blockers with exact guidance.

Sources: repository rules/design system; current coordinator/Actions/tests; official Livewire 4 wire:model, actions, hydration, morphing, and loading guidance; official Laravel 13 transaction/locking guidance. Laravel Boost is not installed.
