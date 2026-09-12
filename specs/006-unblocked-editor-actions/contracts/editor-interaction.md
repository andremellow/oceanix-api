# UI Contract: Unblocked Draft Editing

1. Unsaved authored values never disable or intercept an otherwise valid draft-editing control.
2. The exact add–type–add answer journey works without an intermediate Save in all editor contexts.
3. Immediate Actions persist only their named structural/media effect. Authored values remain visibly unsaved.
4. Post-operation state uses refreshed canonical revisions and stable identity; a later explicit Save must not conflict with the editor's own previous Action.
5. A response may replace canonical identity/order/media only after staged values are mapped to surviving identities. Newer browser edits win over request-start values.
6. Real blockers remain visible and specific: permission/lifecycle, stale or unknown outcome, conflicting pending operation, destructive confirmation, and applicable active upload.
7. Publish and saved-draft preview consume canonical saved data only and may continue requiring a clean editor.
