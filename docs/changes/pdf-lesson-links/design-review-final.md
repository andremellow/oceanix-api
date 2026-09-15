# Independent PDF design review at r2

REQUEST_CHANGES. Run pdf-editor-20260914, scope pdf-links-v1, checkpoint pdf-links-20260914-r2, round 2, fresh independent context. This document records the returned reviewer report.

## PDF-DES-01

Medium implementation deviation, AC-06 / QA-07 and INV-04. Runtime inspection in all three editors found that selecting Read guide and inserting a PDF links the correct text, but typing after modal closure can append inside the final existing anchor rather than after the new PDF link. With no selection, Custom PDF followed by typing xyz can keep the suffix inside the PDF anchor. Source seam: resources/js/content-editor.js insertion, stored-mark clearing and deferred focus.

Required outcome: after modal closure and asynchronous updates settle, caret remains immediately after the inserted link and subsequent typing is outside every anchor, preserving surrounding content.

Other inspected behavior passed: selected/default/custom labels, cancellation focus, associated invalid-file and network upload errors, upload status/disabled submission, mobile modal layout, fullscreen visibility, and English/PT-BR source coverage. Desktop/mobile screenshots inspected. No production/test edits or authored test suite execution. Started and terminal telemetry emitted.
