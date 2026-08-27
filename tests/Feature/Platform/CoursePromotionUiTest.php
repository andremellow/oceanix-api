<?php

use App\Models\Account;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\ModuleVersion;
use Illuminate\Support\Facades\DB;

it('shows source company transferred modules and affected courses before confirmation', function (): void {
    $company = currentCompany();
    $course = Course::factory()->create(['title' => 'Promotable Course']);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);
    $module = Module::factory()->create(['title' => 'Transferred Module']);
    $moduleVersion = ModuleVersion::factory()->published()->create(['module_id' => $module->id, 'title' => 'Transferred Module']);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'module_version_id' => $moduleVersion->id, 'position' => 1]);
    $affected = Course::factory()->create(['title' => 'Also Affected']);
    $affectedVersion = CourseVersion::factory()->published()->create(['course_id' => $affected->id]);
    CourseVersionModule::query()->create(['course_version_id' => $affectedVersion->id, 'module_version_id' => $moduleVersion->id, 'position' => 1]);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.company', ['company' => $company, 'course' => $course])
        ->call('previewPromotion', $course->id)
        ->assertSet('confirmingPromotion', true)
        ->assertSee($company->name)->assertSee('Transferred Module')->assertSee('Also Affected')
        ->assertSee(__('Confirm Make Shared'));
});

it('rejects a company-course detail route whose course belongs to another company', function (): void {
    $company = currentCompany();
    $other = Company::factory()->create();
    $course = Course::factory()->create();
    $account = Account::factory()->platformAdmin()->create();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.companies.courses.show', ['company' => $company, 'course' => $course]))
        ->assertNotFound();

    expect($course->company_id)->toBe($other->id);
});

it('shows company-owned and associated shared courses in one company course list', function (): void {
    $company = currentCompany();
    $owned = Course::factory()->create(['company_id' => $company->id, 'title' => 'Owned Safety Course']);
    $shared = Course::factory()->shared()->create(['title' => 'Shared Safety Course']);
    DB::table('company_courses')->insert([
        'company_id' => $company->id,
        'course_id' => $shared->id,
        'associated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->assertSee(__('Courses'))
        ->assertSee($owned->title)
        ->assertSee(__('Company-owned'))
        ->assertSee($shared->title)
        ->assertSee(__('Shared'));
});

it('opens the real course detail from a company for owned and associated shared courses', function (): void {
    $company = currentCompany();
    $owned = Course::factory()->create(['company_id' => $company->id, 'title' => 'Owned Course Detail']);
    $shared = Course::factory()->shared()->create(['title' => 'Shared Course Detail']);
    DB::table('company_courses')->insert([
        'company_id' => $company->id,
        'course_id' => $shared->id,
        'associated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $account = Account::factory()->platformAdmin()->create();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.companies.courses.show', ['company' => $company, 'course' => $owned]))
        ->assertOk()
        ->assertSee($owned->title)
        ->assertSee(__('Managed by company'));

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.companies.courses.show', ['company' => $company, 'course' => $shared]))
        ->assertOk()
        ->assertSee($shared->title)
        ->assertSee(__('Managed by platform'));
});

it('keeps a promoted course on the company screen and relabels it as shared', function (): void {
    $company = currentCompany();
    $course = Course::factory()->create(['company_id' => $company->id, 'title' => 'Course That Stays Visible']);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->call('previewPromotion', $course->id)
        ->call('makeShared')
        ->assertNoRedirect()
        ->assertSet('confirmingPromotion', false)
        ->assertSee($course->title)
        ->assertSee(__('Shared'));

    expect($course->refresh()->is_shared)->toBeTrue()
        ->and(DB::table('company_courses')
            ->where('company_id', $company->id)
            ->where('course_id', $course->id)
            ->whereNull('removed_at')
            ->exists())->toBeTrue();
});
