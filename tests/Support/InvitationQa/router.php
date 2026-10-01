<?php

use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\QueueWorkosSynchronization;
use App\Enums\AssignmentStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Models\Company;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Services\Requirements\AssignmentMaterializationService;
use App\Services\SocialLogin\OauthStateSigner;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// This router is an isolated executable QA fixture, never loaded by production routes.
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_file($root.'/public'.$path) && $path !== '/') {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('OCEANIX_INVITATION_QA') !== 'isolated' || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== '/private/tmp/oceanix-invite-qa.sqlite') {
    http_response_code(503);
    exit('Isolated QA configuration required.');
}
config(['session.driver' => 'file', 'session.files' => '/private/tmp/oceanix-invite-qa-sessions', 'queue.default' => 'database', 'services.workos.api_key' => 'isolated-stub-key', 'services.workos.client_id' => 'isolated-client']);
@mkdir('/private/tmp/oceanix-invite-qa-sessions', 0700, true);
require __DIR__.'/fixtures.php';
qaInstallProviderStub();
Route::middleware('web')->group(function () {
    Route::get('/qa/reset', fn () => qaReset());
    Route::get('/qa/import-fixture', function () {
        $file = '/private/tmp/oceanix-invite-import.xlsx';
        $rows = [['Name', 'Email', 'Job function', 'Department'], ['QA Imported Invited Person', 'qa-imported@qa.example', 'QA Offshore technicians', 'QA Operations']];
        $xml = '';
        foreach ($rows as $index => $values) {
            $row = $index + 1;
            $xml .= '<row r="'.$row.'">';
            foreach ($values as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).$row.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->close();

        return response()->download($file);
    });
    Route::post('/qa/queue-sync', function () {
        app(TenantContext::class)->set(Company::where('slug', 'invitation-qa')->firstOrFail());

        return app(QueueWorkosSynchronization::class)->handle();
    });
    Route::post('/qa/queue-invitations', function () {
        app(TenantContext::class)->set(Company::where('slug', 'invitation-qa')->firstOrFail());

        return ['queued' => app(QueueWorkosInvitations::class)->handle(request()->input('ids', []), request()->boolean('all'))];
    });
    Route::get('/qa/platform-login', function () {
        $person = User::withoutGlobalScope('company')->where('name', 'Platform Entry Recipient')->firstOrFail();
        Auth::logout();
        session(['platform_account_id' => $person->account_id]);
        session()->regenerate();

        return redirect()->route('platform.dashboard');
    });
    Route::get('/qa/switch-form', function () {
        $token = csrf_token();

        return '<form method="POST" action="/switch-company/invitation-other"><input type="hidden" name="_token" value="'.$token.'"><button>Switch to isolated other company</button></form>';
    });
    Route::get('/qa/block-switch', function () {
        User::withoutGlobalScope('company')->where('name', 'Switch Target Recipient')->update(['status' => request()->query('status', 'suspended')]);

        return ['fixture' => 'target access blocked'];
    });
    Route::get('/qa/complete-cycle', function () {
        app(TenantContext::class)->set(Company::where('slug', 'invitation-qa')->firstOrFail());
        $requirement = TrainingRequirement::firstOrFail();
        $requirement->update(['frequency_type' => FrequencyType::Months, 'frequency_value' => 1, 'assignment_lead_days' => 30, 'renewal_basis' => RenewalBasis::FromCompletion]);
        $requirement->assignments()->update(['status' => AssignmentStatus::Completed, 'completed_at' => now()->subMonths(2)]);

        return ['fixture' => 'satisfied cycle ready for renewal'];
    });
    Route::get('/qa/state', fn () => qaState());
    Route::get('/qa/control', function () {
        $state = qaProviderState();
        foreach (['failure', 'race', 'uncertain', 'malformed'] as $key) {
            if (request()->has($key)) {
                $state[$key] = request()->query($key);
            }
        }
        qaSaveProviderState($state);

        return ['controls' => Arr::only($state, ['failure', 'race', 'uncertain', 'malformed'])];
    });
    Route::get('/qa/login/{name}', function (string $name) {
        $company = Company::where('slug', 'invitation-qa')->firstOrFail();
        app(TenantContext::class)->set($company);
        $person = User::where('name', $name)->firstOrFail();
        Auth::login($person);
        session(['company_id' => $company->id]);
        session()->regenerate();

        return redirect('/c/invitation-qa/people');
    });
    Route::get('/qa/callback/{name}', function (string $name) {
        $company = Company::where('slug', 'invitation-qa')->firstOrFail();
        app(TenantContext::class)->set($company);
        session(['company_id' => $company->id, 'workos_oauth_state' => 'qa-nonce']);

        return redirect()->route('auth.workos.callback', ['code' => $name, 'state' => app(OauthStateSigner::class)->issue('qa-nonce')]);
    });
    Route::get('/qa/revoke/{name}', function (string $name) {
        app(TenantContext::class)->set(Company::where('slug', 'invitation-qa')->firstOrFail());
        User::where('name', $name)->firstOrFail()->roles()->update(['archived_at' => now()]);

        return ['revoked' => $name];
    });
    Route::get('/qa/drain', function () {
        $previous = app(TenantContext::class)->get();
        $company = Company::where('slug', 'invitation-qa')->firstOrFail();
        app(TenantContext::class)->set($company);
        $before = DB::table('jobs')->count();
        $runs = WorkosSyncRun::whereIn('status', ['queued', 'running'])->count();
        $attempts = WorkosInvitationAttempt::whereIn('status', ['queued', 'running', 'sending'])->count();
        while ($job = app('queue')->connection('database')->pop()) {
            app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
        }
        $restored = app(TenantContext::class)->get()?->id === $company->id;
        $previous === null ? app(TenantContext::class)->clear() : app(TenantContext::class)->set($previous);

        return ['runs' => $runs, 'attempts' => $attempts, 'queued_jobs_processed' => $before - DB::table('jobs')->count(), 'job_context_restored' => $restored];
    });
    Route::get('/qa/materialize', function () {
        app(TenantContext::class)->set(Company::where('slug', 'invitation-qa')->firstOrFail());

        return app(AssignmentMaterializationService::class)->materializeAll();
    });
    Route::get('/qa/transition', function () {
        (require database_path('migrations/2026_10_01_000002_reclassify_people_without_access_evidence.php'))->up();

        return qaState();
    });
});
$app->handleRequest(Request::capture());
