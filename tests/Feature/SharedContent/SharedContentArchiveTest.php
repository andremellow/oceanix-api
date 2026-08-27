<?php

use App\Actions\Assignments\CreateManualAssignment;
use App\Actions\Courses\AssociateSharedCourse;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\SharedContent\ArchiveSharedContent;
use App\Actions\Training\StartAssignment;
use App\Enums\CourseStatus;
use App\Enums\ModuleStatus;
use App\Enums\ModuleVersionStatus;
use App\Enums\Permission;
use App\Enums\TargetScope;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Modules\EligibleModuleCatalog;
use App\Services\Requirements\AssignmentMaterializationService;
use App\Services\SharedContent\SharedContentCatalog;
use Illuminate\Support\Facades\DB;

function archivableSharedCourse(): Course
{
    $course = Course::factory()->shared()->create(['status' => CourseStatus::Active]);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course->fresh();
}

it('archives a shared course with explicit platform audit and blocks new operational use', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = archivableSharedCourse();
    $company = currentCompany();
    $tenantActor = userWithPermissions([Permission::SharedCoursesAdd]);

    $archived = app(ArchiveSharedContent::class)->handle($course, $actor, 'Superseded content');

    expect($archived->status)->toBe(CourseStatus::Archived)
        ->and(app(SharedContentCatalog::class)->availableCourses()->modelKeys())->not->toContain($course->id)
        ->and(fn () => app(AssociateSharedCourse::class)->handle($archived, $tenantActor))->toThrow(DomainException::class)
        ->and(fn () => app(CreateManualAssignment::class)->handle(User::factory()->create(), $archived))->toThrow(RuntimeException::class)
        ->and(DB::table('audit_logs')->where('action', 'shared_course.archived')->where('platform_account_id', $actor->id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'shared_course.archived')->value('metadata'))->toContain('Superseded content');
});

it('allows an existing assignment to continue after its shared course is archived', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = archivableSharedCourse();
    $assignment = UserTrainingAssignment::factory()->create([
        'course_id' => $course->id,
        'course_version_id' => $course->current_published_version_id,
    ]);
    app(ArchiveSharedContent::class)->handle($course, $actor, 'Stop new use');

    $attempt = app(StartAssignment::class)->handle($assignment);

    expect($attempt->course_version_id)->toBe($assignment->course_version_id)
        ->and($assignment->fresh()->status->value)->toBe('in_progress');
});

it('does not materialize new requirement assignments for an archived shared course', function (): void {
    $course = archivableSharedCourse();
    User::factory()->create();
    $requirement = TrainingRequirement::factory()->create(['course_id' => $course->id]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);
    app(ArchiveSharedContent::class)->handle(
        $course,
        Account::factory()->platformAdmin()->create(),
        'Stop materialization',
    );

    expect(app(AssignmentMaterializationService::class)->materialize($requirement->fresh()))
        ->toBe(['created' => 0, 'skipped' => 0])
        ->and(UserTrainingAssignment::query()->count())->toBe(0);
});

it('excludes an archived shared module from catalogs and new compositions', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $module = Module::factory()->shared()->create(['status' => ModuleStatus::Active]);
    $version = ModuleVersion::factory()->published()->create(['module_id' => $module->id]);
    $module->update(['current_published_version_id' => $version->id]);
    app(ArchiveSharedContent::class)->handle($version, $actor, 'Outdated module');

    $tenantActor = adminUser();
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course->id]);

    expect($version->fresh()->status)->toBe(ModuleVersionStatus::Archived)
        ->and(app(SharedContentCatalog::class)->availableModules()->modelKeys())->not->toContain($version->id)
        ->and(app(EligibleModuleCatalog::class)->forCourseEditor(currentCompany(), $tenantActor)['shared']->modelKeys())->not->toContain($version->id)
        ->and(fn () => app(UpdateCourseModuleComposition::class)->handle($draft, [$version->id], $tenantActor))->toThrow(LogicException::class);
});
