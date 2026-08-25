<?php

use App\Enums\AssignmentStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Requirements\RequirementSchedulePreview;
use Illuminate\Support\Carbon;

function recurringScheduleRequirement(array $attributes = []): TrainingRequirement
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $course->update(['current_published_version_id' => $version->id]);
    $requirement = TrainingRequirement::factory()->create(array_merge([
        'course_id' => $course,
        'frequency_type' => FrequencyType::Months,
        'frequency_value' => 1,
        'renewal_basis' => RenewalBasis::FromDueDate,
        'assignment_lead_days' => 5,
        'due_days_after_assignment' => 10,
    ], $attributes));
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement,
        'scope_type' => 'everyone',
    ]);

    return $requirement;
}

beforeEach(fn () => Carbon::setTestNow('2026-08-24 09:00:00'));

it('previews at least three months of recurring occurrences without creating assignments', function (): void {
    $requirement = recurringScheduleRequirement();
    $user = User::factory()->create(['name' => 'Scheduled Person']);

    $rows = app(RequirementSchedulePreview::class)->forRequirement($requirement);

    expect($rows->where('person_id', $user->id)->pluck('due_at')->map->toDateString()->all())
        ->toBe(['2026-09-03', '2026-10-03', '2026-11-03'])
        ->and($rows->every(fn (array $row): bool => ! $row['materialized']))->toBeTrue()
        ->and(UserTrainingAssignment::query()->count())->toBe(0);
});

it('shows the same schedule from the person perspective', function (): void {
    $requirement = recurringScheduleRequirement(['name' => 'Monthly safety']);
    $user = User::factory()->create();

    $rows = app(RequirementSchedulePreview::class)->forUser($user);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('requirement_name')->unique()->all())->toBe(['Monthly safety']);
});

it('distinguishes materialized assignments from estimated future completion-based cycles', function (): void {
    $requirement = recurringScheduleRequirement(['renewal_basis' => RenewalBasis::FromCompletion]);
    $user = User::factory()->create();
    UserTrainingAssignment::factory()->forCourse($requirement->course)->create([
        'user_id' => $user,
        'training_requirement_id' => $requirement,
        'cycle_number' => 1,
        'due_at' => '2026-09-01',
        'completed_at' => '2026-08-25',
        'status' => AssignmentStatus::Completed,
    ]);

    $rows = app(RequirementSchedulePreview::class)->forRequirement($requirement);

    expect($rows->first()['materialized'])->toBeTrue()
        ->and($rows->skip(1)->every(fn (array $row): bool => $row['estimated']))->toBeTrue();
});

it('stops projected occurrences at the requirement effective end', function (): void {
    $requirement = recurringScheduleRequirement(['effective_until' => '2026-10-15']);
    User::factory()->create();

    expect(app(RequirementSchedulePreview::class)->forRequirement($requirement)->pluck('due_at')->map->toDateString()->all())
        ->toBe(['2026-09-03', '2026-10-03']);
});

it('paginates requirement and person schedule previews in pages of 25 occurrences', function (): void {
    $requirement = recurringScheduleRequirement();
    $people = User::factory()->count(10)->create();
    $preview = app(RequirementSchedulePreview::class);

    $requirementPage = $preview->paginateForRequirement($requirement, page: 1);
    $personPage = $preview->paginateForUser($people->first(), page: 1);

    expect($requirementPage->perPage())->toBe(25)
        ->and($requirementPage->count())->toBe(25)
        ->and($requirementPage->total())->toBe(30)
        ->and($requirementPage->lastPage())->toBe(2)
        ->and($personPage->perPage())->toBe(25)
        ->and($personPage->total())->toBe(3);
});
