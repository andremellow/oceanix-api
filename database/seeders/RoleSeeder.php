<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The four baseline access profiles from docs/product-spec.md §4. Admin holds no explicit
 * permissions: Gate::before grants it everything, keeping the bypass in one place.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            [
                'key' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full access, including access profiles and users.',
                'is_protected' => true,
                'permissions' => [],
            ],
            [
                'key' => 'training_admin',
                'name' => 'Training administrator',
                'description' => 'Runs the training program: content, rules, assignments and certificates.',
                'is_protected' => false,
                'permissions' => [
                    PermissionEnum::ComplianceDashboardView,
                    PermissionEnum::ComplianceReportsExport,
                    PermissionEnum::CoursesCreate,
                    PermissionEnum::CoursesUpdate,
                    PermissionEnum::CoursesPublish,
                    PermissionEnum::RequirementsCreate,
                    PermissionEnum::RequirementsUpdate,
                    PermissionEnum::RequirementsActivate,
                    PermissionEnum::AssignmentsCreate,
                    PermissionEnum::AssignmentsCancel,
                    PermissionEnum::AssignmentsWaive,
                    PermissionEnum::PeopleManage,
                    PermissionEnum::PeopleImport,
                    PermissionEnum::PeopleInvite,
                    PermissionEnum::DepartmentsManage,
                    PermissionEnum::JobFunctionsManage,
                    PermissionEnum::CertificatesView,
                ],
            ],
            [
                'key' => 'auditor',
                'name' => 'Auditor',
                'description' => 'Reads evidence and reports without changing content or rules.',
                'is_protected' => false,
                'permissions' => [
                    PermissionEnum::ComplianceDashboardView,
                    PermissionEnum::ComplianceEventsView,
                    PermissionEnum::ComplianceReportsExport,
                    PermissionEnum::CoursesView,
                    PermissionEnum::RequirementsView,
                    PermissionEnum::AssignmentsView,
                    PermissionEnum::PeopleView,
                    PermissionEnum::DepartmentsView,
                    PermissionEnum::JobFunctionsView,
                    PermissionEnum::CertificatesView,
                    PermissionEnum::AuditLogsView,
                ],
            ],
            [
                'key' => 'employee',
                'name' => 'Employee',
                'description' => 'Own training, history and certificates only.',
                'is_protected' => true,
                'permissions' => [],
            ],
        ];

        foreach ($profiles as $profile) {
            $role = Role::query()->updateOrCreate(
                ['key' => $profile['key']],
                [
                    'name' => $profile['name'],
                    'description' => $profile['description'],
                    'is_protected' => $profile['is_protected'],
                ],
            );

            // Prerequisites are always persisted alongside the granted permission.
            $keys = PermissionEnum::withPrerequisites($profile['permissions']);

            $role->permissions()->sync(
                Permission::query()->whereIn('key', $keys)->pluck('id')->all()
            );
        }
    }
}
