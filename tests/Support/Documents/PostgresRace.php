<?php

// Standalone, bounded real-Action probe. Never loaded by production or PHPUnit.
use App\Actions\Courses\SaveCompanyCourseEditorDraft;
use App\Actions\Documents\ArchiveLessonDocument;
use App\Actions\Documents\ReuseLessonDocument;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'PDF race probe failed: '.$exception::class."\n");
    exit(1);
});
$database = 'oceanix_pdf_library_qa_20260916_085729b9';
$directory = getenv('OCEANIX_PDF_QA_DIR');
if (! is_string($directory) || ! preg_match('~^/(?:private/)?tmp/oceanix-pdf-qa-[A-Za-z0-9]+$~D', $directory) || ! is_dir($directory)) {
    throw new RuntimeException('Use a disposable PDF QA directory.');
}
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array',
    'filesystems.disks.lesson_documents.root' => $directory.'/documents']);
if (! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Only the local disposable PostgreSQL service is allowed.');
}
DB::purge('pgsql');
if (DB::selectOne('select current_database() as name')->name !== $database) {
    throw new RuntimeException('Disposable database identity mismatch.');
}
DB::statement("SET lock_timeout = '12s'");
DB::statement("SET statement_timeout = '20s'");
Http::preventStrayRequests();
Http::fake();

if (($argv[1] ?? '') === 'child') {
    $data = json_decode(file_get_contents($directory.'/race.json'), true, flags: JSON_THROW_ON_ERROR);
    app(TenantContext::class)->set(Company::findOrFail($data['company']));
    $actor = User::findOrFail($data['actor']);
    $operation = $argv[2];
    $held = ($argv[3] ?? '') === 'hold';
    echo 'PID '.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
    flush();
    $signalled = false;
    DB::listen(function ($query) use ($held, &$signalled): void {
        if (! $signalled && str_contains($query->sql, '"lesson_documents"') && str_contains($query->sql, 'for update')) {
            $signalled = true;
            echo "LOCKED\n";
            flush();
            if ($held) {
                stream_set_timeout(STDIN, 15);
                if (trim((string) fgets(STDIN)) !== 'release') {
                    throw new RuntimeException('Missing bounded release signal.');
                }
            }
        }
    });
    try {
        if ($operation === 'archive') {
            app(ArchiveLessonDocument::class)->handle($data['document'], $actor);
        } else {
            app(ReuseLessonDocument::class)->handle($data['document'], 'company-course', $data['course'], $data['lesson'], $actor, $data['revision']);
        }
        echo "RESULT committed\n";
    } catch (ValidationException $exception) {
        echo 'RESULT rejected '.collect($exception->errors())->flatten()->first()."\n";
    }
    exit;
}

// Additive migrations only, after exact database identity was proved; no schema reset.
Artisan::call('migrate', ['--force' => true]);
function raceLine($stream): string
{
    $read = [$stream];
    $write = $except = [];
    if (stream_select($read, $write, $except, 18) !== 1) {
        throw new RuntimeException('Race process exceeded bounded wait.');
    }

    return trim((string) fgets($stream));
}
function raceProcess(string $operation, bool $held): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'child', $operation, $held ? 'hold' : 'run'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not create race process.');
    }

    return [$process, $pipes];
}
foreach (['archive', 'reuse'] as $first) {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $role = Role::factory()->create(['key' => 'admin']);
    $actor->roles()->attach($role);
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id, 'content_markdown' => '<p>Before</p>']);
    $document = LessonDocument::create(['public_id' => (string) Str::uuid(), 'company_id' => $company->id, 'is_shared' => false, 'name' => 'Race guide.pdf', 'disk' => 'lesson_documents', 'path' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100]);
    Storage::disk('lesson_documents')->put($document->path, file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')));
    $metadata = $document->fresh()->getAttributes();
    $hash = hash('sha256', Storage::disk($document->disk)->get($document->path));
    $snapshot = app(EditorSnapshotBuilder::class)->forCompanyCourse($course->id, $actor);
    file_put_contents($directory.'/race.json', json_encode(['company' => $company->id, 'actor' => $actor->id, 'document' => $document->public_id, 'course' => $course->id, 'lesson' => $lesson->id, 'revision' => $snapshot->revisions['root']], JSON_THROW_ON_ERROR));
    [$p1, $io1] = raceProcess($first, true);
    $pid1 = (int) substr(raceLine($io1[1]), 4);
    if (raceLine($io1[1]) !== 'LOCKED') {
        throw new RuntimeException('First Action did not acquire document lock.');
    }
    [$p2, $io2] = raceProcess($first === 'archive' ? 'reuse' : 'archive', false);
    $pid2 = (int) substr(raceLine($io2[1]), 4);
    $deadline = microtime(true) + 8;
    do {
        $wait = DB::selectOne('select wait_event_type from pg_stat_activity where pid = ?', [$pid2]);
        if (($wait->wait_event_type ?? null) === 'Lock') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    if (($wait->wait_event_type ?? null) !== 'Lock' || $pid1 === $pid2) {
        throw new RuntimeException('Independent opposing Action did not demonstrably wait on the lock.');
    }
    fwrite($io1[0], "release\n");
    $result1 = raceLine($io1[1]);
    $locked2 = raceLine($io2[1]);
    $result2 = raceLine($io2[1]);
    foreach ([[$p1, $io1], [$p2, $io2]] as [$process, $pipes]) {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Action process failed.');
        }
    }
    if ($result1 !== 'RESULT committed' || $locked2 !== 'LOCKED') {
        throw new RuntimeException('Unexpected lock or commit result.');
    }
    if ($first === 'archive') {
        if (! str_starts_with($result2, 'RESULT rejected') || $lesson->documents()->exists()) {
            throw new RuntimeException('Archive-first unexpectedly attached a document.');
        }
    } else {
        if ($result2 !== 'RESULT committed' || $lesson->documents()->count() !== 1) {
            throw new RuntimeException('Reuse-first lost its attachment.');
        }
        $records = $snapshot->records;
        $records[0]['content_markdown'] = '<p>Before <a href="/lesson-documents/'.$document->public_id.'">Retained guide</a></p>';
        app(SaveCompanyCourseEditorDraft::class)->handle($course->id, $version->id, $actor, new EditorSaveCommand($snapshot->course, $snapshot->version, $records, $snapshot->revisions, 1));
        if (! str_contains($lesson->fresh()->content_markdown, $document->public_id)) {
            throw new RuntimeException('Actual pending draft Save did not retain archived attachment.');
        }
    }
    $archive = $document->archive()->sole()->getAttributes();
    app(ArchiveLessonDocument::class)->handle($document->public_id, $actor);
    if ($archive !== $document->archive()->sole()->getAttributes() || $metadata !== $document->fresh()->getAttributes() || $hash !== hash('sha256', Storage::disk($document->disk)->get($document->path))) {
        throw new RuntimeException('Retained evidence changed.');
    }
    echo ucfirst($first).'-first PASS: separate backend processes; opposing Action observed waiting; retained evidence verified'.($first === 'reuse' ? '; actual pending draft Save passed' : '; no new attachment').".\n";
}
