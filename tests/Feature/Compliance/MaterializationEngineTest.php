<?php

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Enums\TargetScope;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Requirements\AssignmentMaterializationService;
use Illuminate\Support\Carbon;

function activeRequirement(array $attributes = []): TrainingRequirement
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    $requirement = TrainingRequirement::factory()->create([
        'course_id' => $course->id,
        'status' => RequirementStatus::Active,
        'frequency_type' => FrequencyType::Months,
        'frequency_value' => 6,
        'renewal_basis' => RenewalBasis::FromCompletion,
        'assignment_lead_days' => 30,
        'due_days_after_assignment' => 30,
        ...$attributes,
    ]);

    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);

    return $requirement->fresh();
}

it('creates one assignment per eligible person', function (): void {
    User::factory()->count(3)->create();
    User::factory()->terminated()->create();
    $requirement = activeRequirement();

    $result = app(AssignmentMaterializationService::class)->materializeAll();

    expect($result['created'])->toBe(3)
        ->and(UserTrainingAssignment::query()->count())->toBe(3)
        ->and(UserTrainingAssignment::query()->first()->origin_type)->toBe(AssignmentOrigin::Requirement);
});

it('creates nothing new when run again', function (): void {
    User::factory()->count(2)->create();
    activeRequirement();

    $service = app(AssignmentMaterializationService::class);
    $service->materializeAll();
    $second = $service->materializeAll();

    expect($second['created'])->toBe(0)
        ->and(UserTrainingAssignment::query()->count())->toBe(2);
});

it('ignores requirements that are not active', function (): void {
    User::factory()->create();
    activeRequirement(['status' => RequirementStatus::Paused]);

    expect(app(AssignmentMaterializationService::class)->materializeAll()['created'])->toBe(0);
});

it('ignores a requirement outside its effective window', function (): void {
    User::factory()->create();
    activeRequirement(['effective_from' => now()->addMonth()->toDateString()]);

    expect(app(AssignmentMaterializationService::class)->materializeAll()['created'])->toBe(0);
});

it('waits for the open occurrence instead of stacking another', function (): void {
    User::factory()->create();
    $requirement = activeRequirement();
    $service = app(AssignmentMaterializationService::class);

    $service->materializeAll();
    Carbon::setTestNow(now()->addYear());
    $service->materializeAll();

    expect(UserTrainingAssignment::query()->count())->toBe(1);
});

it('renews from the completion date once the cycle comes round', function (): void {
    $user = User::factory()->create();
    $requirement = activeRequirement();
    $service = app(AssignmentMaterializationService::class);

    $service->materializeAll();
    $first = UserTrainingAssignment::query()->firstOrFail();
    $first->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()]);

    // Next due six months after completion, available 30 days earlier.
    Carbon::setTestNow(now()->addMonths(5)->addDays(2));
    $service->materializeAll();

    $second = UserTrainingAssignment::query()->where('cycle_number', 2)->first();

    expect($second)->not->toBeNull()
        ->and($second->series_key)->toBe($first->series_key)
        ->and($second->supersedes_assignment_id)->toBe($first->id)
        ->and($second->due_at->toDateString())->toBe($first->completed_at->copy()->addMonths(6)->toDateString());
});

it('does not open the next cycle before its lead window', function (): void {
    User::factory()->create();
    activeRequirement();
    $service = app(AssignmentMaterializationService::class);

    $service->materializeAll();
    UserTrainingAssignment::query()->firstOrFail()
        ->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()]);

    Carbon::setTestNow(now()->addMonth());
    $service->materializeAll();

    expect(UserTrainingAssignment::query()->count())->toBe(1);
});

it('keeps the original calendar when renewing from the due date', function (): void {
    User::factory()->create();
    activeRequirement(['renewal_basis' => RenewalBasis::FromDueDate]);
    $service = app(AssignmentMaterializationService::class);

    $service->materializeAll();
    $first = UserTrainingAssignment::query()->firstOrFail();
    // Completed three months late; the schedule must not shift.
    Carbon::setTestNow(now()->addMonths(3));
    $first->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()]);

    Carbon::setTestNow($first->due_at->copy()->addMonths(6)->subDays(10));
    $service->materializeAll();

    $second = UserTrainingAssignment::query()->where('cycle_number', 2)->firstOrFail();

    expect($second->due_at->toDateString())->toBe($first->due_at->copy()->addMonths(6)->toDateString());
});

it('never renews a one-off requirement', function (): void {
    User::factory()->create();
    activeRequirement(['frequency_type' => FrequencyType::Once, 'frequency_value' => null]);
    $service = app(AssignmentMaterializationService::class);

    $service->materializeAll();
    UserTrainingAssignment::query()->firstOrFail()
        ->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()]);

    Carbon::setTestNow(now()->addYears(5));
    $service->materializeAll();

    expect(UserTrainingAssignment::query()->count())->toBe(1);
});

it('only targets the people a scoped requirement applies to', function (): void {
    $department = Department::factory()->create();
    $inside = User::factory()->create();
    $inside->departments()->attach($department);
    User::factory()->create();

    $requirement = activeRequirement();
    $requirement->targets()->delete();
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Department,
        'department_id' => $department->id,
    ]);

    app(AssignmentMaterializationService::class)->materializeAll();

    expect(UserTrainingAssignment::query()->count())->toBe(1)
        ->and(UserTrainingAssignment::query()->first()->user_id)->toBe($inside->id);
});

it('freezes the published version at materialization', function (): void {
    User::factory()->create();
    $requirement = activeRequirement();
    $original = $requirement->course->current_published_version_id;

    app(AssignmentMaterializationService::class)->materializeAll();

    $newer = CourseVersion::factory()->published()->create([
        'course_id' => $requirement->course_id,
        'version_number' => 2,
    ]);
    $requirement->course->update(['current_published_version_id' => $newer->id]);

    expect(UserTrainingAssignment::query()->first()->course_version_id)->toBe($original);
});

it('marks open assignments past their deadline as overdue', function (): void {
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    $late = UserTrainingAssignment::factory()->forCourse($course->refresh())->create([
        'user_id' => User::factory(), 'due_at' => now()->subDay(), 'status' => AssignmentStatus::Pending,
    ]);
    $onTime = UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => User::factory(), 'due_at' => now()->addDay(), 'status' => AssignmentStatus::Pending,
    ]);
    $done = UserTrainingAssignment::factory()->forCourse($course)->completed()->create([
        'user_id' => User::factory(), 'due_at' => now()->subDay(),
    ]);

    $this->artisan('oceanix:update-overdue')->assertSuccessful();

    expect($late->fresh()->status)->toBe(AssignmentStatus::Overdue)
        ->and($onTime->fresh()->status)->toBe(AssignmentStatus::Pending)
        ->and($done->fresh()->status)->toBe(AssignmentStatus::Completed);
});
