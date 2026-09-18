import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

test('Owner PDF library pagination, private Open, reuse caret, retained archive, revocation and narrow localization', { timeout: 120000 }, async () => {
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
        const modal = page.locator('dialog[open]').filter({ has: page.getByText('Insert PDF', { exact: true }) });
        for (const key of ['companyEditor', 'sharedCourseEditor', 'sharedModuleEditor']) {
            await page.goto(base + fixture[key]);
            const authored = page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable]');
            await authored.waitFor();
            const toolbar = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
            await authored.evaluate(el => { el.focus(); const range = document.createRange(); range.selectNodeContents(el.querySelector('strong')); getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange')); });
            await page.waitForTimeout(100);
            await toolbar.click();
            await modal.waitFor();
            const owner = key === 'companyEditor' ? 'company' : 'platform';
            const target = fixture.library[owner].at(-1);
            const row = modal.locator(`[data-pdf-row="${target.id}"]`);
            const original = await authored.innerHTML();
            const originalText = await authored.locator('p').first().textContent();
            const seen = new Set();
            for (let n = 1; n <= 4; n++) {
                await modal.getByText(`Page ${n} of 4`, { exact: true }).waitFor();
                for (const id of await modal.locator('[data-pdf-row]').evaluateAll(rows => rows.map(row => row.dataset.pdfRow))) seen.add(id);
                if (n < 4) await modal.getByRole('button', { name: 'Next', exact: true }).click();
            }
            for (const file of fixture.library[owner]) assert(seen.has(file.id));
            for (const file of fixture.library.foreign) assert(!seen.has(file.id));
            await modal.getByLabel('Search filenames', { exact: true }).fill('no such filename');
            await modal.getByRole('button', { name: 'Search', exact: true }).click();
            await modal.getByText('No PDFs match your search', { exact: true }).waitFor();
            await modal.getByRole('button', { name: 'Clear search', exact: true }).click();
            await row.waitFor();
            assert.equal(await modal.locator('input[wire\\:model="pdfLinkText"]').inputValue(), 'Before');
            const open = row.getByRole('link');
            assert.equal(await open.getAttribute('target'), '_blank');
            const url = await open.getAttribute('href');
            const popupEvent = page.waitForEvent('popup');
            await open.focus(); await page.keyboard.press('Enter');
            const popup = await popupEvent;
            const bytes = await context.request.get(url);
            assert.equal(bytes.status(), 200);
            assert.match((await bytes.body()).toString(), /^%PDF-/);
            await popup.close();
            assert.equal(await authored.innerHTML(), original);
            // Nested cancel and Escape keep the original selection token and label.
            await row.getByRole('button', { name: `Archive ${target.name}`, exact: true }).click();
            let confirm = page.locator('dialog[open]').filter({ has: page.getByRole('button', { name: 'Archive PDF', exact: true }) });
            await confirm.waitFor();
            await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancel');
            await page.keyboard.press('Enter');
            await confirm.waitFor({ state: 'hidden' });
            await page.waitForFunction(id => document.activeElement?.closest('[data-pdf-row]')?.dataset.pdfRow === id, target.id);
            await page.keyboard.press('Enter');
            await confirm.waitFor();
            await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancel');
            await page.keyboard.press('Escape');
            await confirm.waitFor({ state: 'hidden' });
            assert.equal(await modal.isVisible(), true);
            await row.getByRole('button', { name: `Reuse ${target.name}`, exact: true }).focus();
            await page.keyboard.press('Enter');
            await modal.waitFor({ state: 'hidden' });
            await page.waitForTimeout(500);
            const position = await authored.evaluate(el => { const s = getSelection(); const r = document.createRange(); r.selectNodeContents(el); r.setEnd(s.anchorNode, s.anchorOffset); const node = s.anchorNode.nodeType === Node.ELEMENT_NODE ? s.anchorNode : s.anchorNode.parentElement; return { before: r.toString(), linked: !!node.closest('a'), bold: el.querySelector(`a[href^="/lesson-documents/"] strong`)?.textContent, collapsed: s.isCollapsed, focused: document.activeElement === el }; });
            assert.deepEqual(position, { before: 'Before', linked: false, bold: 'Before', collapsed: true, focused: true });
            await page.keyboard.type(' continuation');
            assert.equal(await authored.locator('a').filter({ hasText: 'continuation' }).count(), 0);
            await page.locator('[data-editor-save]').click();
            await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
            await page.reload();
            await authored.locator(`a[href="/lesson-documents/${target.id}"]`).waitFor();
            assert.equal(await authored.locator('p').first().textContent(), originalText.replace('Before', 'Before continuation'));
            assert.equal(await authored.locator('a').filter({ hasText: ' continuation' }).count(), 0);
            // Caret-only custom/default reuse is the same asynchronous insertion path.
            for (const label of ['Custom reused guide', '']) {
                await authored.evaluate(el => { el.focus(); const range = document.createRange(); range.setStartAfter(el.querySelector('p').lastChild); range.collapse(true); getSelection().removeAllRanges(); getSelection().addRange(range); document.dispatchEvent(new Event('selectionchange')); });
                const prefix = await authored.locator('p').first().textContent();
                await page.waitForTimeout(100);
                await toolbar.click();
                await modal.locator('input[wire\\:model="pdfLinkText"]').fill(label);
                await row.getByRole('button', { name: `Reuse ${target.name}`, exact: true }).click();
                await modal.waitFor({ state: 'hidden' });
                await page.waitForTimeout(500);
                const caret = await authored.evaluate(el => { const s = getSelection(); const r = document.createRange(); r.selectNodeContents(el); r.setEnd(s.anchorNode, s.anchorOffset); const node = s.anchorNode.nodeType === Node.ELEMENT_NODE ? s.anchorNode : s.anchorNode.parentElement; return { before: r.toString(), linked: !!node.closest('a'), collapsed: s.isCollapsed, focused: document.activeElement === el }; });
                assert.deepEqual(caret, { before: prefix + (label || target.name), linked: false, collapsed: true, focused: true });
                await page.keyboard.type(' plain');
                assert.equal(await authored.locator('a').filter({ hasText: ' plain' }).count(), 0);
                assert.equal(await authored.locator('p').first().textContent(), prefix + (label || target.name) + ' plain');
                await page.locator('[data-editor-save]').click();
                await page.waitForFunction(() => document.querySelector('[data-editor-status]').dataset.editorState === 'saved');
                await page.reload();
                await authored.waitFor();
                assert.equal(await authored.locator('p').first().textContent(), prefix + (label || target.name) + ' plain');
                assert.equal(await authored.locator('a').filter({ hasText: ' plain' }).count(), 0);
                assert.equal(await authored.locator('a[href="https://example.com"]').textContent(), 'ordinary link');
            }
            // Archive retains saved content; fresh library Open fails.
            await toolbar.click();
            const beforeArchive = await authored.innerHTML();
            await row.getByRole('button', { name: `Archive ${target.name}`, exact: true }).click();
            await confirm.getByRole('button', { name: 'Archive PDF', exact: true }).click();
            await confirm.waitFor({ state: 'hidden' });
            await row.waitFor({ state: 'hidden' });
            assert.equal(await authored.innerHTML(), beforeArchive);
            assert.equal((await context.request.get(url)).status(), 404);
            await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
            // Shared course and standalone share one owner and source module: keep the next target active.
            fixture.library[owner].pop();
        }
        // Real failing operations retain the editor, retry in place, and never insert twice.
        await page.goto(base + fixture.companyEditor);
        const failureToolbar = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
        const failureEditor = page.locator('[contenteditable]').first();
        const failCommand = command => assert.equal(spawnSync('php', ['tests/Support/Documents/QaFixture.php', command], { env: environment }).status, 0);
        const failureTarget = fixture.library.company.at(-1);
        const failureRow = modal.locator(`[data-pdf-row="${failureTarget.id}"]`);
        const beforeFailure = await failureEditor.innerHTML();
        failCommand('fail-list');
        await failureToolbar.click();
        await modal.getByText('The PDF library could not be loaded. Try again.', { exact: true }).waitFor();
        failCommand('restore-list');
        await modal.getByRole('button', { name: 'Try again', exact: true }).click();
        await failureRow.waitFor();
        failCommand('fail-reuse');
        await failureRow.getByRole('button', { name: `Reuse ${failureTarget.name}`, exact: true }).click();
        await modal.getByText('The PDF could not be reused. Try again.', { exact: true }).waitFor();
        assert.equal(await failureEditor.innerHTML(), beforeFailure);
        failCommand('restore-reuse');
        await failureRow.getByRole('button', { name: `Archive ${failureTarget.name}`, exact: true }).click();
        const failedConfirm = page.locator('dialog[open]').filter({ has: page.getByRole('button', { name: 'Archive PDF', exact: true }) });
        failCommand('fail-archive');
        await failedConfirm.getByRole('button', { name: 'Archive PDF', exact: true }).click();
        await failedConfirm.getByText('The PDF could not be archived. Try again.', { exact: true }).waitFor();
        assert.equal(await failureEditor.innerHTML(), beforeFailure);
        failCommand('restore-archive');
        await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancel');
        await page.keyboard.press('Escape');
        await failedConfirm.waitFor({ state: 'hidden' });
        await failureRow.locator('[data-pdf-archive]').click();
        failCommand('fail-archive');
        await failedConfirm.getByRole('button', { name: 'Archive PDF', exact: true }).click();
        await failedConfirm.getByText('The PDF could not be archived. Try again.', { exact: true }).waitFor();
        failCommand('restore-archive');
        await failedConfirm.getByRole('button', { name: 'Archive PDF', exact: true }).click();
        await failedConfirm.waitFor({ state: 'hidden' });
        await failureRow.waitFor({ state: 'hidden' });
        assert.equal(await failureEditor.innerHTML(), beforeFailure);
        fixture.library.company.pop();
        // Hold the actual committed reuse response: duplicate controls disable, cancellation invalidates completion.
        let releaseReuse, reuseSeen;
        const heldReuse = new Promise(resolve => { releaseReuse = resolve; });
        const capturedReuse = new Promise(resolve => { reuseSeen = resolve; });
        await page.route(/\/livewire[^/]*\/update$/, async route => {
            if (!(route.request().postData() || '').includes('"method":"reusePdf"')) return route.continue();
            const response = await route.fetch(); reuseSeen(); await heldReuse; await route.fulfill({ response });
        });
        const reuseButton = modal.locator(`[data-pdf-row="${fixture.library.company.at(-1).id}"]`).getByRole('button', { name: /^Reuse / });
        await reuseButton.click();
        await capturedReuse;
        assert.equal(await reuseButton.isDisabled(), true);
        await modal.locator('[data-flux-modal-close] button').click();
        await modal.waitFor({ state: 'hidden' });
        releaseReuse();
        await page.waitForTimeout(500);
        await page.unroute(/\/livewire[^/]*\/update$/);
        assert.equal(await failureEditor.innerHTML(), beforeFailure);
        await page.goto(`${base}/__pdf-qa/login/${fixture.libraryUser}`);
        await page.goto(base + fixture.companyEditor);
        const toolbar = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
        const authored = page.locator('[contenteditable]').first();
        await toolbar.click();
        const beforeRevoked = await authored.innerHTML();
        const target = fixture.library.company.at(-1);
        const deniedUrl = await modal.locator(`[data-pdf-row="${target.id}"] a`).getAttribute('href');
        for (const permission of ['archive', 'library']) {
            for (const afterConfirmation of [false, true]) {
                const archiveRow = modal.locator(`[data-pdf-row="${target.id}"]`);
                if (afterConfirmation) await archiveRow.locator('[data-pdf-archive]').click();
                failCommand(`revoke-${permission === 'archive' ? 'archive-permission' : permission}`);
                if (afterConfirmation) await page.locator('[data-pdf-confirm]').click();
                else await archiveRow.locator('[data-pdf-archive]').click();
                await page.waitForFunction(() => !document.querySelector('dialog[open] [data-pdf-confirm]'));
                if (permission === 'library') await modal.getByText('You do not have access to this PDF library.', { exact: true }).waitFor();
                else await page.waitForFunction(() => !document.querySelector('[data-pdf-archive]'));
                assert.equal(await authored.innerHTML(), beforeRevoked);
                failCommand(`restore-${permission === 'archive' ? 'archive-permission' : permission}`);
                if (permission === 'library') await modal.getByRole('button', { name: 'Try again', exact: true }).click();
                else { await modal.getByLabel('Search filenames', { exact: true }).fill(''); await modal.getByRole('button', { name: 'Search', exact: true }).click(); }
                await archiveRow.locator('[data-pdf-archive]').waitFor();
            }
        }
        assert.equal(spawnSync('php', ['tests/Support/Documents/QaFixture.php', 'revoke-library'], { env: environment }).status, 0);
        await modal.getByLabel('Search filenames', { exact: true }).fill('guide');
        await modal.getByRole('button', { name: 'Search', exact: true }).click();
        await modal.getByText('You do not have access to this PDF library.', { exact: true }).waitFor();
        await page.waitForFunction(() => document.querySelectorAll('[data-pdf-row]').length === 0);
        assert.equal(await modal.locator('[data-pdf-row]').count(), 0);
        assert.equal(await authored.innerHTML(), beforeRevoked);
        assert.equal((await context.request.get(deniedUrl)).status(), 403);
        assert.equal(spawnSync('php', ['tests/Support/Documents/QaFixture.php', 'restore-library'], { env: environment }).status, 0);
        await modal.getByRole('button', { name: 'Try again', exact: true }).click();
        await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.locator('form[action$="/locale/pt_BR"] button').click();
        await page.setViewportSize({ width: 390, height: 844 });
        await toolbar.focus(); await toolbar.press('Enter');
        const portuguese = page.locator('dialog[open]');
        await portuguese.getByText('Biblioteca de PDFs', { exact: true }).waitFor();
        const reuse = portuguese.getByRole('button', { name: /^Reutilizar / }).last();
        await reuse.scrollIntoViewIfNeeded();
        const rect = await reuse.boundingBox();
        assert(rect.x >= 0 && rect.x + rect.width <= 390);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.equal(await reuse.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(27, 35, 40)');
        const archive = portuguese.locator('[data-pdf-archive]').first();
        assert.equal(await archive.evaluate(el => getComputedStyle(el).color), 'rgb(198, 66, 66)');
        await archive.focus(); await page.keyboard.press('Enter');
        const ptConfirm = page.locator('dialog[open]').filter({ has: page.locator('[data-pdf-confirm]') });
        await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancelar');
        const ptAction = ptConfirm.locator('[data-pdf-confirm]');
        await ptAction.scrollIntoViewIfNeeded();
        const confirmRect = await ptAction.boundingBox();
        assert(confirmRect.x >= 0 && confirmRect.x + confirmRect.width <= 390 && confirmRect.y >= 0 && confirmRect.y + confirmRect.height <= 844);
        assert.equal(await ptAction.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(198, 66, 66)');
        await page.keyboard.press('Escape'); await ptConfirm.waitFor({ state: 'hidden' });
        failCommand('fail-reuse');
        await reuse.focus(); await page.keyboard.press('Enter');
        await portuguese.getByText('Não foi possível reutilizar o PDF. Tente novamente.', { exact: true }).waitFor();
        assert.equal(await page.getByText('Reuse Pdf', { exact: false }).count(), 0);
        failCommand('restore-reuse');
        failCommand('revoke-library');
        await portuguese.locator('[data-pdf-archive]').first().focus(); await page.keyboard.press('Enter');
        await portuguese.getByRole('button', { name: 'Tentar novamente', exact: true }).waitFor();
        assert.equal(await portuguese.locator('[data-pdf-row]').count(), 0);
        failCommand('restore-library');
        await portuguese.screenshot({ path: `${directory}/library-mobile-pt.png` });
        assert.deepEqual(errors, []);
        console.log(`PDF library browser evidence passed; fixture and screenshot retained at ${directory}`);
    } finally {
        await browser.close(); server.kill('SIGTERM');
    }
});

test('PDF library interrupted transport, retained selection, keyboard progress and empty owner', { timeout: 120000 }, async () => {
    const directory = await mkdtemp('/tmp/oceanix-pdf-qa-');
    const environment = { ...process.env, OCEANIX_PDF_QA_DIR: directory };
    const command = name => { const result = spawnSync('php', ['tests/Support/Documents/QaFixture.php', ...(name ? [name] : [])], { env: environment, encoding: 'utf8' }); assert.equal(result.status, 0, result.stderr); };
    command();
    const fixture = JSON.parse(await readFile(`${directory}/manifest.json`, 'utf8'));
    const port = await new Promise(resolve => { const server = createServer(); server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); }); });
    const base = `http://127.0.0.1:${port}`;
    const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/Support/Documents/QaRouter.php'], { env: environment, stdio: 'ignore' });
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (let attempt = 0; attempt < 50; attempt++) { try { await page.goto(`${base}/__pdf-qa/login/${fixture.emptyAuthor}`); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); } }
        await page.goto(base + fixture.emptyEditor);
        const toolbar = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
        await toolbar.focus(); await page.keyboard.press('Enter');
        await page.getByText('No PDFs in this library yet', { exact: true }).waitFor();
        await page.goto(`${base}/__pdf-qa/login/${fixture.libraryUser}`);
        await page.goto(base + fixture.companyEditor);
        const authored = page.locator('[contenteditable]').first();
        await authored.evaluate(el => { el.focus(); const r = document.createRange(); r.selectNodeContents(el.querySelector('strong')); getSelection().removeAllRanges(); getSelection().addRange(r); document.dispatchEvent(new Event('selectionchange')); });
        await page.waitForTimeout(100);
        await toolbar.click();
        const modal = page.locator('dialog[open]').filter({ has: page.getByText('Insert PDF', { exact: true }) });
        const before = await authored.innerHTML();
        const label = modal.locator('input[wire\\:model="pdfLinkText"]');
        const search = modal.getByLabel('Search filenames', { exact: true });
        const target = fixture.library.company.at(-1);
        const row = modal.locator(`[data-pdf-row="${target.id}"]`);
        await row.waitFor();
        const interrupt = async method => {
            await page.route(/\/livewire[^/]*\/update$/, async route => {
                if (!(route.request().postData() || '').includes(`"method":"${method}"`)) return route.continue();
                await route.abort('failed');
            });
        };
        const release = () => page.unroute(/\/livewire[^/]*\/update$/);
        await search.fill('reusable');
        await interrupt('searchPdfs');
        await search.press('Enter');
        await modal.getByText('The PDF request was interrupted. Results may be out of date. Your text and selection are preserved.', { exact: true }).waitFor();
        assert.equal(await modal.locator('[data-pdf-row]').count(), 20);
        assert.equal(await search.inputValue(), 'reusable');
        assert.equal(await label.inputValue(), 'Before');
        assert.equal(await authored.innerHTML(), before);
        await release();
        await modal.getByRole('button', { name: 'Try again', exact: true }).focus(); await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelectorAll('[data-pdf-row]').length === 1);
        await modal.getByRole('button', { name: 'Clear search', exact: true }).focus(); await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelectorAll('[data-pdf-row]').length === 20);
        // Hold an actual page request: retain old rows and expose busy/disabled navigation.
        let releasePage, pageSeen;
        const held = new Promise(resolve => { releasePage = resolve; });
        const seen = new Promise(resolve => { pageSeen = resolve; });
        await page.route(/\/livewire[^/]*\/update$/, async route => { if (!(route.request().postData() || '').includes('"method":"loadPdfLibrary"')) return route.continue(); pageSeen(); await held; await route.continue(); });
        await modal.getByRole('button', { name: 'Next', exact: true }).focus(); await page.keyboard.press('Enter'); await seen;
        await modal.getByText('Loading PDFs…', { exact: true }).waitFor();
        assert.equal(await modal.locator('[data-pdf-row]').count(), 20);
        assert.equal(await modal.getByRole('button', { name: 'Next', exact: true }).isDisabled(), true);
        assert.equal(await modal.locator('ul').getAttribute('aria-busy'), 'true');
        releasePage(); await modal.getByText('Page 2 of 4', { exact: true }).waitFor(); await release();
        await modal.getByRole('button', { name: 'Previous', exact: true }).focus(); await page.keyboard.press('Enter'); await row.waitFor();
        // Archive response can be lost after commit; retry remains idempotent.
        await row.locator('[data-pdf-archive]').focus(); await page.keyboard.press('Enter');
        const confirm = page.locator('dialog[open]').filter({ has: page.locator('[data-pdf-confirm]') });
        await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancel');
        let archiveSeen, releaseArchive;
        const archiveHeld = new Promise(resolve => { releaseArchive = resolve; });
        const archiveStarted = new Promise(resolve => { archiveSeen = resolve; });
        await page.route(/\/livewire[^/]*\/update$/, async route => { if (!(route.request().postData() || '').includes('"method":"archivePdf"')) return route.continue(); await route.fetch(); archiveSeen(); await archiveHeld; await route.abort('failed'); });
        await confirm.locator('[data-pdf-confirm]').focus(); await page.keyboard.press('Enter'); await archiveStarted;
        await confirm.getByText('Archiving PDF…', { exact: true }).waitFor();
        assert.equal(await confirm.getByRole('button', { name: 'Cancel', exact: true }).isDisabled(), true);
        releaseArchive();
        await confirm.getByText('The archive response was interrupted. Try Archive PDF again to confirm the result. Existing links still work.', { exact: true }).waitFor();
        await release();
        await page.waitForFunction(() => document.activeElement?.textContent.trim() === 'Cancel');
        await confirm.locator('[data-pdf-confirm]').focus(); await page.keyboard.press('Enter');
        await confirm.waitFor({ state: 'hidden' }); await row.waitFor({ state: 'hidden' });
        const archival = spawnSync('php', ['tests/Support/Documents/QaFixture.php', 'archive-count', target.id], { env: environment, encoding: 'utf8' });
        assert.equal(archival.status, 0, archival.stderr); assert.equal(archival.stdout, '1');
        assert.equal(await authored.innerHTML(), before);
        await page.waitForFunction(() => !!document.activeElement?.closest('[data-pdf-row]'));
        // The same captured selection survives all requests, and interrupted reuse remains usable.
        const next = fixture.library.company.at(-2);
        await interrupt('reusePdf');
        await modal.locator(`[data-pdf-row="${next.id}"] [data-editor-action-detail="pdf-reuse"]`).click();
        await modal.getByText('The PDF request was interrupted. Results may be out of date. Your text and selection are preserved.', { exact: true }).waitFor();
        assert.equal(await authored.innerHTML(), before); assert.equal(await label.inputValue(), 'Before');
        await release(); await modal.getByRole('button', { name: 'Try again', exact: true }).click();
        await modal.waitFor({ state: 'hidden' }); await page.waitForTimeout(500);
        assert.equal(await authored.locator(`a[href="/lesson-documents/${next.id}"] strong`).textContent(), 'Before');
        await page.keyboard.type(' recovered');
        assert.equal(await authored.locator('a').filter({ hasText: ' recovered' }).count(), 0);
        assert.equal(await authored.locator('p').first().textContent(), 'Before recovered Read guide after ordinary link');
        assert.deepEqual(errors, []);
        console.log(`PDF transport evidence passed; disposable fixture retained at ${directory}`);
    } finally { await browser.close(); server.kill('SIGTERM'); }
});

test('PDF initial opening transport failure preserves unsaved editing, Save and selected-text retry', { timeout: 120000 }, async () => {
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
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (let attempt = 0; attempt < 50; attempt++) { try { await page.goto(`${base}/__pdf-qa/login/${fixture.author}`); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); } }
        for (const key of ['companyEditor', 'sharedCourseEditor', 'sharedModuleEditor']) {
            await page.goto(base + fixture[key]);
            const authored = page.locator('[contenteditable]').first();
            const toolbar = page.locator('[data-editor-action-detail="open-pdf-modal"]').first();
            const save = page.getByRole('button', { name: 'Save changes', exact: true });
            const modal = page.locator('dialog[open]').filter({ has: page.getByText('Insert PDF', { exact: true }) });
            await authored.evaluate(el => { el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(r); document.dispatchEvent(new Event('selectionchange')); });
            await page.keyboard.type(`-unsaved-${key}`);
            const before = await authored.innerHTML();
            assert.equal(await save.isEnabled(), true);
            // Positive control: ordinary opening and cancellation preserve the draft.
            await toolbar.click(); await modal.waitFor();
            await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
            await modal.waitFor({ state: 'hidden' });
            assert.equal(await authored.innerHTML(), before);
            await authored.evaluate(el => { el.focus(); const r = document.createRange(); r.selectNodeContents(el.querySelector('strong')); getSelection().removeAllRanges(); getSelection().addRange(r); document.dispatchEvent(new Event('selectionchange')); });
            await page.waitForTimeout(100);
            let intercepted = [];
            let interruptedCount = 0;
            await page.route(/\/livewire[^/]*\/update$/, async route => {
                const methods = JSON.parse(route.request().postData()).components.flatMap(component => component.calls.map(call => call.method));
                if (!methods.includes('openPdfModal')) return route.continue();
                intercepted = methods;
                interruptedCount++;
                // Also exercise a lost response after the server handled opening.
                if (key === 'sharedModuleEditor') await route.fetch();
                await route.abort('failed');
            });
            await toolbar.click();
            await page.waitForTimeout(500);
            assert.deepEqual(intercepted, ['openPdfModal']);
            assert.equal(await authored.innerHTML(), before);
            assert.equal(await toolbar.isEnabled(), true, `${key}: opening failure must release Insert PDF`);
            assert.equal(await save.isEnabled(), true, `${key}: opening failure must retain Save`);
            const failure = page.locator('[data-pdf-open-failure]');
            await failure.getByText('The PDF request was interrupted. Results may be out of date. Your text and selection are preserved.', { exact: true }).waitFor();
            await failure.getByRole('button', { name: 'Try again', exact: true }).click();
            await page.waitForTimeout(500);
            assert.equal(interruptedCount, 2);
            await failure.waitFor();
            assert.equal(await save.isEnabled(), true);
            assert.equal(await authored.innerHTML(), before);
            await page.unroute(/\/livewire[^/]*\/update$/);
            await failure.getByRole('button', { name: 'Try again', exact: true }).focus(); await page.keyboard.press('Enter');
            await modal.waitFor();
            assert.equal(await modal.locator('input[wire\\:model="pdfLinkText"]').inputValue(), 'Before');
            assert.equal(await authored.innerHTML(), before);
            const target = fixture.library[key === 'companyEditor' ? 'company' : 'platform'].at(-1);
            await modal.locator(`[data-pdf-row="${target.id}"] [data-editor-action-detail="pdf-reuse"]`).click();
            await modal.waitFor({ state: 'hidden' }); await page.waitForTimeout(500);
            assert.equal(await authored.locator(`a[href="/lesson-documents/${target.id}"] strong`).textContent(), 'Before');
            await page.keyboard.type(' recovered');
            assert.equal(await authored.locator('a').filter({ hasText: ' recovered' }).count(), 0);
            const recovered = await authored.innerHTML();
            await save.click(); await page.getByRole('region', { name: 'Draft save actions' }).getByText('All changes saved', { exact: true }).waitFor();
            await page.reload();
            await authored.waitFor();
            assert.equal(await authored.innerHTML(), recovered);
            // Saving remains available even when the user chooses not to retry opening.
            await page.route(/\/livewire[^/]*\/update$/, route => (route.request().postData() || '').includes('"method":"openPdfModal"') ? route.abort('failed') : route.continue());
            await toolbar.click(); await failure.waitFor();
            await authored.evaluate(el => { el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(r); document.dispatchEvent(new Event('selectionchange')); });
            await page.keyboard.type(`-continue-${key}`);
            const continued = await authored.innerHTML();
            await save.click(); await page.getByRole('region', { name: 'Draft save actions' }).getByText('All changes saved', { exact: true }).waitFor();
            await page.unroute(/\/livewire[^/]*\/update$/);
            await page.reload(); await authored.waitFor();
            assert.equal(await authored.innerHTML(), continued);
        }
        assert.deepEqual(errors, []);
        console.log(`PDF initial opening recovery passed; disposable fixture retained at ${directory}`);
    } finally { await browser.close(); server.kill('SIGTERM'); }
});

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
