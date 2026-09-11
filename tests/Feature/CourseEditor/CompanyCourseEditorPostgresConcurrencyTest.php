<?php

use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('serializes competing PostgreSQL whole-graph saves so one stale writer is rejected without a partial merge', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }

    $actor = userWithPermissions([Permission::CoursesUpdate]);
    $course = Course::factory()->draft()->create(['title' => 'Concurrent original']);
    $version = CourseVersion::factory()->create(['course_id' => $course, 'title' => $course->title, 'description' => $course->description]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'title' => 'Stable lesson', 'content_markdown' => '', 'position' => 1]);
    $question = Question::factory()->create(['lesson_id' => $lesson, 'prompt' => 'Stable question', 'position' => 1]);
    QuestionOption::factory()->correct()->create(['question_id' => $question, 'text' => 'Correct', 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question, 'text' => 'Incorrect', 'position' => 2]);
    $snapshot = app(EditorSnapshotBuilder::class)->forCompanyCourse($course->id, $actor);
    $payload = base64_encode(json_encode([
        'course' => $snapshot->course,
        'version' => $snapshot->version,
        'records' => $snapshot->records,
        'revisions' => $snapshot->revisions,
    ], JSON_THROW_ON_ERROR));
    $barrier = 9061101;

    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $label): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_company_save_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $course = App\Models\Course::query()->findOrFail(%d);
    app(App\Tenancy\TenantContext::class)->set($course->company()->firstOrFail());
    $version = App\Models\CourseVersion::query()->findOrFail(%d);
    $actor = App\Models\User::query()->findOrFail(%d);
    $data = json_decode(base64_decode('%s'), true, flags: JSON_THROW_ON_ERROR);
    $data['course']['title'] = 'Concurrent %s winner';
    $data['records'][0]['description'] = 'Writer %s description';
    $command = new App\Services\CourseEditor\EditorSaveCommand($data['course'], $data['version'], $data['records'], $data['revisions'], 1, []);
    app(App\Actions\Courses\SaveCompanyCourseEditorDraft::class)->handle($course, $version, $actor, $command);
    echo 'RESULT:saved:%s';
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'RESULT:stale:%s';
}
PHP, $label, $barrier, $course->id, $version->id, $actor->id, $payload, $label, $label, $label, $label);

    $first = new Process([PHP_BINARY, '-r', $script('alpha')], base_path(), timeout: 30);
    $second = new Process([PHP_BINARY, '-r', $script('bravo')], base_path(), timeout: 30);
    $first->start();
    $second->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_company_save_alpha','oceanix_company_save_bravo') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $first->wait();
    $second->wait();

    DB::purge();
    DB::reconnect();
    $outputs = [$first->getOutput(), $second->getOutput()];
    sort($outputs);
    $saved = collect($outputs)->first(fn (string $output): bool => str_starts_with($output, 'RESULT:saved:'));
    $winner = str_ends_with((string) $saved, 'alpha') ? 'alpha' : 'bravo';
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:saved:'))->count())->toBe(1)
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:stale:'))->count())->toBe(1)
        ->and($course->fresh()->title)->toBe("Concurrent {$winner} winner")
        ->and($lesson->fresh()->description)->toBe("Writer {$winner} description")
        ->and($question->fresh()->prompt)->toBe('Stable question');

    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('users')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL competing whole-graph Save gate.');

it('serializes competing PostgreSQL immediate structural writers against one frozen revision', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }

    $actor = userWithPermissions([Permission::CoursesUpdate]);
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $expected = app(EditorRevision::class)->forCompanyCourse($course, $version);
    $barrier = 9061102;

    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $label): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_structure_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $course = App\Models\Course::query()->findOrFail(%d);
    app(App\Tenancy\TenantContext::class)->set($course->company()->firstOrFail());
    app(App\Actions\Courses\AddDirectCourseLesson::class)->handle(
        App\Models\CourseVersion::query()->findOrFail(%d),
        App\Models\User::query()->findOrFail(%d),
        '%s'
    );
    echo 'RESULT:saved:%s';
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'RESULT:stale:%s';
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).':'.$e->getMessage());
    exit(2);
}
PHP, $label, $barrier, $course->id, $version->id, $actor->id, $expected, $label, $label);

    $first = new Process([PHP_BINARY, '-r', $script('alpha')], base_path(), timeout: 30);
    $second = new Process([PHP_BINARY, '-r', $script('bravo')], base_path(), timeout: 30);
    $first->start();
    $second->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_structure_alpha','oceanix_structure_bravo') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $first->wait();
    $second->wait();
    DB::purge();
    DB::reconnect();

    $outputs = [$first->getOutput(), $second->getOutput()];
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:saved:'))->count())->toBe(1)
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:stale:'))->count())->toBe(1)
        ->and($version->fresh()->lessons()->count())->toBe(1);

    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('users')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL immediate structure concurrency gate.');

it('serializes competing PostgreSQL immediate media writers against one frozen revision', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }

    $actor = userWithPermissions([Permission::CoursesUpdate]);
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $video = Video::factory()->create(['lesson_id' => $lesson, 'is_current' => true]);
    $expected = app(EditorRevision::class)->forCompanyCourse($course, $version);
    $barrier = 9061103;

    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $label): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_media_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $course = App\Models\Course::query()->findOrFail(%d);
    app(App\Tenancy\TenantContext::class)->set($course->company()->firstOrFail());
    app(App\Actions\Videos\DetachEditorVideo::class)->forCompanyEditor(
        App\Models\Lesson::query()->findOrFail(%d),
        %d,
        App\Models\User::query()->findOrFail(%d),
        '%s'
    );
    echo 'RESULT:saved:%s';
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'RESULT:stale:%s';
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).':'.$e->getMessage());
    exit(2);
}
PHP, $label, $barrier, $course->id, $lesson->id, $video->id, $actor->id, $expected, $label, $label);

    $first = new Process([PHP_BINARY, '-r', $script('alpha')], base_path(), timeout: 30);
    $second = new Process([PHP_BINARY, '-r', $script('bravo')], base_path(), timeout: 30);
    $first->start();
    $second->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_media_alpha','oceanix_media_bravo') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $first->wait();
    $second->wait();
    DB::purge();
    DB::reconnect();

    $outputs = [$first->getOutput(), $second->getOutput()];
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:saved:'))->count())->toBe(1)
        ->and(collect($outputs)->filter(fn (string $output): bool => str_starts_with($output, 'RESULT:stale:'))->count())->toBe(1)
        ->and($video->fresh()->is_current)->toBeFalse();

    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('users')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL immediate media concurrency gate.');
