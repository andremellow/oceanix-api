<?php

use App\Actions\Auth\AuthenticatePlatformAccount;
use App\Actions\Auth\AuthenticateSocialLogin;
use App\Actions\Auth\RecordTenantAccess;
use App\Actions\ControlPlane\ApplyInvitationObservation;
use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Actions\ControlPlane\ChangeCompanyComplianceAccess;
use App\Actions\ControlPlane\EnsureCompany;
use App\Actions\ControlPlane\MapExistingAccountPerson;
use App\Actions\ControlPlane\MigrateAccountPerson;
use App\Actions\People\AdoptPeopleIntoAccount;
use App\Actions\People\AssignAccessProfile;
use App\Actions\People\ChangePersonProductAccess;
use App\Actions\People\DispatchWorkosOperation;
use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\QueueWorkosSynchronization;
use App\Actions\People\RegisterPerson;
use App\Actions\Tenancy\SwitchCompany;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\WorkosController;
use App\Http\Controllers\ControlPlane\CompanyComplianceAccessController;
use App\Http\Controllers\ControlPlane\MigrateAccountPersonController;
use App\Http\Controllers\ControlPlane\ProductActorAuthorizationController;
use App\Http\Controllers\ControlPlane\ProductOperationController;
use App\Http\Controllers\ControlPlane\ProductOperationStatusController;
use App\Http\Controllers\ControlPlane\ProvisionCompanyController;
use App\Http\Middleware\EnsureUserCanAccessControlCenter;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetLocale;
use App\Jobs\ReconcileWorkosInvitations;
use App\Jobs\SendWorkosInvitation;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyAccessReceipt;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\ProvisioningReceipt;
use App\Models\Role;
use App\Models\ServicePrincipal;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Services\Account\AccountUserClient;
use App\Services\Audit\AuditLogger;
use App\Services\People\PeopleDirectory;
use App\Services\Tenancy\CompanyComplianceAccess;
use Oceanix\Platform\Actions\ControlPlane\ChangeCompanyProductAccess;
use Oceanix\Platform\Http\Controllers\ControlPlane\CompanyProductAccessController;
use Oceanix\Platform\Services\Tenancy\CompanyProductAccess;

return [
    'product' => 'compliance',
    'operation_namespaces' => ['platform' => 'platform', 'platform-invite' => 'platform-invite', 'mapping-baseline-v1-source' => 'mapping-baseline-v1-source', 'mapping' => 'mapping'],
    'migrations' => false,
    'authorization' => ['register_gates' => false, 'catalog_admin_bypass' => false],
    'auth_routes' => ['logout_path' => '/c/{company:slug}/logout'],
    'routes' => ['auth' => true, 'control_plane' => false],
    'jobs' => ['SendWorkosInvitation' => SendWorkosInvitation::class, 'ReconcileWorkosInvitations' => ReconcileWorkosInvitations::class],
    'legacy_bindings' => [
        Oceanix\Platform\Actions\People\QueueWorkosSynchronization::class => QueueWorkosSynchronization::class,
        Oceanix\Platform\Actions\People\QueueWorkosInvitations::class => QueueWorkosInvitations::class,
        Oceanix\Platform\Actions\People\ReconcileWorkosInvitations::class => App\Actions\People\ReconcileWorkosInvitations::class,
        Oceanix\Platform\Actions\People\SendWorkosInvitation::class => App\Actions\People\SendWorkosInvitation::class,
        Oceanix\Platform\Actions\People\DispatchWorkosOperation::class => DispatchWorkosOperation::class,
        Oceanix\Platform\Actions\People\AssignAccessProfile::class => AssignAccessProfile::class,
        Oceanix\Platform\Actions\People\ChangePersonProductAccess::class => ChangePersonProductAccess::class,
        Oceanix\Platform\Actions\People\RegisterPerson::class => RegisterPerson::class,
        Oceanix\Platform\Actions\People\AdoptPeopleIntoAccount::class => AdoptPeopleIntoAccount::class,
        Oceanix\Platform\Http\Controllers\ControlPlane\ProductOperationStatusController::class => ProductOperationStatusController::class,
        Oceanix\Platform\Http\Controllers\ControlPlane\ProvisionCompanyController::class => ProvisionCompanyController::class,
        Oceanix\Platform\Http\Controllers\ControlPlane\ProductOperationController::class => ProductOperationController::class,
        Oceanix\Platform\Http\Controllers\ControlPlane\ProductActorAuthorizationController::class => ProductActorAuthorizationController::class,
        Oceanix\Platform\Http\Controllers\ControlPlane\MigrateAccountPersonController::class => MigrateAccountPersonController::class,
        CompanyProductAccessController::class => CompanyComplianceAccessController::class,
        Oceanix\Platform\Actions\ControlPlane\ApplyProductOperation::class => ApplyProductOperation::class,
        Oceanix\Platform\Actions\ControlPlane\MigrateAccountPerson::class => MigrateAccountPerson::class,
        ChangeCompanyProductAccess::class => ChangeCompanyComplianceAccess::class,
        Oceanix\Platform\Actions\ControlPlane\AuthorizeProductActor::class => AuthorizeProductActor::class,
        Oceanix\Platform\Actions\ControlPlane\EnsureCompany::class => EnsureCompany::class,
        Oceanix\Platform\Actions\ControlPlane\ApplyInvitationObservation::class => ApplyInvitationObservation::class,
        Oceanix\Platform\Actions\ControlPlane\MapExistingAccountPerson::class => MapExistingAccountPerson::class,
        Oceanix\Platform\Services\People\PeopleDirectory::class => PeopleDirectory::class,
        Oceanix\Platform\Services\Account\AccountUserClient::class => AccountUserClient::class,
        Oceanix\Platform\Http\Middleware\SetLocale::class => SetLocale::class,
        Oceanix\Platform\Http\Middleware\EnsureUserCanAccessControlCenter::class => EnsureUserCanAccessControlCenter::class,
        Oceanix\Platform\Http\Middleware\EnsureUserIsAdmin::class => EnsureUserIsAdmin::class,
        Oceanix\Platform\Http\Middleware\EnsureUserHasPermission::class => EnsureUserHasPermission::class,
        Oceanix\Platform\Http\Controllers\Auth\WorkosController::class => WorkosController::class,
        Oceanix\Platform\Actions\Tenancy\SwitchCompany::class => SwitchCompany::class,
        Oceanix\Platform\Actions\Auth\RecordTenantAccess::class => RecordTenantAccess::class,
        Oceanix\Platform\Actions\Auth\AuthenticateSocialLogin::class => AuthenticateSocialLogin::class,
        Oceanix\Platform\Actions\Auth\AuthenticatePlatformAccount::class => AuthenticatePlatformAccount::class,
        Oceanix\Platform\Services\Audit\AuditLogger::class => AuditLogger::class,
        Oceanix\Platform\Services\People\PersonProductAccess::class => App\Services\People\PersonProductAccess::class,
        CompanyProductAccess::class => CompanyComplianceAccess::class,
    ],
    'route_names' => ['dashboard' => 'dashboard'],
    'ui' => [
        'enabled' => true,
        'layout' => 'layouts::app',
        'login_layout' => 'layouts::guest',
        'logo' => 'images/oceanix-logo.png',
        'middleware' => [EnsureUserCanAccessControlCenter::class],
        'components' => ['people' => 'organization.people', 'person' => 'organization.person'],
        'person_label' => 'Employee',
        'people_search_placeholder' => 'Name, email or employee ID',
        'profile_placeholder' => 'Training coordinator',
        'product_removal_message' => 'This removes only Compliance access for this company. Identity, other products and operational evidence are retained.',
        'slots' => [
            'login_local' => 'platform-extensions.login_local',
            'people_actions' => 'platform-extensions.people_actions',
            'people_cells_after_access' => 'platform-extensions.people_cells_after_access',
            'people_cells_before_status' => 'platform-extensions.people_cells_before_status',
            'people_empty_actions' => 'platform-extensions.people_empty_actions',
            'people_filters' => 'platform-extensions.people_filters',
            'people_headers_after_access' => 'platform-extensions.people_headers_after_access',
            'people_headers_before_status' => 'platform-extensions.people_headers_before_status',
            'person_actions' => 'platform-extensions.person_actions',
            'person_after_profiles' => 'platform-extensions.person_after_profiles',
            'person_before_profiles' => 'platform-extensions.person_before_profiles',
        ],
    ],
    'permission_catalog' => [Permission::class],
    'user_status_enum' => UserStatus::class,
    'models' => ['AccountCompanyBinding' => AccountCompanyBinding::class, 'AccountRequestOperation' => AccountRequestOperation::class, 'PersonAccountLink' => PersonAccountLink::class, 'PersonProductAccess' => PersonProductAccess::class, 'ServicePrincipal' => ServicePrincipal::class, 'ProductOperationReceipt' => ProductOperationReceipt::class, 'ProvisioningReceipt' => ProvisioningReceipt::class, 'CompanyAccessReceipt' => CompanyAccessReceipt::class, 'WorkosInvitationAttempt' => WorkosInvitationAttempt::class, 'WorkosSyncRun' => WorkosSyncRun::class, 'AuditLog' => AuditLog::class, 'User' => User::class, 'Company' => Company::class, 'Account' => Account::class, 'Role' => Role::class, 'Permission' => App\Models\Permission::class],
    'account_config' => 'services.account',
    'bootstrap_config' => 'oceanix',
    'access' => [
        'disabled_message' => 'Compliance access is disabled.',
        'person_exempt_routes' => ['platform.*', 'login', 'tenant.login', 'logout', 'locale.update', 'certificates.verify'],
        'company_exempt_routes' => ['platform.*', 'login', 'auth.workos.platform.redirect', 'logout', 'locale.update', 'certificates.verify'],
        'target_company_routes' => ['platform.companies.enter', 'platform.companies.courses.show'],
        'exempt_paths' => ['preview/courses/*'],
    ],
    'company_access' => [
        'enabled_column' => 'compliance_access_enabled',
        'version_column' => 'compliance_access_version',
        'binding_column' => 'compliance_company_id',
        'public_id_key' => 'compliance_company_public_id',
        'endpoint' => 'compliance-access',
    ],
];
