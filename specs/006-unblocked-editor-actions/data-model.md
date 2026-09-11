# Data Model: Unblocked Editor Actions

No database schema changes are introduced.

## Canonical draft graph

Persisted course/version, record, question, option, composition, and media identities, positions, revisions, and lifecycle state. It remains authoritative and is produced by the existing authorized snapshot builder.

## Staged authored state

Ephemeral values keyed by stable identity and ancestry for whitelisted authored leaves only. It also carries dirty content keys, validation mapping, generation, and authored error provenance. It never contains authority, canonical order, revision calculation, media association, provider URLs, or persistence behavior.

## Rebase result

The canonical graph/revisions plus retained staged values, remapped validation paths, retained/dropped keys, and truthful dirty state. A confirmed removal drops only the exact target subtree. Malformed, duplicate, temporary, or cross-parent identities fail safe.

## State transitions

- Clean becomes dirty when an authored field changes.
- Dirty or validation-error remains authored-dirty after a successful immediate Action with retained differences.
- Dirty becomes clean only when the only staged values belonged to a confirmed removed target; it never reports Saved.
- Conflict, unknown response, or permission loss retains copyable values.
- Dirty becomes saved only after explicit Save succeeds.
