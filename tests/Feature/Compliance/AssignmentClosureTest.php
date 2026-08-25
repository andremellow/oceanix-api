<?php

use App\Actions\Assignments\CloseAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\Department;
use Livewire\Livewire;

beforeEach(fn () => fakeCloudflarePlayback());

it('waives an obligation without deleting its history', function (): void {
    [$assignment] = trainableAssignment();

    $closed = app(CloseAssignment::class)->handle($assignment, AssignmentStatus::Waived, 'Holds an equivalent external certificate');

    expect($closed->status)->toBe(AssignmentStatus::Waived)
        ->and($closed->status->isSatisfied())->toBeTrue()
        ->and($closed->metadata['closed_reason'])->toBe('Holds an equivalent external certificate')
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::AssignmentWaived->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'assignment.waived')->count())->toBe(1);
});

it('cancels without satisfying the obligation', function (): void {
    [$assignment] = trainableAssignment();

    $closed = app(CloseAssignment::class)->handle($assignment, AssignmentStatus::Cancelled, 'Left the company before the deadline');

    expect($closed->status->isSatisfied())->toBeFalse()
        ->and($closed->status->isOpen())->toBeFalse();
});

it('refuses to close an assignment as anything else', function (): void {
    [$assignment] = trainableAssignment();

    expect(fn () => app(CloseAssignment::class)->handle($assignment, AssignmentStatus::Completed, 'because'))
        ->toThrow(InvalidArgumentException::class);
});

it('requires the matching permission for each closure', function (): void {
    [$assignment] = trainableAssignment();
    $department = Department::factory()->create();
    $assignment->user->departments()->attach($department);
    $viewer = userWithPermissions([Permission::AssignmentsView]);
    $viewer->managedDepartments()->attach($department);
    $operator = userWithPermissions([Permission::AssignmentsWaive]);
    $operator->managedDepartments()->attach($department);

    Livewire::actingAs($viewer)
        ->test('compliance.assignment', ['assignment' => $assignment])
        ->call('startClosing', 'waived')
        ->assertForbidden();

    Livewire::actingAs($operator)
        ->test('compliance.assignment', ['assignment' => $assignment])
        ->call('startClosing', 'waived')
        ->set('closeReason', 'Equivalent training completed elsewhere')
        ->call('close')
        ->assertHasNoErrors();

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Waived);
});

it('exports the filtered table as csv and records who pulled it', function (): void {
    [$assignment] = trainableAssignment();
    $department = Department::factory()->create();
    $nestedDepartment = Department::factory()->create();
    $assignment->user->departments()->attach($department);
    $assignment->user->managedDepartments()->attach($nestedDepartment);
    [$nestedAssignment] = trainableAssignment();
    $nestedAssignment->user->departments()->attach($nestedDepartment);
    [$outsideAssignment] = trainableAssignment();
    $manager = userWithPermissions([Permission::ComplianceReportsExport]);
    $manager->managedDepartments()->attach($department);

    $response = $this->actingAs($manager)
        ->get(route('assignments.export'));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())
        ->toContain($assignment->user->name)
        ->toContain($nestedAssignment->user->name)
        ->not->toContain($outsideAssignment->user->name)
        ->and(AuditLog::query()->where('action', 'compliance_report.exported')->count())->toBe(1);
});

it('denies the export without the report permission', function (): void {
    $this->actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->get(route('assignments.export'))
        ->assertForbidden();
});
