<?php

use App\Models\Department;
use App\Models\JobFunction;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use Tests\Support\CourseEditor\BrowserEnvironment;

beforeEach(fn () => BrowserEnvironment::assertReady());

test('the audience modal saves a job function to the selected non-first requirement', function (): void {
    $this->actingAs(adminUser());
    $unselected = TrainingRequirement::factory()->draft()->create(['id' => 21, 'name' => 'Unselected requirement']);
    $selected = TrainingRequirement::factory()->draft()->create(['id' => 42, 'name' => 'Selected requirement']);
    $job = JobFunction::factory()->create(['name' => 'Audience supervisors']);

    $page = visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$selected->id.')"]')
        ->assertSee('Add audience target')
        ->select('select[name="targetForm.scope_type"]', 'job_function')
        ->select('select[name="targetForm.job_function_id"]', (string) $job->id)
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();

    expect($selected->targets()->sole()->job_function_id)->toBe($job->id)
        ->and($unselected->targets()->count())->toBe(0)
        ->and(TrainingRequirementTarget::query()->count())->toBe(1);

    visit(route('requirements.index', [], false))->assertSee('Audience supervisors');
});

test('audience dismissal clears stale validation before selecting another requirement', function (string $dismissal): void {
    $this->actingAs(adminUser());
    $first = TrainingRequirement::factory()->draft()->create(['id' => 21]);
    $second = TrainingRequirement::factory()->draft()->create(['id' => 42]);
    $department = Department::factory()->create(['name' => 'Audience Operations']);

    $page = visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$first->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'department_job_function')
        ->select('select[name="targetForm.department_id"]', (string) $department->id)
        ->click('dialog[open] button[type="submit"]')
        ->assertSee('Select a job function.');

    match ($dismissal) {
        'cancel' => $page->click('dialog[open] button[wire\\:click="closeTargeting"]'),
        'escape' => $page->keys('select[name="targetForm.scope_type"]', 'Escape'),
        'close' => $page->click('dialog[open] button[aria-label="Close modal"]'),
    };
    $page->assertMissing('dialog[open]');
    expect(TrainingRequirementTarget::query()->count())->toBe(0);

    $page->click('[wire\\:click="startTargeting('.$second->id.')"]')
        ->assertSee('Add audience target')
        ->assertSelected('select[name="targetForm.scope_type"]', 'department')
        ->assertSelected('select[name="targetForm.department_id"]', '')
        ->select('select[name="targetForm.scope_type"]', 'department_job_function')
        ->assertPresent('dialog[open] select[name="targetForm.job_function_id"]')
        ->assertDontSee('Select a job function.')
        ->select('select[name="targetForm.scope_type"]', 'everyone')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();

    expect($first->targets()->count())->toBe(0)
        ->and($second->targets()->sole()->scope_type->value)->toBe('everyone');
})->with(['cancel', 'escape', 'close']);
