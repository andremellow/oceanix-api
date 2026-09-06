<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Actions\Courses\PublishCourseVersion;
use App\Models\Company;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\User;
use App\Services\Courses\PublicPreviewResolver;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

foreach (['DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55439', 'DB_DATABASE' => 'preview_test', 'DB_USERNAME' => 'preview_test', 'DB_PASSWORD' => '', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'NIGHTWATCH_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
// Invoked only by the opted-in isolated PostgreSQL tests; never reads .env database values.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => null,
    'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 55439,
    'database.connections.pgsql.database' => 'preview_test', 'database.connections.pgsql.username' => 'preview_test',
    'database.connections.pgsql.password' => '', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
if (getenv('PREVIEW_PG_TEST') !== '1') {
    throw new LogicException('Explicit disposable test opt-in required.');
}
Http::preventStrayRequests();
$mode = $argv[1];
if ($mode === 'setup') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->create();
    $role = Role::factory()->create(['key' => 'admin', 'is_protected' => true]);
    $user->roles()->attach($role);
    $version = CourseVersion::factory()->create();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id, 'content_markdown' => 'Ready for publication']);
    $question = Question::factory()->create(['lesson_id' => $lesson->id]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);
    echo json_encode(['company' => $company->id, 'version' => $version->id, 'user' => $user->id]);
    exit;
}
$fixture = json_decode(file_get_contents($argv[2]), true);
app(TenantContext::class)->set(Company::findOrFail($fixture['company']));
$user = User::findOrFail($fixture['user']);
$version = CourseVersion::findOrFail($fixture['version']);
if ($mode === 'inspect') {
    $link = CoursePreviewLink::first();
    $status = null;
    if ($link) {
        try {
            app(PublicPreviewResolver::class)->resolve($link->token_encrypted);
            $status = 200;
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
        }
    }
    echo json_encode(['count' => CoursePreviewLink::count(), 'status' => $version->status->value, 'read' => $status]);
    exit;
}
$barrier = $argv[3];
if ($mode === 'observe') {
    $row = DB::selectOne("select wait_event_type, query from pg_stat_activity where application_name = 'preview-contender' and pid <> pg_backend_pid()");
    echo json_encode(['blocked_on_course' => $row && $row->wait_event_type === 'Lock' && str_contains($row->query, '"courses"') && str_contains($row->query, 'for update')]);
    exit;
}
$operation = $argv[4];
$execute = function () use ($operation, $version, $user): array {
    if ($operation === 'publish') {
        app(PublishCourseVersion::class)->handle($version, $user);

        return ['published' => true];
    }
    $dto = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $user);

    return ['digest' => hash('sha256', $dto['url'])];
};
try {
    if ($mode === 'hold') {
        $result = DB::transaction(function () use ($execute, $barrier): array {
            // The real Action obtains its locks; the outer transaction keeps them held.
            // This is deliberately not a substitute lock in the harness.
            $result = $execute();
            file_put_contents($barrier.'.held', 'ready');
            $deadline = microtime(true) + 20;
            while (! file_exists($barrier.'.release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Lock release timeout.');
                }
                usleep(10000);
            }

            return $result;
        });
    } else {
        DB::select("select set_config('application_name', 'preview-contender', false)");
        $result = $execute();
    }
    echo json_encode($result);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
