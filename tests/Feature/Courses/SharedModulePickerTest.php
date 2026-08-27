<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Services\Modules\EligibleModuleCatalog;
use Livewire\Livewire;

function pickerModule(Module $module): Module
{
    $version = ModuleVersion::factory()->published()->create(['module_id' => $module->id, 'title' => $module->title]);

    return $version;
}

it('searches eligible company and shared modules without leaking another tenant', function (): void {
    $company = currentCompany();
    $actor = userWithPermissions([Permission::CoursesUpdate, Permission::SharedModulesUse]);
    $actor->forceFill(['company_id' => $company->id])->save();
    pickerModule(Module::factory()->create(['title' => 'Company Escape']));
    pickerModule(Module::factory()->shared()->create(['title' => 'Shared Escape']));
    pickerModule(Module::factory()->create(['company_id' => Company::factory()->create()->id, 'title' => 'Foreign Escape']));

    $groups = app(EligibleModuleCatalog::class)->forCourseEditor($company, $actor, 'Escape');

    expect($groups['company']->pluck('title')->all())->toBe(['Company Escape'])
        ->and($groups['shared']->pluck('title')->all())->toBe(['Shared Escape']);
});

it('renders ownership labels, search and accessible module controls', function (): void {
    $course = Course::factory()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course->id]);
    pickerModule(Module::factory()->create(['title' => 'Company Survival']));
    pickerModule(Module::factory()->shared()->create(['title' => 'Shared Safety']));
    $actor = userWithPermissions([Permission::CoursesUpdate, Permission::SharedModulesUse]);
    $actor->forceFill(['company_id' => $course->company_id])->save();

    Livewire::actingAs($actor)->test('courses.editor', ['course' => $course])
        ->assertSee(__('Company Modules'))
        ->assertSee(__('Shared Modules'))
        ->assertSee(__('Managed by platform'))
        ->assertSee(__('Managed by company'))
        ->assertSee('Shared Safety')
        ->assertSeeHtml('aria-live="polite"');
});
