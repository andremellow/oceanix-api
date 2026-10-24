<?php

use Symfony\Component\Process\Process;

it('completes PDF and image uploads in both shared editors with a disabled session tenant', function () {
    // Pest's in-process HTTP server drops multipart files; use native PHP HTTP with
    // the existing disposable PDF fixture and isolated storage instead.
    $directory = '/tmp/oceanix-pdf-qa-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $environment = ['OCEANIX_PDF_QA_DIR' => $directory, 'DB_URL' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php'];
    $fixture = new Process([PHP_BINARY, 'tests/Support/Documents/QaFixture.php'], base_path(), $environment);
    $fixture->mustRun();
    $database = new PDO('sqlite:'.$directory.'/database.sqlite');
    $database->exec('UPDATE companies SET compliance_access_enabled = 0');
    $beforeDocuments = (int) $database->query('SELECT COUNT(*) FROM lesson_documents')->fetchColumn();
    $beforeImages = (int) $database->query('SELECT COUNT(*) FROM content_images')->fetchColumn();
    $bootstrap = var_export(base_path('tests/Support/Documents/QaBootstrap.php'), true);
    file_put_contents($directory.'/router.php', <<<PHP
        <?php
        \$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (\$path !== '/' && is_file(getcwd().'/public'.\$path)) return false;
        require {$bootstrap};
        config(['filesystems.disks.public.root' => \$directory.'/images', 'filesystems.disks.public.url' => '/storage']);
        Illuminate\\Support\\Facades\\Route::get('/storage/{path}', function (string \$path) use (\$directory) {
            return response()->file(\$directory.'/images/content-images/'.basename(\$path));
        })->where('path', 'content-images/[A-Za-z0-9.]+');
        Illuminate\Support\Facades\Route::middleware('web')->get('/__upload-qa/login/{user}', function (int \$user) {
            \$actor = App\Models\User::withoutGlobalScopes()->findOrFail(\$user);
            Illuminate\Support\Facades\Auth::login(\$actor);
            session(['company_id' => \$actor->company_id]);
            return response('Disposable session ready');
        });
        \$app->handleRequest(Illuminate\Http\Request::capture());
        PHP);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = new Process([PHP_BINARY, '-S', $address, '-t', 'public', $directory.'/router.php'], base_path(), $environment);
    $server->start();
    file_put_contents($directory.'/browser.cjs', <<<'JS'
        const assert = require('node:assert/strict');
        const fs = require('node:fs');
        const {createRequire} = require('node:module');
        const {chromium} = createRequire(process.cwd() + '/package.json')('playwright');
        (async () => {
            const [base, directory] = process.argv.slice(2);
            const fixture = JSON.parse(fs.readFileSync(directory + '/manifest.json'));
            const browser = await chromium.launch({headless: true});
            try {
                const page = await browser.newPage();
                const errors = [], uploads = [];
                page.on('pageerror', e => errors.push(e.message));
                page.on('response', response => { if (response.url().includes('/platform/editor-uploads/')) uploads.push(response.status()); });
                for (let i = 0; i < 50; i++) {
                    try { await page.goto(base + '/__upload-qa/login/' + fixture.author); break; }
                    catch { await new Promise(r => setTimeout(r, 100)); }
                }
                for (const key of ['sharedCourseEditor', 'sharedModuleEditor']) {
                    for (const image of [false, true]) {
                        await page.goto(base + fixture[key]);
                        await page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable="true"]').waitFor();
                        if (image) {
                            await page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable="true"]').click();
                            await page.locator('[data-editor-action-detail="open-image-library"]').first().click();
                            await page.locator('input[wire\\:model="contentImageUpload"]').setInputFiles({name: 'transport.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')});
                        } else {
                            await page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable="true"]').click();
                            await page.locator('[data-editor-action-detail="open-pdf-modal"]').first().click();
                            await page.locator('input[wire\\:model="pdfUpload"]').setInputFiles('tests/Fixtures/lesson-guide.pdf');
                        }
                        const property = image ? 'contentImageUpload' : 'pdfUpload';
                        await page.waitForFunction(property => Boolean(Livewire.first().get(property)), property);
                        await page.getByRole('button', {name: image ? 'Upload and insert image' : 'Upload and insert link', exact: true}).click();
                        await page.waitForFunction(() => !document.querySelector('dialog[open]'));
                        await page.locator(image ? '[contenteditable="true"] img' : '[contenteditable="true"] a[href^="/lesson-documents/"]').first().waitFor();
                        await page.locator('[data-oceanix-editor-model="records.0.content_markdown"] [contenteditable="true"]').press('End');
                        await page.keyboard.type(' transport saved');
                        await page.locator('[data-editor-save]').click();
                        await page.waitForFunction(() => Livewire.first().get('saveState') === 'saved');
                        const html = await page.evaluate(() => Livewire.first().get('records.0.content_markdown'));
                        assert(html.includes(image ? '<img' : '/lesson-documents/'), JSON.stringify({key, image, html}));
                    }
                }
                assert.deepEqual(uploads, [200,200,200,200]);
                assert.deepEqual(errors, []);
                fs.writeFileSync(directory + '/browser-result.json', JSON.stringify({uploads, errors}));
            } finally { await browser.close(); }
        })().catch(error => { console.error(error); process.exitCode = 1; });
        JS);
    try {
        $browser = new Process(['node', $directory.'/browser.cjs', 'http://'.$address, $directory], base_path(), $environment);
        $browser->setTimeout(120);
        $browser->mustRun();
        $result = json_decode(file_get_contents($directory.'/browser-result.json'), true);
        expect($result['uploads'])->toBe([200, 200, 200, 200])->and($result['errors'])->toBeEmpty();
        expect((int) $database->query('SELECT COUNT(*) FROM lesson_documents')->fetchColumn())->toBe($beforeDocuments + 2)
            ->and((int) $database->query('SELECT COUNT(*) FROM content_images')->fetchColumn())->toBe($beforeImages + 2);
    } finally {
        $server->stop();
    }
});
