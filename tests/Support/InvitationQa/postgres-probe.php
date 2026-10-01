<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('OCEANIX_INVITATION_QA') !== 'isolated' || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'oceanix_invitation_probe' || config('database.connections.pgsql.host') !== '/private/tmp/oceanix-invite-pg') {
    throw new RuntimeException('Disposable PostgreSQL configuration required.');
}
use App\Actions\People\QueueWorkosInvitations;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

Queue::fake();
if (($argv[1] ?? '') === 'child') {
    app(TenantContext::class)->set(Company::findOrFail($argv[2]));
    Auth::login(User::findOrFail($argv[3]));
    echo app(QueueWorkosInvitations::class)->handle([(int) $argv[4]]);
    exit;
}
Artisan::call('migrate:fresh', ['--force' => true]);
$company = Company::factory()->create(['workos_organization_id' => 'org_probe']);
app(TenantContext::class)->set($company);
$actor = User::factory()->create();
$actor->roles()->attach(Role::factory()->create(['key' => 'admin']));
$person = User::factory()->create(['workos_user_id' => null]);
DB::beginTransaction();
User::whereKey($person->id)->lockForUpdate()->firstOrFail();
$command = [PHP_BINARY, __FILE__, 'child', (string) $company->id, (string) $actor->id, (string) $person->id];
$a = new Process($command, base_path());
$b = new Process($command, base_path());
$a->start();
$b->start();
usleep(500000);
if (! $a->isRunning() || ! $b->isRunning()) {
    throw new RuntimeException('Children did not wait on locked person row.');
}
DB::commit();
$a->wait();
$b->wait();
if (! $a->isSuccessful() || ! $b->isSuccessful()) {
    throw new RuntimeException('Child execution failed: '.$a->getErrorOutput().$b->getErrorOutput());
}
$outputs = [trim($a->getOutput()), trim($b->getOutput())];
sort($outputs);
if ($outputs !== ['0', '1'] || WorkosInvitationAttempt::count() !== 1) {
    throw new RuntimeException('Open attempt exclusion failed.');
}
echo json_encode(['database' => 'disposable PostgreSQL', 'row_lock_blocks_competing_workers' => true, 'concurrent_results' => $outputs, 'open_attempts' => 1]);
