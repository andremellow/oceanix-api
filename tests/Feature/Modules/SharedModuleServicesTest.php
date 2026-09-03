<?php

use App\Enums\AssignmentStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\UserTrainingAssignment;
use App\Services\Modules\ModulePropagationImpact;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\SharedContent\SharedContentCatalog;

it('keeps private content out of platform shared projections', function (): void {
    Module::factory()->create(['title' => 'Private module']);
    Module::factory()->shared()->create(['title' => 'Global module']);

    $modules = app(SharedContentCatalog::class)->platformModules();

    expect($modules)->toHaveCount(1)
        ->and($modules->sole()->title)->toBe('Global module');
});

it('requires a video only when the module content contains a video block', function (): void {
    $version = ModuleVersion::factory()->shared()->create(['content_markdown' => '<div data-oceanix-video></div>']);

    expect(app(ModuleVersionValidator::class)->problems($version))->toHaveCount(2)
        ->and(collect(app(ModuleVersionValidator::class)->problems($version))->join(' '))
        ->toContain('has no video')
        ->toContain('has no questions');

    $version->update(['content_markdown' => '<p>Text-only module</p>']);

    expect(collect(app(ModuleVersionValidator::class)->problems($version->fresh()))->join(' '))
        ->not->toContain('has no video');
});

it('summarizes affected courses and separates started assignments', function (): void {
    $module = Module::factory()->shared()->create();
    $published = ModuleVersion::factory()->published()->create(['module_id' => $module->id]);
    $draft = ModuleVersion::factory()->create([
        'module_id' => $module->id,
        'version_number' => 2,
        'status' => ModuleVersionStatus::Draft,
    ]);
    $course = Course::factory()->shared()->create();
    $courseVersion = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $courseVersion->id]);
    CourseVersionModule::query()->create([
        'course_version_id' => $courseVersion->id,
        'module_version_id' => $published->id,
        'position' => 1,
        'is_required' => true,
    ]);
    UserTrainingAssignment::factory()->forCourse($course)->create(['status' => AssignmentStatus::Pending]);
    UserTrainingAssignment::factory()->forCourse($course)->create(['status' => AssignmentStatus::InProgress]);

    expect(app(ModulePropagationImpact::class)->summarize($draft))->toBe([
        'affected_courses' => 1,
        'not_started_assignments' => 1,
        'in_progress_assignments' => 1,
    ]);
});
