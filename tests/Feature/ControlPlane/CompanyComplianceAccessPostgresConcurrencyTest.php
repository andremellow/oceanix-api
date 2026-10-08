<?php

use App\Actions\ControlPlane\ChangeCompanyComplianceAccess;
use App\Models\AccountCompanyBinding;
use App\Models\CompanyAccessReceipt;
use App\Models\ServicePrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ComplianceAccessFixture;

it('SC-14 serializes competing receiver commands and preserves old receipts on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires isolated PostgreSQL.');
    }
    expect(config('database.connections.pgsql.database'))->toBe('compliance_access_qa');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_race']);
    $uuid = (string) Str::uuid();
    AccountCompanyBinding::create(['account_company_uuid' => $uuid, 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_race']);
    $principal = ServicePrincipal::create(['name' => 'Race', 'product' => 'compliance', 'environment' => app()->environment(), 'active' => true]);
    $key = (string) Str::uuid();
    $input = ['account_company_uuid' => $uuid, 'operation_id' => $key, 'workos_organization_id' => 'org_race', 'compliance_company_public_id' => $company->public_id, 'enabled' => false, 'version' => 1, 'actor_workos_user_id' => 'user_race', 'correlation_id' => $key];
    DB::commit();
    DB::beginTransaction();
    DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
    $seed = '$principal=\\App\\Models\\ServicePrincipal::find('.$principal->id.');';
    $one = ComplianceAccessFixture::process('DB::statement("SET application_name = \'receiver_first\'");'.$seed.'app(\\App\\Actions\\ControlPlane\\ChangeCompanyComplianceAccess::class)->handle($principal,'.var_export($input, true).');');
    ComplianceAccessFixture::await(fn () => DB::selectOne("select count(*) as n from pg_stat_activity where application_name='receiver_first' and wait_event_type='Lock'")->n > 0);
    $other = (string) Str::uuid();
    $competing = [...$input, 'enabled' => true, 'operation_id' => $other, 'correlation_id' => $other];
    $two = ComplianceAccessFixture::process('DB::statement("SET application_name = \'receiver_second\'");'.$seed.'try{app(\\App\\Actions\\ControlPlane\\ChangeCompanyComplianceAccess::class)->handle($principal,'.var_export($competing, true).');exit(9);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $e){if($e->getStatusCode()!==409)throw $e;echo "conflict";}');
    ComplianceAccessFixture::await(fn () => DB::selectOne("select count(*) as n from pg_stat_activity where application_name='receiver_second' and wait_event_type='Lock'")->n > 0);
    DB::commit();
    $one->wait();
    $two->wait();
    expect($one->getExitCode())->toBe(0, $one->getErrorOutput().$one->getOutput())->and($two->getExitCode())->toBe(0, $two->getErrorOutput().$two->getOutput());
    expect($company->fresh()->compliance_access_enabled)->toBeFalse()->and(CompanyAccessReceipt::where('company_id', $company->id)->count())->toBe(1);
    $next = (string) Str::uuid();
    $action = app(ChangeCompanyComplianceAccess::class);
    $action->handle($principal, [...$input, 'enabled' => true, 'version' => 2, 'operation_id' => $next, 'correlation_id' => $next]);
    expect($action->handle($principal, $input)->response['enabled'])->toBeFalse()->and($company->fresh()->compliance_access_enabled)->toBeTrue();
});
