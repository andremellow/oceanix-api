<?php

use App\Enums\AssignmentStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\FrequencyType;
use App\Models\CourseVersion;
use Illuminate\Support\Carbon;

it('marks only draft versions as editable', function (): void {
    expect((new CourseVersion(['status' => CourseVersionStatus::Draft]))->isEditable())->toBeTrue()
        ->and((new CourseVersion(['status' => CourseVersionStatus::Published]))->isEditable())->toBeFalse()
        ->and((new CourseVersion(['status' => CourseVersionStatus::Retired]))->isEditable())->toBeFalse();
});

it('treats pending, in progress, failed and overdue as open obligations', function (): void {
    expect(AssignmentStatus::Pending->isOpen())->toBeTrue()
        ->and(AssignmentStatus::InProgress->isOpen())->toBeTrue()
        ->and(AssignmentStatus::Failed->isOpen())->toBeTrue()
        ->and(AssignmentStatus::Overdue->isOpen())->toBeTrue()
        ->and(AssignmentStatus::Completed->isOpen())->toBeFalse()
        ->and(AssignmentStatus::Cancelled->isOpen())->toBeFalse();
});

it('counts completed and waived as satisfying the obligation', function (): void {
    expect(AssignmentStatus::Completed->isSatisfied())->toBeTrue()
        ->and(AssignmentStatus::Waived->isSatisfied())->toBeTrue()
        ->and(AssignmentStatus::Failed->isSatisfied())->toBeFalse();
});

it('advances a recurrence cycle by the configured unit', function (): void {
    $from = Carbon::parse('2026-01-31');

    expect(FrequencyType::Days->advance($from, 45)->toDateString())->toBe('2026-03-17')
        ->and(FrequencyType::Months->advance($from, 6)->toDateString())->toBe('2026-07-31')
        ->and(FrequencyType::Years->advance($from, 2)->toDateString())->toBe('2028-01-31')
        ->and(FrequencyType::Once->advance($from, 1))->toBeNull();
});
