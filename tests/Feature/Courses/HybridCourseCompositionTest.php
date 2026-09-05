<?php

use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleStatus;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function activeModuleVersion(array $moduleAttributes): ModuleVersion
{
    $module = Module::factory()->create(['status' => ModuleStatus::Active, ...$moduleAttributes]);
    $version = ModuleVersion::factory()->published()->create(['module_id' => $module->id]);
    $module->update(['current_published_version_id' => $version->id]);

    return $version;
}

it('orders company and shared module snapshots in a company course draft', function (): void {
    $company = currentCompany();
    $own = activeModuleVersion(['company_id' => $company->id, 'is_shared' => false]);
    $shared = activeModuleVersion(['company_id' => null, 'is_shared' => true]);
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course->id]);
    $actor = adminUser();

    app(UpdateCourseModuleComposition::class)->handle($draft, [$shared->id, $own->id], $actor);

    expect($draft->moduleCompositions()->pluck('lesson_id')->all())->toBe([$shared->id, $own->id]);
});

it('rejects cross-tenant module injection and published-course mutation', function (): void {
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course->id]);
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    $foreign = activeModuleVersion(['company_id' => $other->id, 'is_shared' => false]);
    app(TenantContext::class)->set($course->company);
    $actor = adminUser();

    expect(fn () => app(UpdateCourseModuleComposition::class)->handle($draft, [$foreign->id], $actor))
        ->toThrow(LogicException::class);

    $existing = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    app(UpdateCourseModuleComposition::class)->handle($draft, [$existing->id], $actor);
    $pivotId = $draft->moduleCompositions()->sole()->id;
    $pivotBefore = (array) DB::table('course_version_lessons')->whereKey($pivotId)->first();
    $draft->update(['status' => CourseVersionStatus::Published]);
    expect(fn () => app(UpdateCourseModuleComposition::class)->handle($draft->fresh(), [], $actor))
        ->toThrow(LogicException::class)
        ->and((array) DB::table('course_version_lessons')->whereKey($pivotId)->first())->toBe($pivotBefore);
});
