<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// This harness is never loaded by the application. It only boots a disposable local QA database.
$directory = getenv('OCEANIX_PDF_QA_DIR');
if (! is_string($directory) || ! preg_match('~^/(?:private/)?tmp/oceanix-pdf-qa-[A-Za-z0-9]+$~D', $directory) || ! is_dir($directory)) {
    throw new RuntimeException('Create a disposable /tmp/oceanix-pdf-qa-* directory first.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.lesson_documents.root' => $directory.'/documents', 'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $directory.'/uploads', 'throw' => true], 'session.files' => $directory.'/sessions', 'livewire.temporary_file_upload.directory' => 'livewire-tmp']);
if (is_file($directory.'/fail-storage')) {
    config(['filesystems.disks.lesson_documents.root' => $directory.'/database.sqlite']);
}
Http::preventStrayRequests();
Http::fake();
// Failure injection is confined to this explicitly selected disposable harness.
DB::connection()->beforeExecuting(function (string $query) use ($directory): void {
    foreach (['list' => 'from "lesson_documents"', 'archive' => 'insert into "lesson_document_archives"', 'reuse' => 'insert into "lesson_document"'] as $operation => $fragment) {
        if (is_file($directory.'/fail-'.$operation) && str_contains(strtolower($query), $fragment)) {
            throw new RuntimeException('Disposable PDF operation failure.');
        }
    }
});
