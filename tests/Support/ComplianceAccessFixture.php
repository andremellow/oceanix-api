<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ComplianceAccessFixture
{
    public static function create(bool $administrator = false): array
    {
        $company = app(TenantContext::class)->get() ?? Company::factory()->create(['name' => 'Atlantic Access Fixture', 'slug' => 'atlantic-access-fixture']);
        app(TenantContext::class)->set($company);
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::firstOrCreate(['key' => $administrator ? 'admin' : 'employee'], ['name' => $administrator ? 'Administrator' : 'Employee', 'is_protected' => true]);
        $user->roles()->attach($role);

        return [$user, $company];
    }

    public static function process(string $code): Process
    {
        $path = tempnam(sys_get_temp_dir(), 'access-process-');
        $bootstrap = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $app=require '.var_export(base_path('bootstrap/app.php'), true).'; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["database.default"=>"pgsql", "database.connections.pgsql"=>'.var_export(config('database.connections.pgsql'), true).']); ';
        file_put_contents($path, $bootstrap.$code);
        $process = new Process([PHP_BINARY, $path], base_path(), null, null, 20);
        $process->start();

        return $process;
    }

    private static function condition(callable $condition): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_stat_clear_snapshot()');
        }

        return (bool) $condition();
    }

    public static function await(callable $condition): void
    {
        $deadline = microtime(true) + 12;
        while (! self::condition($condition)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Concurrency barrier timed out.');
            }usleep(10000);
        }
    }
}
