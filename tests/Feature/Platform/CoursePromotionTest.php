<?php

use App\Actions\Courses\MakeCourseShared;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Services\Courses\CoursePromotionImpact;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

function promotionGraph(): array
{
    $course = Course::factory()->create(['title' => 'Source Course']);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);
    $moduleVersion = ModuleVersion::query()->create([
        'company_id' => currentCompany()->id, 'is_shared' => false, 'code' => 'REUSABLE',
        'lineage_uuid' => (string) Str::uuid(), 'version_number' => 1, 'status' => 'published',
        'title' => 'Reusable Module', 'published_at' => now(),
    ]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'module_version_id' => $moduleVersion->id, 'position' => 1]);

    $affected = Course::factory()->create(['title' => 'Affected Course']);
    $affectedVersion = CourseVersion::factory()->published()->create(['course_id' => $affected->id]);
    $affected->update(['current_published_version_id' => $affectedVersion->id]);
    CourseVersionModule::query()->create(['course_version_id' => $affectedVersion->id, 'module_version_id' => $moduleVersion->id, 'position' => 1]);

    return [$course, $moduleVersion, $affected];
}

it('previews reused modules and every other affected company course', function (): void {
    [$course, $module, $affected] = promotionGraph();
    $preview = app(CoursePromotionImpact::class)->preview($course, currentCompany());

    expect($preview['modules']->modelKeys())->toBe([$module->id])
        ->and($preview['affected_courses']->modelKeys())->toBe([$affected->id])
        ->and($preview['token'])->toHaveLength(64);
});

it('atomically promotes the course and reused modules and keeps a source association', function (): void {
    [$course, $module, $affected] = promotionGraph();
    $company = currentCompany();
    $tenantAdministrator = adminUser();
    $actor = Account::factory()->platformAdmin()->create();
    $preview = app(CoursePromotionImpact::class)->preview($course, $company);

    $result = app(MakeCourseShared::class)->handle($course, $company, $actor, $preview['token']);

    expect($result->id)->toBe($course->id)
        ->and($result->company_id)->toBeNull()->and($result->is_shared)->toBeTrue()
        ->and($module->fresh()->company_id)->toBeNull()->and($module->fresh()->is_shared)->toBeTrue()
        ->and($affected->fresh()->company_id)->toBe($company->id)
        ->and(CompanyCourse::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('course_id', $course->id)->active()->exists())->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'module.promoted_to_shared')->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'course.promoted_to_shared')->firstOrFail()->platform_account_id)->toBe($actor->id)
        ->and(Gate::forUser($tenantAdministrator)->allows('update', $result))->toBeFalse();
});

it('rejects a stale preview without partially changing ownership', function (): void {
    [$course, $module] = promotionGraph();
    $company = currentCompany();
    $actor = Account::factory()->platformAdmin()->create();
    $token = app(CoursePromotionImpact::class)->preview($course, $company)['token'];
    DB::table('lessons')->where('id', $module->id)->update(['title' => 'Changed after preview', 'updated_at' => now()->addSecond()]);

    expect(fn () => app(MakeCourseShared::class)->handle($course, $company, $actor, $token))
        ->toThrow(DomainException::class, 'preview is stale');

    expect($course->fresh()->company_id)->toBe($company->id)->and($course->fresh()->is_shared)->toBeFalse()
        ->and($module->fresh()->company_id)->toBe($company->id)->and($module->fresh()->is_shared)->toBeFalse();
});

it('rejects an invalid ownership graph without changing any record', function (): void {
    [$course, $module] = promotionGraph();
    $company = currentCompany();
    $otherCompany = Company::factory()->create();
    $foreignVersion = ModuleVersion::query()->create([
        'company_id' => $otherCompany->id, 'is_shared' => false, 'code' => 'FOREIGN',
        'lineage_uuid' => (string) Str::uuid(), 'version_number' => 1, 'status' => 'published',
        'title' => 'Foreign Module', 'published_at' => now(),
    ]);
    app(TenantContext::class)->set($company);
    CourseVersionModule::query()->create([
        'course_version_id' => $course->current_published_version_id,
        'module_version_id' => $foreignVersion->id,
        'position' => 2,
    ]);

    expect(fn () => app(CoursePromotionImpact::class)->preview($course, $company))->toThrow(DomainException::class);
    expect($course->fresh()->company_id)->toBe($company->id)
        ->and($module->fresh()->company_id)->toBe($company->id)
        ->and($foreignVersion->fresh()->company_id)->toBe($otherCompany->id);
});
