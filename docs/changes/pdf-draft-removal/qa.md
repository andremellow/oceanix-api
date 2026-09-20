# Independent runtime QA

PASS, zero findings. Run pdf-draft-removal-20260919, scope pdf-draft-removal-v1, checkpoint pdf-draft-removal-r1; round1, fresh independent context. Complete frozen QA-01/02 executed. Parent persisted report from read-only QA agent; no authored tests substituted for runtime.

New disposable /tmp/oceanix-pdf-qa-5It6TY, verified testing environment, isolated SQLite/private storage. Actual Chromium editor on loopback8874; application factories provided synthetic survivor/control lessons. No source review, .env or real database mutation.

- QA-01 (AC-01/02, INV-01/02): editor-confirmed company draft lesson1 removal returned200; reload omitted it. Survivor lessons9/10 retained positions1/2 and PDF pivots. Document metadata/timestamps identical. Lesson9 preview showed Retained PDF guide; actual contextual URL and rendered-link click returned200 application/pdf with original hash.
- QA-02 (AC-03, INV-02): same user4 successfully removed control lesson11. Opened lesson12 confirmation, removed all synthetic actor roles, then clicked Remove lesson. Response403 and explicit permission-removed/not-applied guidance. Lesson12 at position2 and its PDF pivot remained with lesson9's surviving use.

Original/storage/contextual-response SHA256:3896fbb310896873a391c65f6b104192eda3f1102aae1b8fe5c791cbf128fe68. Screenshots qa01-after.png and qa02-denied.png under disposable directory. No FK exception, unexpected page/request error or PHP application failure. Expected403 recorded by browser; application log unchanged since before QA.

Fixture correction: removing only editor role left edit permission granted by library-role prerequisites. That was still-authorized setup, not a product defect; repeated same scenario after effective all-role revocation. No added scenario.

Both temporary browsers and PHP server stopped; only owned fixtures/evidence retained. Started/completed telemetry includes both QA IDs, exact scope/checkpoint, pass and0 findings.
