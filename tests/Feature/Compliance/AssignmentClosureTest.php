<?php

use App\Actions\Assignments\CloseAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ComplianceEvent;
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

    Livewire::actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->test('compliance.assignment', ['assignment' => $assignment])
        ->call('startClosing', 'waived')
        ->assertForbidden();

    Livewire::actingAs(userWithPermissions([Permission::AssignmentsWaive]))
        ->test('compliance.assignment', ['assignment' => $assignment])
        ->call('startClosing', 'waived')
        ->set('closeReason', 'Equivalent training completed elsewhere')
        ->call('close')
        ->assertHasNoErrors();

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Waived);
});

it('exports the filtered table as csv and records who pulled it', function (): void {
    [$assignment] = trainableAssignment();

    $response = $this->actingAs(userWithPermissions([Permission::ComplianceReportsExport]))
        ->get(route('assignments.export'));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain($assignment->user->name)
        ->and(AuditLog::query()->where('action', 'compliance_report.exported')->count())->toBe(1);
});

it('denies the export without the report permission', function (): void {
    $this->actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->get(route('assignments.export'))
        ->assertForbidden();
});
