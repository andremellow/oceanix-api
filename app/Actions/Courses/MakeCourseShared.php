<?php

namespace App\Actions\Courses;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\Module;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\CoursePromotionImpact;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class MakeCourseShared
{
    public function __construct(
        private readonly CoursePromotionImpact $impact,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Course $course, Company $sourceCompany, Account $actor, string $previewToken): Course
    {
        if (! $actor->is_platform_admin || $actor->status !== 'active') {
            throw new DomainException('Only an active platform administrator can promote content.');
        }

        $previousCompany = $this->tenant->get();

        try {
            return DB::transaction(function () use ($course, $sourceCompany, $actor, $previewToken): Course {
                $impact = $this->impact->preview($course, $sourceCompany, lock: true);

                if (! hash_equals($impact['token'], $previewToken)) {
                    throw new DomainException('The promotion preview is stale. Review the impact again before confirming.');
                }

                $this->tenant->set($sourceCompany);
                $promotedLineages = $impact['modules']->where('is_shared', false)->pluck('lineage_uuid');
                $moduleVersions = Module::query()->withoutGlobalScopes()
                    ->whereIn('lineage_uuid', $promotedLineages)
                    ->lockForUpdate()
                    ->get();
                $promotedModuleIds = $moduleVersions->modelKeys();

                foreach ($moduleVersions->where('is_shared', false) as $module) {
                    $module->forceFill(['company_id' => null, 'is_shared' => true])->save();
                    $this->audit->log('module.promoted_to_shared', $module, before: [
                        'company_id' => $sourceCompany->id, 'is_shared' => false,
                    ], after: ['company_id' => null, 'is_shared' => true], platformActor: $actor);
                }

                $promoted = $impact['course'];
                $promoted->forceFill(['company_id' => null, 'is_shared' => true])->save();

                $now = now();
                DB::table('company_courses')->insertOrIgnore([
                    'company_id' => $sourceCompany->id,
                    'course_id' => $promoted->id,
                    'associated_at' => $now,
                    'associated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                CompanyCourse::query()->withoutGlobalScopes()
                    ->where('company_id', $sourceCompany->id)->where('course_id', $promoted->id)
                    ->update(['removed_at' => null, 'removed_by_user_id' => null, 'removal_reason' => null]);

                $this->audit->log('course.promoted_to_shared', $promoted, before: [
                    'company_id' => $sourceCompany->id, 'is_shared' => false,
                ], after: [
                    'company_id' => null,
                    'is_shared' => true,
                    'promoted_module_ids' => $promotedModuleIds,
                    'affected_course_ids' => $impact['affected_courses']->modelKeys(),
                ], platformActor: $actor);

                return $promoted->refresh();
            });
        } finally {
            $previousCompany === null ? $this->tenant->clear() : $this->tenant->set($previousCompany);
        }
    }
}
