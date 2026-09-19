<?php

use App\Models\AuditLog;
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
        ->click('Audience supervisors')
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
    $job = JobFunction::factory()->create(['name' => 'Dismissal supervisor']);

    $page = visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$first->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'department_job_function')
        ->select('select[name="targetForm.department_id"]', (string) $department->id)
        ->click('dialog[open] button[type="submit"]')
        ->assertSee('Select at least one job function.')
        ->click('Select all')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true')
        ->fill('#target-function-search', 'Dismissal');

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
        ->assertPresent('dialog[open] fieldset')
        ->assertValue('#target-function-search', '')
        ->assertNotChecked('dialog[open] [data-target-function="'.$job->id.'"] [data-flux-checkbox]')
        ->assertSee('0 selected')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'false')
        ->assertDontSee('Select at least one job function.')
        ->select('select[name="targetForm.scope_type"]', 'everyone')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();

    expect($first->targets()->count())->toBe(0)
        ->and($second->targets()->sole()->scope_type->value)->toBe('everyone');
})->with(['cancel', 'escape', 'close']);

test('search preserves multiple selections and supports keyboard toggles', function (): void {
    $this->actingAs(adminUser());
    TrainingRequirement::factory()->draft()->create(['name' => 'First rule']);
    $requirement = TrainingRequirement::factory()->draft()->create(['name' => 'Second rule']);
    $first = JobFunction::factory()->create(['name' => 'Alpha supervisors']);
    $second = JobFunction::factory()->create(['name' => 'Beta engineers']);
    $page = visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$requirement->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'job_function')
        ->fill('#target-function-search', 'Alpha')
        ->assertVisible('dialog[open] [data-target-function="'.$first->id.'"]')
        ->assertMissing('dialog[open] [data-target-function="'.$second->id.'"]')
        ->click('Alpha supervisors')
        ->assertSee('1 selected')
        ->fill('#target-function-search', 'Beta')
        ->assertVisible('dialog[open] [data-target-function="'.$second->id.'"]')
        ->assertMissing('dialog[open] [data-target-function="'.$first->id.'"]')
        ->click('Beta engineers')
        ->assertSee('2 selected')
        ->fill('#target-function-search', 'unmatched search')
        ->assertSee('No job functions found.')
        ->assertMissing('dialog[open] [data-target-function="'.$first->id.'"]')
        ->assertMissing('dialog[open] [data-target-function="'.$second->id.'"]')
        ->assertNotPresent('dialog[open] [data-target-function]:visible')
        ->fill('#target-function-search', 'Alpha')
        ->assertSee('Alpha supervisors')
        ->assertChecked('dialog[open] [data-target-function="'.$first->id.'"] [data-flux-checkbox]')
        ->keys('dialog[open] [data-flux-checkbox][value="'.$first->id.'"]', ' ')
        ->assertSee('1 selected')
        ->keys('dialog[open] [data-flux-checkbox][value="'.$first->id.'"]', ' ')
        ->assertSee('2 selected')
        ->screenshot(filename: 'requirement-multiselect-desktop')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();
    expect($requirement->targets()->orderBy('job_function_id')->pluck('job_function_id')->all())->toBe([$first->id, $second->id])
        ->and(AuditLog::query()->where('action', 'training_requirement.target_added')->count())->toBe(2);
    visit(route('requirements.index', [], false))->assertSee('Alpha supervisors')->assertSee('Beta engineers');
});

test('mobile combined audiences wrap long labels and apply one department to all functions', function (): void {
    $this->actingAs(adminUser());
    $requirement = TrainingRequirement::factory()->draft()->create();
    $department = Department::factory()->create(['name' => 'Offshore operations']);
    $first = JobFunction::factory()->create(['name' => 'Senior offshore maintenance and industrial equipment supervisor']);
    $second = JobFunction::factory()->create(['name' => 'Specialist industrial equipment inspection and operational safety engineer']);
    $page = visit(route('requirements.index', [], false))->resize(390, 844)
        ->click('[wire\\:click="startTargeting('.$requirement->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'department_job_function')
        ->select('select[name="targetForm.department_id"]', (string) $department->id)
        ->keys('#target-functions-all', ' ')
        ->assertSee('2 selected')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true');
    $page->screenshot(filename: 'requirement-select-all-mobile');
    $layout = $page->script('(() => { const dialog = document.querySelector("dialog[open]"); const label = dialog.querySelector("[data-target-function] [data-flux-label]"); return {width: window.innerWidth, right: label.getBoundingClientRect().right, overflow: dialog.scrollWidth > dialog.clientWidth}; })()');
    expect($layout['right'])->toBeLessThanOrEqual($layout['width'])
        ->and($layout['overflow'])->toBeFalse();
    $page->click('dialog[open] button[type="submit"]')->assertMissing('dialog[open]')->assertNoJavascriptErrors();
    expect($requirement->targets()->pluck('department_id')->all())->toBe([$department->id, $department->id])
        ->and($requirement->targets()->orderBy('job_function_id')->pluck('job_function_id')->all())->toBe([$first->id, $second->id]);
    visit(route('requirements.index', [], false))->assertSee($first->name)->assertSee($second->name)->assertSee($department->name);
});

test('select all tracks partial selection and saves all active functions', function (): void {
    $this->actingAs(adminUser());
    $requirement = TrainingRequirement::factory()->draft()->create();
    $first = JobFunction::factory()->create(['name' => 'Alpha supervisors']);
    $second = JobFunction::factory()->create(['name' => 'Beta engineers']);
    JobFunction::factory()->create(['name' => 'Inactive function', 'status' => 'inactive']);

    visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$requirement->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'job_function')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'false')
        ->click('Select all')
        ->assertSee('2 selected')
        ->assertChecked('[data-target-function="'.$first->id.'"] [data-flux-checkbox]')
        ->assertChecked('[data-target-function="'.$second->id.'"] [data-flux-checkbox]')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true')
        ->click('Select all')
        ->assertSee('0 selected')
        ->assertNotChecked('[data-target-function="'.$first->id.'"] [data-flux-checkbox]')
        ->assertNotChecked('[data-target-function="'.$second->id.'"] [data-flux-checkbox]')
        ->click('Select all')
        ->click('Alpha supervisors')
        ->assertSee('1 selected')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'mixed')
        ->screenshot(filename: 'requirement-select-all-partial')
        ->click('Select all')
        ->assertSee('2 selected')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();

    expect($requirement->targets()->orderBy('job_function_id')->pluck('job_function_id')->all())->toBe([$first->id, $second->id]);
    visit(route('requirements.index', [], false))->assertSee($first->name)->assertSee($second->name)->assertDontSee('Inactive function');
});

test('filtered select all preserves hidden selections and disables for no matches', function (): void {
    $this->actingAs(adminUser());
    $requirement = TrainingRequirement::factory()->draft()->create();
    $hidden = JobFunction::factory()->create(['name' => 'Hidden supervisor']);
    $first = JobFunction::factory()->create(['name' => 'Match engineer']);
    $second = JobFunction::factory()->create(['name' => 'Match technician']);

    visit(route('requirements.index', [], false))
        ->click('[wire\\:click="startTargeting('.$requirement->id.')"]')
        ->select('select[name="targetForm.scope_type"]', 'job_function')
        ->click('Hidden supervisor')
        ->fill('#target-function-search', '  MATCH  ')
        ->assertSee('Select all results')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'false')
        ->click('Select all results')
        ->assertSee('3 selected')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true')
        ->click('Select all results')
        ->assertSee('1 selected')
        ->fill('#target-function-search', '')
        ->assertChecked('[data-target-function="'.$hidden->id.'"] [data-flux-checkbox]')
        ->assertNotChecked('[data-target-function="'.$first->id.'"] [data-flux-checkbox]')
        ->assertNotChecked('[data-target-function="'.$second->id.'"] [data-flux-checkbox]')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'mixed')
        ->fill('#target-function-search', 'Hidden')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'true')
        ->fill('#target-function-search', 'unmatched search')
        ->assertSee('No job functions found.')
        ->assertDisabled('#target-functions-all')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'false')
        ->assertSee('1 selected')
        ->fill('#target-function-search', '')
        ->assertAttribute('#target-functions-all', 'aria-checked', 'mixed')
        ->click('dialog[open] button[type="submit"]')
        ->assertMissing('dialog[open]')
        ->assertNoJavascriptErrors();

    expect($requirement->targets()->sole()->job_function_id)->toBe($hidden->id);
});
