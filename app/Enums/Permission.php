<?php

namespace App\Enums;

/**
 * Atomic permission catalog — one case per independently grantable backend action.
 *
 * Every permission is registered as a Laravel Gate in AppServiceProvider::boot(). Routes are
 * protected with EnsureUserHasPermission; state-changing Actions and Livewire methods must
 * authorize through the same Gate or a Policy. Hiding a control is presentation, never
 * authorization.
 */
enum Permission: string
{
    // Compliance operations
    case ComplianceDashboardView = 'compliance-dashboard.view';
    case ComplianceReportsExport = 'compliance-reports.export';
    case ComplianceEventsView = 'compliance-events.view';

    // Content
    case CoursesView = 'courses.view';
    case CoursesCreate = 'courses.create';
    case CoursesUpdate = 'courses.update';
    case CoursesPublish = 'courses.publish';
    case CoursesRetire = 'courses.retire';

    // Requirements
    case RequirementsView = 'training-requirements.view';
    case RequirementsCreate = 'training-requirements.create';
    case RequirementsUpdate = 'training-requirements.update';
    case RequirementsActivate = 'training-requirements.activate';

    // Assignments
    case AssignmentsView = 'assignments.view';
    case AssignmentsCreate = 'assignments.create';
    case AssignmentsCancel = 'assignments.cancel';
    case AssignmentsWaive = 'assignments.waive';

    // People and organization
    case PeopleView = 'people.view';
    case PeopleManage = 'people.manage';
    case PeopleImport = 'people.import';
    case PeopleAssignAccessProfiles = 'people.access-profiles.assign';
    case PeopleInvite = 'people.invite';
    case DepartmentsView = 'departments.view';
    case DepartmentsManage = 'departments.manage';
    case JobFunctionsView = 'job-functions.view';
    case JobFunctionsManage = 'job-functions.manage';

    // Certificates
    case CertificatesView = 'certificates.view';
    case CertificatesRevoke = 'certificates.revoke';

    // Administration
    case AuditLogsView = 'audit-logs.view';
    case AppSettingsView = 'app-settings.view';
    case AppSettingsUpdate = 'app-settings.update';

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'compliance-dashboard.') => 'compliance-dashboard',
            str_starts_with($this->value, 'compliance-reports.') => 'compliance-reports',
            str_starts_with($this->value, 'compliance-events.') => 'compliance-events',
            str_starts_with($this->value, 'courses.') => 'courses',
            str_starts_with($this->value, 'training-requirements.') => 'training-requirements',
            str_starts_with($this->value, 'assignments.') => 'assignments',
            str_starts_with($this->value, 'people.') => 'people',
            str_starts_with($this->value, 'departments.') => 'departments',
            str_starts_with($this->value, 'job-functions.') => 'job-functions',
            str_starts_with($this->value, 'certificates.') => 'certificates',
            str_starts_with($this->value, 'audit-logs.') => 'audit-logs',
            default => 'app-settings',
        };
    }

    public function groupLabel(): string
    {
        return __(match ($this->group()) {
            'compliance-dashboard' => 'Team training oversight',
            'compliance-reports' => 'Compliance reports',
            'compliance-events' => 'Compliance evidence',
            'courses' => 'Courses',
            'training-requirements' => 'Training requirements',
            'assignments' => 'Assignments',
            'people' => 'People',
            'departments' => 'Departments',
            'job-functions' => 'Job functions',
            'certificates' => 'Certificates',
            'audit-logs' => 'Audit logs',
            default => 'Application settings',
        });
    }

    public function label(): string
    {
        return __(match ($this) {
            self::ComplianceDashboardView => 'Monitor training for people you manage',
            self::ComplianceReportsExport => 'Export compliance reports',
            self::ComplianceEventsView => 'View the compliance evidence trail',
            self::CoursesView => 'View courses',
            self::CoursesCreate => 'Create courses',
            self::CoursesUpdate => 'Edit course drafts',
            self::CoursesPublish => 'Publish course versions',
            self::CoursesRetire => 'Retire courses and versions',
            self::RequirementsView => 'View training requirements',
            self::RequirementsCreate => 'Create training requirements',
            self::RequirementsUpdate => 'Edit training requirements',
            self::RequirementsActivate => 'Activate or pause training requirements',
            self::AssignmentsView => 'Review training assignments',
            self::AssignmentsCreate => 'Create manual assignments',
            self::AssignmentsCancel => 'Cancel assignments',
            self::AssignmentsWaive => 'Waive assignments',
            self::PeopleView => 'View people',
            self::PeopleManage => 'Manage people and organizational links',
            self::PeopleImport => 'Import people from spreadsheets',
            self::PeopleAssignAccessProfiles => 'Assign access profiles to people',
            self::PeopleInvite => 'Invite people through WorkOS',
            self::DepartmentsView => 'View departments',
            self::DepartmentsManage => 'Manage departments',
            self::JobFunctionsView => 'View job functions',
            self::JobFunctionsManage => 'Manage job functions',
            self::CertificatesView => 'View certificates',
            self::CertificatesRevoke => 'Revoke certificates',
            self::AuditLogsView => 'View administrative audit logs',
            self::AppSettingsView => 'View application settings',
            self::AppSettingsUpdate => 'Update application settings',
        });
    }

    public function description(): string
    {
        return __(match ($this) {
            self::ComplianceDashboardView => 'See training status, deadlines and completion indicators only for people within your management scope, including indirect reports.',
            self::ComplianceReportsExport => 'Download compliance data for the people you are allowed to oversee.',
            self::ComplianceEventsView => 'Inspect the evidence recorded while an accessible person completes training.',
            self::CoursesView => 'Browse published courses and their content.',
            self::CoursesCreate => 'Create new courses and their first draft.',
            self::CoursesUpdate => 'Change course content while it is still a draft.',
            self::CoursesPublish => 'Publish immutable course versions for assignment.',
            self::CoursesRetire => 'Remove courses or versions from future operational use.',
            self::RequirementsView => 'Review the rules that determine who must complete training.',
            self::RequirementsCreate => 'Create new training obligation rules.',
            self::RequirementsUpdate => 'Change the audience, schedule or settings of a training rule.',
            self::RequirementsActivate => 'Start or pause automatic assignment generation from a rule.',
            self::AssignmentsView => 'Open the training obligations of people within your management scope.',
            self::AssignmentsCreate => 'Assign a course directly to an accessible person. Course and person choices remain available here without opening their administrative modules.',
            self::AssignmentsCancel => 'Cancel an obligation while preserving its compliance history.',
            self::AssignmentsWaive => 'Excuse an obligation with a recorded reason.',
            self::PeopleView => 'Browse people within the organizational scope you are allowed to access.',
            self::PeopleManage => 'Edit people and their department, job function and management responsibilities.',
            self::PeopleImport => 'Add or update people in bulk from a spreadsheet.',
            self::PeopleAssignAccessProfiles => 'Choose what another person is allowed to see and do.',
            self::PeopleInvite => 'Send sign-in invitations to people through WorkOS.',
            self::DepartmentsView => 'Browse the company department structure.',
            self::DepartmentsManage => 'Create and change departments.',
            self::JobFunctionsView => 'Browse the company job functions.',
            self::JobFunctionsManage => 'Create and change job functions.',
            self::CertificatesView => 'View certificates belonging to people within your management scope.',
            self::CertificatesRevoke => 'Invalidate a certificate while preserving its history.',
            self::AuditLogsView => 'Review recorded administrative changes.',
            self::AppSettingsView => 'View company application settings.',
            self::AppSettingsUpdate => 'Change company application settings.',
        });
    }

    /**
     * Permissions that must be granted alongside this one. Selecting a dependent
     * permission in the access-profile editor also persists its prerequisites.
     *
     * @return list<self>
     */
    public function prerequisites(): array
    {
        return match ($this) {
            self::ComplianceDashboardView,
            self::CoursesView,
            self::RequirementsView,
            self::AssignmentsView,
            self::PeopleView,
            self::DepartmentsView,
            self::JobFunctionsView,
            self::CertificatesView,
            self::AuditLogsView,
            self::AppSettingsView => [],

            self::ComplianceReportsExport => [self::ComplianceDashboardView],
            self::ComplianceEventsView => [self::ComplianceDashboardView, self::AssignmentsView],

            self::CoursesCreate,
            self::CoursesUpdate,
            self::CoursesRetire => [self::CoursesView],
            self::CoursesPublish => [self::CoursesView, self::CoursesUpdate],

            self::RequirementsCreate,
            self::RequirementsUpdate => [self::RequirementsView, self::CoursesView],
            self::RequirementsActivate => [self::RequirementsView, self::RequirementsUpdate],

            // The assignment screen may use courses and people as scoped selector data.
            // That does not grant access to either administrative module.
            self::AssignmentsCreate => [self::AssignmentsView],
            self::AssignmentsCancel,
            self::AssignmentsWaive => [self::AssignmentsView],

            self::PeopleManage => [self::PeopleView, self::DepartmentsView, self::JobFunctionsView],
            self::PeopleImport => [self::PeopleView, self::DepartmentsView, self::JobFunctionsView],
            self::PeopleAssignAccessProfiles => [self::PeopleView],
            self::PeopleInvite => [self::PeopleView],
            self::DepartmentsManage => [self::DepartmentsView],
            self::JobFunctionsManage => [self::JobFunctionsView],

            self::CertificatesRevoke => [self::CertificatesView],
            self::AppSettingsUpdate => [self::AppSettingsView],
        };
    }

    /**
     * Expand a set of permissions with every prerequisite it implies.
     *
     * @param  iterable<self|string>  $permissions
     * @return list<string>
     */
    public static function withPrerequisites(iterable $permissions): array
    {
        $resolved = [];

        $add = function (self $permission) use (&$resolved, &$add): void {
            if (isset($resolved[$permission->value])) {
                return;
            }

            $resolved[$permission->value] = true;

            foreach ($permission->prerequisites() as $prerequisite) {
                $add($prerequisite);
            }
        };

        foreach ($permissions as $permission) {
            $add($permission instanceof self ? $permission : self::from($permission));
        }

        return array_keys($resolved);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
