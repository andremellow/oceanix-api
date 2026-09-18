# Directed Code Verification — APPROVE

Run pdf-library-20260916; scope pdf-library-v1; checkpoint pdf-library-20260916-r2; round1 remediation; inherited directed context. Assigned CR-01. Zero new findings. No tests run or files edited; started/completed directed telemetry succeeded.

requestArchivePdf now returns normally after denied refresh, rendering cleared state/localized denial. Archive-only revocation refreshes permitted rows, removes Archive and clears confirmation. pdfFailure clears confirmation identity/dialog after authorization denial, refreshes permitted catalog and displays denial in parent. HTML/label/token remain; denied request does not archive and Action boundary still enforces denial during confirmation. Four-case regression asserts HTTP200, cleared confirmation, preserved content/token, zero archives.

Causal transport delta: recovery restricted to one allowlisted PDF call on transport/server failure; retains insertion state and requires explicit retry; unrelated/mixed requests retain separate handling. Browser assertions cover interrupted search, postcommit archive retry and retained-selection reuse. Four affected production hashes match r2.

CR-01 closed. Original full PDF Library review's unaffected evidence retained: Actions, Services, schema and authorization boundaries unchanged. No older PDF-links approval reused.
