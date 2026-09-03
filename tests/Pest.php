<?php

use App\Enums\ComplianceEventType;
use App\Enums\Permission as PermissionEnum;
use App\Enums\QuestionType;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\Video;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Training\LessonProgressProjector;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function currentCompany(): Company
{
    return app(TenantContext::class)->get() ?? throw new RuntimeException('No test company selected.');
}

/**
 * Grant a set of permissions through a throwaway access profile — the same path the
 * application uses, so the Gate resolution under test is the real one.
 *
 * @param  list<PermissionEnum|string>  $permissions
 */
function grantPermissions(User $user, array $permissions, string $profileName = 'Test profile'): Role
{
    $role = Role::factory()->create(['name' => $profileName, 'key' => str($profileName)->slug()->toString()]);

    $ids = collect(PermissionEnum::withPrerequisites($permissions))
        ->map(function (string $key): int {
            $permission = PermissionEnum::from($key);

            return Permission::query()->firstOrCreate(
                ['key' => $permission->value],
                ['label' => $permission->label(), 'group' => $permission->group()],
            )->id;
        });

    $role->permissions()->sync($ids);
    $user->roles()->attach($role);

    return $role;
}

/** @param list<PermissionEnum|string> $permissions */
function userWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    grantPermissions($user, $permissions, 'Profile '.str()->random(8));

    return $user->fresh();
}

function adminUser(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->firstOrCreate(
        ['key' => 'admin'],
        ['name' => 'Administrator', 'is_protected' => true],
    ));

    return $user->fresh();
}

/** A person with no granted permission: only their own training. */
function employeeUser(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->firstOrCreate(
        ['key' => 'employee'],
        ['name' => 'Employee', 'is_protected' => true],
    ));

    return $user->fresh();
}

function seedAccessCatalog(): void
{
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
}

/** @return array{0: Course, 1: CourseVersion, 2: Module, 3: ModuleVersion} */
function sharedTrainingGraph(): array
{
    $course = Course::factory()->shared()->create();
    $courseVersion = CourseVersion::factory()->published()->create([
        'course_id' => $course->id,
        'published_by' => null,
    ]);
    $course->update(['current_published_version_id' => $courseVersion->id]);

    $module = Module::factory()->shared()->create(['status' => 'active']);
    $moduleVersion = ModuleVersion::factory()->published()->create([
        'module_id' => $module->id,
        'published_by' => null,
    ]);
    $module->update(['current_published_version_id' => $moduleVersion->id]);

    CourseVersionModule::query()->create([
        'course_version_id' => $courseVersion->id,
        'module_version_id' => $moduleVersion->id,
        'position' => 1,
        'is_required' => true,
    ]);

    return [$course->fresh(), $courseVersion->fresh(), $module->fresh(), $moduleVersion->fresh()];
}

/**
 * @return array{0: UserTrainingAssignment, 1: Lesson, 2: Question}
 */
function trainableAssignment(array $lessonAttributes = [], int $maxAttempts = 2): array
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    $lesson = Lesson::factory()->create([
        'course_version_id' => $version->id,
        'minimum_watch_percentage' => 90,
        'passing_score' => 70,
        'content_markdown' => '<div data-oceanix-video></div>',
        ...$lessonAttributes,
    ]);
    Video::factory()->create(['lesson_id' => $lesson->id, 'duration_seconds' => 100]);

    $question = Question::factory()->create([
        'lesson_id' => $lesson->id,
        'type' => QuestionType::SingleChoice,
        'max_attempts' => $maxAttempts,
    ]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    $assignment = UserTrainingAssignment::factory()->create([
        'user_id' => User::factory(),
        'course_id' => $course->id,
        'course_version_id' => $version->id,
    ]);

    return [$assignment->fresh(), $lesson->fresh(), $question->fresh()];
}

/** Report playback that advances in step with real time, the way a real player does. */
function watch(UserTrainingAssignment $assignment, Lesson $lesson, int $toSecond, int $step = 10): void
{
    $recorder = app(ComplianceEventRecorder::class);
    $clock = Carbon::now();

    for ($second = 0; $second <= $toSecond; $second += $step) {
        $clock = $clock->copy()->addSeconds($step);
        Carbon::setTestNow($clock);

        $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => $second,
        ]);
    }

    app(LessonProgressProjector::class)->project($assignment, $lesson);
}

/** The provider is exercised through its real implementation; only the network is faked. */
function fakeCloudflarePlayback(): void
{
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['token' => 'signed-token']]),
    ]);
}
