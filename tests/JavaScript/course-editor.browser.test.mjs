import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

// Run explicitly against a disposable local fixture with three distinct lessons,
// each containing a question and two options (never a production tenant):
// EDITOR_TEST_URL=http://127.0.0.1:18891 EDITOR_TEST_TENANT=oceanix-demo \
// EDITOR_TEST_COURSE=5 PLAYWRIGHT_MODULE=/path/to/playwright \
// node --test tests/JavaScript/course-editor.browser.test.mjs
const base = process.env.EDITOR_TEST_URL;
const course = process.env.EDITOR_TEST_COURSE;
const tenant = process.env.EDITOR_TEST_TENANT;
const require = createRequire(import.meta.url);
const titleSelector = 'input[wire\\:model\\.live\\.blur$=".title"]';
const answerSelector = 'input[wire\\:model\\.live\\.debounce\\.500ms$=".text"]';

test('installed browser runtime preserves expanded record bindings and the confirmation boundary', {
    skip: !base || !course || !tenant ? 'Requires an explicitly selected disposable local fixture' : false,
    timeout: 120000,
}, async () => {
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname));
    const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const token = Date.now();

    async function exercise(removeIdentityFix) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
            if (removeIdentityFix) {
                // Reproduce the old keys only in responses to this browser. The
                // application's files and other browser sessions stay untouched.
                const oldKeys = html => html
                    .replace(/\s+wire:key="lesson-fields-[^"]+"/g, '')
                    .replace(/wire:key="(question|option)-(\d+)-[^"]+"/g, 'wire:key="$1-$2"');
                await page.route('**/*', async route => {
                    const request = route.request();
                    if (request.resourceType() !== 'document' && !(request.method() === 'POST' && request.url().includes('livewire'))) return route.continue();
                    const response = await route.fetch();
                    let body = await response.text();
                    if ((response.headers()['content-type'] || '').includes('json')) {
                        const payload = JSON.parse(body);
                        for (const component of payload.components || []) {
                            if (component.effects?.html) component.effects.html = oldKeys(component.effects.html);
                        }
                        body = JSON.stringify(payload);
                    } else body = oldKeys(body);
                    await route.fulfill({ response, body });
                });
            }
            await page.goto(`${base}/c/${tenant}`);
            await page.goto(`${base}/auth/local`);
            await page.goto(`${base}/courses/${course}/editor`);
            const rows = page.locator('[data-lesson-drop-id]');
            await rows.first().waitFor();
            const ids = await rows.evaluateAll(elements => elements.map(el => el.dataset.lessonDropId));
            assert.equal(ids.length, 3, 'The disposable fixture must have exactly three populated lessons');
            const row = id => page.locator(`[data-lesson-drop-id="${id}"]`);
            async function expand() {
                for (const id of ids) {
                    if (!await row(id).locator(titleSelector).count()) await row(id).locator('button[wire\\:click^="toggleLesson"]').first().click();
                    await row(id).locator(titleSelector).waitFor();
                }
            }
            const saved = () => page.waitForFunction(() => document.querySelector('.admin-page[x-data]')._x_dataStack[0].state === 'saved');
            const state = () => page.locator('.admin-page[x-data]').evaluate(el => {
                const { state, dirty, revision, structuralPending } = el._x_dataStack[0];
                return { state, dirty, revision, structuralPending };
            });
            const snapshot = () => rows.evaluateAll(elements => Object.fromEntries(elements.map(el => [el.dataset.lessonDropId,
                [...el.querySelectorAll('[data-editor-authored]')].filter(control => control.getAttributeNames().some(name => name.startsWith('wire:model'))).map(control => ({
                    question: control.closest('[data-editor-question-id]')?.dataset.editorQuestionId,
                    option: control.closest('[data-editor-option-id]')?.dataset.editorOptionId,
                    path: control.getAttribute(control.getAttributeNames().find(name => name.startsWith('wire:model'))).replace(/^lessons\.\d+\./, ''),
                    value: control.matches('ui-checkbox') ? control.getAttribute('aria-checked') : control.type === 'checkbox' ? control.checked : control.value,
                })),
            ])));
            await expand();
            const initial = await snapshot();
            assert.equal(new Set(Object.values(initial).map(fields => fields.find(field => field.path === 'title').value)).size, 3);
            assert.equal(new Set(Object.values(initial).map(fields => fields.find(field => field.path === 'questions.0.options.0.text').value)).size, 3);
            const moved = ids[1];
            for (const width of [1440, 320]) {
                await page.setViewportSize({ width, height: 1000 });
                for (const direction of ['up', 'down']) {
                    await page.locator(`[data-lesson-focus-id="${moved}"][data-lesson-focus-direction="${direction}"]`).click();
                    await saved();
                    assert.deepEqual(await snapshot(), initial, 'displayed controls must retain their stable record values after reorder');
                    await page.waitForFunction(id => document.activeElement.dataset.lessonFocusId === id, moved);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                }
            }
            // Leave the authored record at a different index for the write test.
            await page.locator(`[data-lesson-focus-id="${moved}"][data-lesson-focus-direction="up"]`).click();
            await saved();
            assert.deepEqual(await snapshot(), initial, 'displayed controls must retain their stable record values after reorder');
            assert.equal(await rows.first().getAttribute('data-lesson-drop-id'), moved);
            const newTitle = `Moved lesson ${token}`;
            const newAnswer = `Moved answer ${token}`;
            await row(moved).locator(titleSelector).fill(newTitle);
            await row(moved).locator(titleSelector).press('Tab');
            await saved();
            await row(moved).locator(answerSelector).first().fill(newAnswer);
            await saved();
            await row(moved).locator(answerSelector).first().press('Tab');
            await page.reload();
            await expand();
            const expected = structuredClone(initial);
            expected[moved].find(field => field.path === 'title').value = newTitle;
            expected[moved].find(field => field.path === 'questions.0.options.0.text').value = newAnswer;
            assert.deepEqual(await snapshot(), expected, 'reload must change only the intended lesson and option identities');

            const before = await state();
            let mutations = 0;
            let release;
            const held = new Promise(resolve => { release = resolve; });
            await page.route('**/*', async route => {
                if (route.request().method() === 'POST' && route.request().postData()?.includes('removeLesson')) {
                    mutations++;
                    await held;
                    return route.abort(); // Positive control stops before deleting fixture data.
                }
                return route.continue();
            });
            const remove = row(moved).locator('button[wire\\:click^="removeLesson"]');
            page.once('dialog', dialog => dialog.dismiss());
            await remove.click();
            await page.waitForTimeout(500);
            assert.equal(mutations, 0, 'declined confirmation must not dispatch');
            assert.deepEqual(await state(), before, 'declined confirmation must not create pending or dirty state');
            page.once('dialog', dialog => dialog.accept());
            await remove.click();
            await page.waitForFunction(() => document.querySelector('.admin-page[x-data]')._x_dataStack[0].structuralPending);
            for (let attempt = 0; mutations === 0 && attempt < 50; attempt++) await page.waitForTimeout(20);
            assert.equal(mutations, 1, 'accepted confirmation must dispatch the mutation');
            assert.equal((await state()).state, 'saving');
            release();
            assert.deepEqual(errors, []);
        } finally {
            await page.close();
        }
    }

    try {
        await assert.rejects(exercise(true), /displayed controls must retain their stable record values after reorder/,
            'Removing the positional identity fix must make this browser regression fail');
        await exercise(false);
    } finally {
        await browser.close();
    }
});
