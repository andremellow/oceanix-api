# Independent Code Review — REQUEST_CHANGES

Run pdf-library-20260916; scope pdf-library-v1; checkpoint pdf-library-20260916-r1; round1; fresh independent context. Reviewer inspected all32 manifest files and causal dependencies, verified their hashes, ran no tests and edited no code. Started/completed telemetry recorded. No other material findings.

## CR-01 — Archive permission loss retains unauthorized catalog UI

P2 blocking implementation deviation; detected by Code Reviewer, failure stage implementation. Basis AC-05/AC-06, B-05, approved design denial states and architecture§8.

EditorCoordinator.php:1104–1106: requestArchivePdf invokes loadPdfLibrary, which catches denial and clears server catalog state, then aborts403 because the row is absent. Livewire calls actions before rendering/dehydrating, so that cleared state/error never reaches the browser. Existing filenames/actions remain behind the failed request. Archive-only revocation also produces uncaught abort. On revocation after confirmation opens, pdfFailure at1144–1148 clears only pdfLibrary, retaining pdfArchiveConfirmation/pdfArchiveModalOpen; root.blade.php:908–916 therefore retains the filename and Archive control.

Required outcome: normal modal denial transitions return updated permitted catalog/actions, clear unauthorized confirmation metadata, show localized denial and preserve editor HTML and insertion state; no archive occurs. Verify revocation before requesting confirmation and before confirming. No broader authorization redesign is requested. This is not QA approval.
