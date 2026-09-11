import test from 'node:test';
import assert from 'node:assert/strict';
import { appendFile, mkdir, mkdtemp, writeFile, rm } from 'node:fs/promises';
import { createServer } from 'node:net';
import { spawn, spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const project = fileURLToPath(new URL('../../', import.meta.url));
const artifacts = new URL('../Browser/Artifacts/', import.meta.url);

async function availablePort() {
    return new Promise((resolve, reject) => {
        const server = createServer();
        server.unref();
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            server.close(() => resolve(address.port));
        });
    });
}

async function waitUntilReachable(url, timeout = 20_000) {
    const deadline = Date.now() + timeout;
    let lastError;
    while (Date.now() < deadline) {
        try {
            const response = await fetch(url, { redirect: 'manual' });
            if (response.status < 500) return response;
            lastError = new Error(`HTTP ${response.status}`);
        } catch (error) {
            lastError = error;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Application did not become reachable: ${lastError?.message}`);
}

function runPhp(args, environment) {
    const result = spawnSync('php', args, { cwd: project, env: environment, encoding: 'utf8' });
    if (result.status !== 0) throw new Error(`${args.join(' ')} failed:\n${result.stdout}\n${result.stderr}`);
    return result.stdout.trim();
}

function startApplication(port, environment) {
    const child = spawn('php', [
        '-S', `127.0.0.1:${port}`, '-t', '.',
        `${project}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
    ], { cwd: `${project}/public`, env: environment, stdio: ['ignore', 'pipe', 'pipe'] });
    for (const stream of [child.stdout, child.stderr]) {
        stream.on('data', chunk => appendFile(new URL('outage-application.log', artifacts), chunk));
    }
    return child;
}

async function stopApplication(child) {
    if (!child || child.exitCode !== null) return;
    const exited = new Promise(resolve => child.once('exit', resolve));
    child.kill('SIGTERM');
    await Promise.race([exited, new Promise((_, reject) => setTimeout(() => reject(new Error('Application did not stop')), 5_000))]);
}

async function expectState(page, state, timeout = 15_000) {
    await page.locator('[data-editor-status]').waitFor({ state: 'visible', timeout });
    await page.waitForFunction(expected => document.querySelector('[data-editor-status]')?.dataset.editorState === expected, state, { timeout });
}

test('the authenticated editor retains a staged Save through a real application outage and retry', { timeout: 90_000 }, async () => {
    await mkdir(artifacts, { recursive: true });
    await rm(new URL('outage-recovery-trace.zip', artifacts), { force: true });
    await writeFile(new URL('outage-application.log', artifacts), '');
    const temporary = await mkdtemp(join(tmpdir(), 'oceanix-editor-outage-'));
    const database = join(temporary, 'outage.sqlite');
    await writeFile(database, '');
    const port = await availablePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    const environment = {
        ...process.env, APP_ENV: 'local', APP_URL: baseUrl,
        DB_CONNECTION: 'sqlite', DB_DATABASE: database,
        SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync',
        LOCAL_AUTH_EMAIL: 'editor.outage@example.test',
    };

    runPhp(['artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'], environment);
    const fixture = JSON.parse(runPhp(['tests/Support/CourseEditor/OutageFixture.php'], environment));
    const changedTitle = 'Outage editor retained and recovered title';
    let application = startApplication(port, environment);
    let browser;
    let context;

    try {
        await waitUntilReachable(`${baseUrl}/login/${fixture.companySlug}`);
        browser = await chromium.launch({ headless: true });
        context = await browser.newContext();
        await context.tracing.start({ screenshots: true, snapshots: true, sources: false });
        const page = await context.newPage();
        await page.goto(`${baseUrl}/login/${fixture.companySlug}`);
        await page.goto(`${baseUrl}/auth/local`);
        const editorResponse = await page.goto(`${baseUrl}/courses/${fixture.courseId}/editor`);
        if (editorResponse?.status() !== 200 || !await page.locator('[data-editor-root]').count()) {
            throw new Error(`Editor did not render (${editorResponse?.status()}) at ${page.url()}: ${(await page.locator('body').innerText()).slice(0, 1000)}`);
        }
        const title = '[data-editor-field][data-field-name="course.title"] input';
        await page.locator(title).fill(changedTitle);
        await page.locator(title).press('Tab');
        await expectState(page, 'dirty');

        let intercepted = false;
        const livewireUpdatePattern = /\/livewire(?:-[^/]+)?\/update$/;
        await page.route(livewireUpdatePattern, async route => {
            if (intercepted) return route.continue();
            intercepted = true;
            await stopApplication(application);
            await route.continue();
        });
        await page.locator('[data-editor-save]').click();
        await expectState(page, 'network-error');
        assert.equal(await page.locator(title).inputValue(), changedTitle);
        assert.equal(JSON.parse(runPhp(['tests/Support/CourseEditor/OutageFixture.php', 'read', String(fixture.courseId)], environment)).title, fixture.originalTitle);

        await page.unroute(livewireUpdatePattern);
        application = startApplication(port, environment);
        await waitUntilReachable(`${baseUrl}/login/${fixture.companySlug}`);
        await page.locator('[data-editor-save]').click();
        await expectState(page, 'saved');
        await page.reload();
        assert.equal(await page.locator(title).inputValue(), changedTitle);
        assert.equal(JSON.parse(runPhp(['tests/Support/CourseEditor/OutageFixture.php', 'read', String(fixture.courseId)], environment)).title, changedTitle);

        await context.tracing.stop({ path: fileURLToPath(new URL('outage-recovery-trace.zip', artifacts)) });
    } finally {
        if (context) await context.close();
        if (browser) await browser.close();
        await stopApplication(application);
        await rm(temporary, { recursive: true, force: true });
    }
});
