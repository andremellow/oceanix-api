<?php

use App\Enums\AssignmentStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseAttempt;
use App\Models\CourseVersion;
use App\Models\UserTrainingAssignment;

it('shows publication impact and leaves in-progress restart unchecked by default', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $published = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    CourseVersion::factory()->create(['course_id' => $course->id, 'version_number' => 2]);
    $course->update(['current_published_version_id' => $published->id]);

    $inProgress = UserTrainingAssignment::factory()->create([
        'course_id' => $course->id,
        'course_version_id' => $published->id,
        'status' => AssignmentStatus::Pending,
    ]);
    UserTrainingAssignment::factory()->create([
        'course_id' => $course->id,
        'course_version_id' => $published->id,
        'status' => AssignmentStatus::InProgress,
    ]);
    CourseAttempt::factory()->create(['assignment_id' => $inProgress->id]);

    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSet('restartInProgress', false)
        ->assertViewHas('impact', ['not_started' => 1, 'in_progress' => 1])
        ->assertSee(__('Not started'))
        ->assertSee(__('In progress'))
        ->assertSee(__('Restart in-progress assignments'));
});

it('shows an actionable empty state for a shared course draft without modules', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course->id]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSee(__('No modules selected'))
        ->assertSee(__('Publish course and module changes'))
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->call('publish')
        ->assertHasErrors('publish')
        ->assertSee(__('Add at least one lesson before publishing.'));
});
