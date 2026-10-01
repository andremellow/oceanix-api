<?php

use App\Actions\Assignments\CreateManualAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\TargetScope;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Services\Requirements\AssignmentMaterializationService;
use App\Services\Requirements\RequirementEligibilityService;

it('assigns all statuses with target and frozen version constraints', function ($status) {
    $person = User::factory()->create(['status' => $status]);
    $untargeted = User::factory()->create(['status' => $status]);
    $department = Department::factory()->create();
    $person->departments()->attach($department);
    $requirement = invitationRequirementFixture();
    $requirement->targets()->delete();
    TrainingRequirementTarget::factory()->create(['training_requirement_id' => $requirement->id, 'scope_type' => TargetScope::Department, 'department_id' => $department->id]);
    $requirement->unsetRelation('targets');
    expect(app(RequirementEligibilityService::class)->resolve($requirement)->modelKeys())->toBe([$person->id]);
    $manual = app(CreateManualAssignment::class)->handle($person, $requirement->course);
    expect($manual->course_version_id)->toBe($requirement->course->current_published_version_id);
    $service = app(AssignmentMaterializationService::class);
    expect($service->materialize($requirement)['created'])->toBe(1)->and($service->materialize($requirement)['created'])->toBe(0)
        ->and($person->assignments()->where('training_requirement_id', $requirement->id)->first()->course_version_id)->toBe($manual->course_version_id)
        ->and($untargeted->assignments()->exists())->toBeFalse();
    expect($person->status->canAccessTenant())->toBe(in_array($status, [UserStatus::Active, UserStatus::Invited], true));
})->with(UserStatus::cases());

it('preserves all-status effective windows recurrence and existing obligation history', function ($status) {
    $person = User::factory()->create(['status' => $status]);
    $requirement = invitationRequirementFixture();
    $requirement->update(['effective_from' => now()->addDay()]);
    $service = app(AssignmentMaterializationService::class);
    expect($service->materialize($requirement)['created'])->toBe(0);
    $requirement->update(['effective_from' => null, 'frequency_type' => FrequencyType::Months, 'frequency_value' => 1, 'assignment_lead_days' => 30, 'renewal_basis' => RenewalBasis::FromCompletion]);
    expect($service->materialize($requirement)['created'])->toBe(1);
    $original = $person->assignments()->sole();
    $original->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()->subMonths(2)]);
    $snapshot = $original->getRawOriginal();
    expect($service->materialize($requirement)['created'])->toBe(1)->and($service->materialize($requirement)['created'])->toBe(0)
        ->and($original->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($person->assignments()->orderBy('cycle_number')->pluck('cycle_number')->all())->toBe([1, 2]);
})->with(UserStatus::cases());
