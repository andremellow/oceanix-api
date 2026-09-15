import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

test('PDF selection, upload, save, reload, cancellation, errors and real learner popup', { timeout: 120000 }, async () => {
    const directory = await mkdtemp('/tmp/oceanix-pdf-qa-');
    const environment = { ...process.env, OCEANIX_PDF_QA_DIR: directory };
    const setup = spawnSync('php', ['tests/Support/Documents/QaFixture.php'], { env: environment, encoding: 'utf8' });
    assert.equal(setup.status, 0, setup.stderr);
    const fixture = JSON.parse(await readFile(`${directory}/manifest.json`, 'utf8'));
    const port = await new Promise(resolve => { const server = createServer(); server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); }); });
    const base = `http://127.0.0.1:${port}`;
    const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Support/Documents/QaRouter.php'], { env: environment, stdio: 'ignore' });
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (let attempt = 0; attempt < 50; attempt++) {
            try { await page.goto(`${base}/__pdf-qa/login/${fixture.author}`); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        for (const key of ['companyEditor', 'sharedCourseEditor', 'sharedModuleEditor']) {
            await page.goto(base + fixture[key]);
            const editor = page.locator('[data-oceanix-editor-model="records.0.content_markdown"]');
            await editor.locator('[contenteditable]').waitFor();
            const originalParagraph = await editor.locator('[contenteditable] p').first().textContent();
            const selectedPrefix = originalParagraph.slice(0, originalParagraph.indexOf('Read guide') + 'Read guide'.length);
            const continuedParagraph = originalParagraph.replace('Read guide', 'Read guide more');
            await page.evaluate(() => {
                const editor = document.querySelector('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable]');
                editor.focus();
                const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
                let node; while ((node = walker.nextNode()) && !node.textContent.includes('Read guide')) {}
                const offset = node.textContent.indexOf('Read guide');
                const range = document.createRange(); range.setStart(node, offset); range.setEnd(node, offset + 10);
                const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
                document.dispatchEvent(new Event('selectionchange'));
            });
            await page.waitForTimeout(100);
            await editor.locator('[data-editor-action-detail="open-pdf-modal"]').click();
            const modal = page.locator('dialog[open]');
            await modal.waitFor();
            assert.equal(await modal.locator('input[wire\\:model="pdfLinkText"]').inputValue(), 'Read guide');
            await modal.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
            await page.waitForFunction(() => !document.querySelector('dialog[open] button[type=submit]').disabled);
            await page.waitForTimeout(200);
            await modal.getByRole('button', { name: 'Upload and insert link' }).click();
            await editor.locator('a[href^="/lesson-documents/"]').filter({ hasText: 'Read guide' }).waitFor();
            await modal.waitFor({ state: 'hidden' });
            // Allow the upload response morph and native dialog focus restoration to settle.
            await page.waitForTimeout(500);
            const caret = await editor.locator('[contenteditable]').evaluate(el => {
                const selection = getSelection();
                const range = document.createRange(); range.selectNodeContents(el); range.setEnd(selection.anchorNode, selection.anchorOffset);
                const parent = selection.anchorNode.nodeType === Node.ELEMENT_NODE ? selection.anchorNode : selection.anchorNode.parentElement;
                return { collapsed: selection.isCollapsed, before: range.toString(), anchor: parent.closest('a')?.outerHTML ?? null, focused: document.activeElement === el };
            });
            assert.deepEqual(caret, { collapsed: true, before: selectedPrefix, anchor: null, focused: true });
            await page.keyboard.type(' more');
            assert.equal(await editor.locator('a[href^="/lesson-documents/"]').filter({ hasText: 'Read guide' }).textContent(), 'Read guide');
            assert.equal(await editor.locator('[contenteditable] p').first().textContent(), continuedParagraph);
            await page.locator('[data-editor-save]').click();
            await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
            await page.reload();
            const link = page.locator('[contenteditable] a').filter({ hasText: 'Read guide' }).first();
            await link.waitFor();
            assert.equal(await link.getAttribute('target'), '_blank');
            assert.match(await editor.innerHTML(), /<strong>Before<\/strong>/);
            assert.equal(await editor.locator('a[href="https://example.com"]').count(), 1);
            // No-selection custom insertion must also resume at the exact insertion point.
            await editor.locator('[contenteditable]').evaluate(el => {
                el.focus();
                const range = document.createRange(); range.setStartAfter(el.querySelector('strong')); range.collapse(true);
                getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange'));
            });
            await page.waitForTimeout(100);
            await editor.locator('[data-editor-action-detail="open-pdf-modal"]').click();
            await modal.waitFor();
            assert.equal(await modal.locator('input[wire\\:model="pdfLinkText"]').inputValue(), '');
            await modal.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
            const customLabel = `Custom insertion ${key}`;
            await modal.locator('input[wire\\:model="pdfLinkText"]').fill(customLabel);
            await page.waitForTimeout(350);
            await modal.getByRole('button', { name: 'Upload and insert link' }).click();
            await modal.waitFor({ state: 'hidden' });
            await page.waitForTimeout(500);
            const customCaret = await editor.locator('[contenteditable]').evaluate(el => {
                const selection = getSelection();
                const range = document.createRange(); range.selectNodeContents(el); range.setEnd(selection.anchorNode, selection.anchorOffset);
                const parent = selection.anchorNode.nodeType === Node.ELEMENT_NODE ? selection.anchorNode : selection.anchorNode.parentElement;
                return { before: range.toString(), anchor: parent.closest('a')?.outerHTML ?? null, collapsed: selection.isCollapsed, focused: document.activeElement === el };
            });
            assert.deepEqual(customCaret, { before: `Before${customLabel}`, anchor: null, collapsed: true, focused: true });
            await page.keyboard.type(' plain');
            const customParagraph = continuedParagraph.replace('Before', `Before${customLabel} plain`);
            assert.equal(await editor.locator('[contenteditable] p').first().textContent(), customParagraph);
            assert.equal(await editor.locator('a').filter({ hasText: customLabel }).textContent(), customLabel);
            assert.equal(await editor.locator('a').filter({ hasText: ' plain' }).count(), 0);
            await page.locator('[data-editor-save]').click();
            await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
            await page.reload();
            await editor.locator('[contenteditable]').waitFor();
            assert.equal(await editor.locator('[contenteditable] p').first().textContent(), customParagraph);
            // Dismissal has no insertion and restores focus, including on a narrow screen.
            await page.setViewportSize({ width: 390, height: 844 });
            const before = await editor.locator('[contenteditable]').innerHTML();
            await editor.locator('[data-editor-action-detail="open-pdf-modal"]').click();
            await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
            await page.waitForTimeout(100);
            assert.equal(await editor.locator('[contenteditable]').innerHTML(), before);
            assert.equal(await page.evaluate(() => document.activeElement.isContentEditable), true);
            await page.setViewportSize({ width: 1280, height: 900 });
        }
        await page.goto(base + fixture.companyEditor);
        const button = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
        const authored = page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable]');
        // CR-01: accepting an exact default preserves text and formatting, including whitespace, 0 and >500 characters.
        for (const sample of [' leading ', '0', 'Long selected text ']) {
            const before = await authored.innerHTML();
            const selected = await page.evaluate(sample => {
                const editor = document.querySelector('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable]');
                editor.focus();
                const paragraph = [...editor.querySelectorAll('p')].find(p => sample === '0' ? p.textContent === sample : p.textContent.includes(sample));
                const range = document.createRange(); range.setStart(paragraph, sample === ' leading ' ? 1 : 0); range.setEnd(paragraph, sample === '0' ? paragraph.childNodes.length : paragraph.childNodes.length - 1);
                getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange'));
                return range.toString();
            }, sample);
            await page.waitForTimeout(80);
            await button.click();
            const dialog = page.locator('dialog[open]');
            assert.equal(await dialog.locator('input[wire\\:model="pdfLinkText"]').inputValue(), selected);
            await dialog.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
            await page.waitForTimeout(350);
            await dialog.getByRole('button', { name: 'Upload and insert link' }).click();
            await dialog.waitFor({ state: 'hidden' });
            const after = await authored.innerHTML();
            const stripAnchors = html => html.replace(/<a\b[^>]*>|<\/a>/g, '');
            assert.equal(stripAnchors(after), stripAnchors(before));
            const texts = await authored.locator('a[href^="/lesson-documents/"]').allTextContents();
            assert(texts.join('').includes(selected));
            await page.locator('[data-editor-save]').click();
            await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
            await page.reload();
            assert.equal(stripAnchors(await authored.innerHTML()), stripAnchors(before));
        }
        // CR-02: all native dismissals clear selection and restore focus; keyboard reopening captures current selection.
        for (const dismissal of ['cancel', 'escape', 'x', 'outside']) {
            const before = await authored.innerHTML();
            await button.click();
            const dialog = page.locator('dialog[open]');
            await dialog.waitFor();
            if (dismissal === 'cancel') await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
            if (dismissal === 'escape') await page.keyboard.press('Escape');
            if (dismissal === 'x') await dialog.locator('[data-flux-modal-close] button').click();
            if (dismissal === 'outside') await page.mouse.click(5, 5);
            await dialog.waitFor({ state: 'hidden' });
            await page.waitForTimeout(100);
            assert.equal(await authored.innerHTML(), before);
            assert.equal(await page.evaluate(() => document.activeElement.isContentEditable), true);
            await page.evaluate(() => { const editor = document.querySelector('[contenteditable]'); editor.focus(); const range = document.createRange(); range.selectNodeContents(editor); range.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange')); });
            await button.focus(); await button.press('Enter');
            assert.equal(await dialog.locator('input[wire\\:model="pdfLinkText"]').inputValue(), '');
            await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        }
        // CR-02: hold an actual committed upload response, dismiss through X, then deliver its stale completion.
        await button.click();
        let staleDialog = page.locator('dialog[open]');
        await staleDialog.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
        await page.waitForTimeout(350);
        let releaseCompletion;
        const completionHeld = new Promise(resolve => { releaseCompletion = resolve; });
        let completionSeen;
        const completionCaptured = new Promise(resolve => { completionSeen = resolve; });
        await page.route(/\/livewire[^/]*\/update$/, async route => {
            if (!(route.request().postData() || '').includes('"method":"uploadPdf"')) return route.continue();
            const response = await route.fetch(); completionSeen(); await completionHeld; await route.fulfill({ response });
        });
        const beforeStale = await authored.innerHTML();
        await staleDialog.getByRole('button', { name: 'Upload and insert link' }).click();
        await completionCaptured;
        await staleDialog.locator('[data-flux-modal-close] button').click();
        await staleDialog.waitFor({ state: 'hidden' });
        releaseCompletion();
        await page.waitForTimeout(400);
        await page.unroute(/\/livewire[^/]*\/update$/);
        assert.equal(await authored.innerHTML(), beforeStale);
        await page.evaluate(() => { const editor = document.querySelector('[contenteditable]'); editor.focus(); const range = document.createRange(); range.selectNodeContents(editor); range.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange')); });
        // TA-03: real transfer is held while disabled/duplicate and narrow loaded-file controls are inspected.
        await page.setViewportSize({ width: 390, height: 844 });
        await button.focus(); await button.press('Enter');
        let releaseTransfer;
        const held = new Promise(resolve => { releaseTransfer = resolve; });
        let transferSeen;
        const transferStarted = new Promise(resolve => { transferSeen = resolve; });
        let transferFinished;
        const transferDone = new Promise(resolve => { transferFinished = resolve; });
        await page.route(/\/livewire[^/]*\/upload-file/, async route => { transferSeen(); await held; await route.continue(); transferFinished(); });
        let dialog = page.locator('dialog[open]');
        await dialog.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
        await transferStarted;
        const submit = dialog.getByRole('button', { name: 'Upload and insert link' });
        assert.equal(await submit.isDisabled(), true);
        await submit.evaluate(el => { el.click(); el.click(); });
        const rect = await submit.boundingBox();
        assert(rect.x >= 0 && rect.x + rect.width <= 390 && rect.y >= 0 && rect.y + rect.height <= 844);
        assert.equal(await dialog.locator('input[wire\\:model="pdfLinkText"]').inputValue(), 'lesson-guide.pdf');
        const countBefore = await authored.locator('a[href^="/lesson-documents/"]').count();
        releaseTransfer(); await transferDone; await page.unroute(/\/livewire[^/]*\/upload-file/);
        await page.waitForTimeout(400);
        await submit.click();
        await dialog.waitFor({ state: 'hidden' });
        assert.equal(await authored.locator('a[href^="/lesson-documents/"]').count(), countBefore + 1);
        await page.locator('[data-editor-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
        await page.reload();
        assert.equal(await authored.locator('a').filter({ hasText: 'lesson-guide.pdf' }).count(), 1);
        await page.setViewportSize({ width: 1280, height: 900 });
        await button.focus(); await button.press('Enter');
        let modal = page.locator('dialog[open]');
        const beforeInvalid = await authored.innerHTML();
        await modal.locator('input[type=file]').setInputFiles({ name: 'false.pdf', mimeType: 'application/pdf', buffer: Buffer.from('plain text') });
        await page.waitForTimeout(400);
        await modal.getByRole('button', { name: 'Upload and insert link' }).click();
        await page.locator('#editor-pdf-error p').waitFor();
        assert.equal(await modal.locator('input[type=file]').getAttribute('aria-invalid'), 'true');
        assert.equal(await authored.innerHTML(), beforeInvalid);
        await modal.locator('input[type=file]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
        await modal.locator('input[wire\\:model="pdfLinkText"]').fill('Custom PDF label');
        await page.waitForTimeout(400);
        await modal.getByRole('button', { name: 'Upload and insert link' }).click();
        await page.locator('[contenteditable] a').filter({ hasText: 'Custom PDF label' }).waitFor();
        await page.locator('[data-editor-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
        await page.reload();
        await authored.locator('a').filter({ hasText: 'Custom PDF label' }).waitFor();
        await page.goto(`${base}/__pdf-qa/login/${fixture.learner}`);
        await page.goto(base + fixture.learnerLesson);
        const original = page.url();
        const saved = page.getByRole('link', { name: 'Saved PDF guide' });
        const href = await saved.getAttribute('href');
        const response = await context.request.get(href);
        assert.equal(response.status(), 200);
        assert.equal(response.headers()['content-type'], 'application/pdf');
        assert.match(response.headers()['content-disposition'], /^inline;/);
        const popupPromise = page.waitForEvent('popup');
        const pdfResponsePromise = context.waitForEvent('response', response => response.url() === href);
        await saved.click();
        const popup = await popupPromise;
        const pdfResponse = await pdfResponsePromise;
        assert.equal(page.url(), original);
        assert.notEqual(popup, page);
        assert.equal(pdfResponse.status(), 200);
        assert.equal(pdfResponse.headers()['content-type'], 'application/pdf');
        assert.deepEqual(errors, []);
        console.log(`PDF browser evidence passed; disposable fixture retained at ${directory}`);
    } finally {
        await browser.close();
        server.kill('SIGTERM');
    }
});
