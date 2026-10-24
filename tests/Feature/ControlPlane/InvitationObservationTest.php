<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\Role;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture as Fixture;
use Tests\Support\AccountOwnedInvitationFixture;

it('SC-10 SC-16 applies invitation-only snapshots atomically and preserves access and accepted history', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => ['fixture' => true]]);
    $person->forceFill(['invitation_generation' => 1, 'workos_invitation_id' => 'invitation_existing', 'invitation_accepted_history_at' => '2026-10-01T10:00:00Z'])->save();
    $before = $person->fresh()->getAttributes();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 1, 'observation_sequence' => 2, 'verified_at' => '2026-10-20T12:00:00Z', 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_existing', 'state' => 'expired', 'created_at' => '2026-10-01T00:00:00Z']];
    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $receipt = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'applied')->json();
    $this->putJson($url, $input)->assertExactJson($receipt);
    $after = $person->fresh()->getAttributes();
    foreach (['status', 'workos_active_membership', 'invitation_accepted_history_at'] as $field) {
        expect($after[$field])->toBe($before[$field]);
    }
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['observation_sequence'] = 1;
    $input['payload']['invitation']['state'] = 'pending';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'superseded');
    expect($person->fresh()->getAttributes())->toBe($after);
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['observation_sequence'] = 2;
    $input['payload']['invitation']['state'] = 'expired';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'superseded');
    $receipts = ProductOperationReceipt::count();
    $input['payload']['invitation']['state'] = 'pending';
    $this->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    $input['operation_id'] = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect($person->fresh()->getAttributes())->toBe($after)->and(ProductOperationReceipt::count())->toBe($receipts);
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['generation'] = 2;
    $input['payload']['observation_sequence'] = 1;
    $input['payload']['invitation'] = ['id' => 'invitation_new_generation', 'state' => 'revoked'];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful();
    $newer = $person->fresh()->getAttributes();
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['generation'] = 1;
    $input['payload']['observation_sequence'] = 900;
    $input['payload']['invitation'] = ['id' => 'invitation_existing', 'state' => 'accepted'];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'superseded');
    expect($person->fresh()->getAttributes())->toBe($newer);
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['generation'] = 2;
    $input['payload']['observation_sequence'] = 2;
    $input['payload']['invitation'] = ['id' => 'invitation_other_same_generation', 'state' => 'pending'];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect($person->fresh()->getAttributes())->toBe($newer);
});

it('SC-16 rolls back the projection if the atomic receipt cannot be persisted', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => []]);
    $person->forceFill(['invitation_generation' => 1, 'workos_invitation_id' => 'invitation_atomic'])->save();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 1, 'observation_sequence' => 1, 'verified_at' => '2026-10-20T00:00:00Z', 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_atomic', 'state' => 'expired', 'created_at' => '2026-10-01T00:00:00Z']];
    $before = $person->fresh()->getAttributes();
    ProductOperationReceipt::creating(fn () => throw new RuntimeException('Synthetic receipt persistence failure'));
    try {
        $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertStatus(500);
        expect($person->fresh()->getAttributes())->toBe($before)->and(ProductOperationReceipt::count())->toBe(0);
    } finally {
        ProductOperationReceipt::flushEventListeners();
    }
});

it('TA-03 serializes competing PostgreSQL receivers with authority before transaction and newer generation winning', function () {
    $directory = AccountOwnedInvitationFixture::directory();
    expect(DB::getDriverName())->toBe('pgsql')->and(config('database.connections.pgsql.database'))->toBe('account_owned_compliance_test')->and(config('database.connections.pgsql.host'))->toBe($directory.'/pgsocket')->and((int) config('database.connections.pgsql.port'))->toBe(15587);
    Fixture::fakeAccountActorAuthorization();
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => []]);
    $person->forceFill(['workos_invitation_id' => 'invitation_pg_old', 'invitation_generation' => 1, 'invitation_accepted_history_at' => '2026-01-01T00:00:00Z'])->save();
    $protected = $person->fresh()->only(['status', 'workos_active_membership', 'first_access_at', 'invitation_accepted_history_at']);
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 1, 'observation_sequence' => 99, 'verified_at' => now()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_pg_old', 'state' => 'expired']];
    $marker = $directory.'/receiver-contention-'.bin2hex(random_bytes(6));
    $script = $marker.'.php';
    $result = $marker.'.json';
    $bootstrap = '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $app=require '.var_export(base_path('bootstrap/app.php'), true).'; $app->useEnvironmentPath('.var_export($directory.'/noenv', true).'); $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["app.env"=>"testing","database.default"=>"pgsql","database.connections.pgsql.host"=>'.var_export($directory.'/pgsocket', true).',"database.connections.pgsql.port"=>15587,"database.connections.pgsql.database"=>"account_owned_compliance_test","database.connections.pgsql.username"=>"qa_operator","database.connections.pgsql.password"=>"","cache.default"=>"array","services.account.url"=>"https://account.example.test","services.account.token"=>"synthetic","services.account.actor_assertion_secret"=>"synthetic-actor-proof","services.account.invitation_ownership_enabled"=>true]); Illuminate\\Support\\Facades\\DB::purge("pgsql"); Illuminate\\Support\\Facades\\Http::preventStrayRequests(); ';
    $callback = 'Illuminate\\Support\\Facades\\Http::fake(["account.example.test/*"=>function($request){file_put_contents('.var_export($marker, true).',json_encode(["transaction_level"=>Illuminate\\Support\\Facades\\DB::transactionLevel()]));$data=$request->data();$proof=array_intersect_key($data,array_flip(["operation_id","actor_workos_user_id","account_company_uuid","product","kind","subject_id"]))+["payload_hash"=>$data["payload_hash"],"issued_at"=>now()->timestamp,"expires_at"=>now()->addMinute()->timestamp,"nonce"=>(string)Illuminate\\Support\\Str::uuid()];ksort($proof,SORT_STRING);$proof["signature"]=hash_hmac("sha256",json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"synthetic-actor-proof");return Illuminate\\Support\\Facades\\Http::response(["actor_assertion"=>$proof]);}]);';
    $request = '$input='.var_export($input, true).';$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);$request=Illuminate\\Http\\Request::create("/api/control-plane/v2/operations/".$input["operation_id"],"PUT",[],[],[],["CONTENT_TYPE"=>"application/json","HTTP_ACCEPT"=>"application/json","HTTP_AUTHORIZATION"=>'.var_export('Bearer '.$token, true).',"HTTP_IDEMPOTENCY_KEY"=>$input["operation_id"]],json_encode($input));$response=$kernel->handle($request);file_put_contents('.var_export($result, true).',json_encode(["status"=>$response->getStatusCode(),"body"=>json_decode($response->getContent(),true)]));';
    file_put_contents($script, $bootstrap.$callback.$request);
    DB::commit();
    DB::beginTransaction();
    DB::table('service_principals')->where('id', $principal->id)->lockForUpdate()->first();
    DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
    $pipes = [];
    $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), ['APP_ENV' => 'testing', 'PATH' => getenv('PATH')]);
    try {
        $deadline = microtime(true) + 10;
        while (! is_file($marker) && microtime(true) < $deadline) {
            usleep(20000);
            clearstatcache();
        }
        expect(is_file($marker))->toBeTrue()->and(json_decode(file_get_contents($marker), true)['transaction_level'])->toBe(0);
        usleep(100000);
        expect(is_file($result))->toBeFalse()->and(proc_get_status($process)['running'])->toBeTrue();
        $new = $input;
        $new['operation_id'] = (string) Str::uuid();
        $new['payload']['generation'] = 2;
        $new['payload']['observation_sequence'] = 1;
        $new['payload']['invitation'] = ['id' => 'invitation_pg_new', 'state' => 'revoked'];
        app(ApplyProductOperation::class)->handle($principal, $new);
        DB::commit();
        foreach ($pipes as $pipe) {
            fclose($pipe);
        } $exit = proc_close($process);
        $process = null;
        $response = json_decode(file_get_contents($result), true);
        expect($exit)->toBe(0)->and($response['status'])->toBe(201)->and($response['body']['confirmed_state']['result'])->toBe('superseded')->and($person->fresh()->invitation_generation)->toBe(2)->and($person->fresh()->invitation_observation_sequence)->toBe(1)->and($person->fresh()->workos_invitation_id)->toBe('invitation_pg_new')->and($person->fresh()->only(array_keys($protected)))->toEqual($protected)->and(ProductOperationReceipt::count())->toBe(2);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }foreach ([$script, $marker, $result] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
})->skip(fn () => getenv('ACCOUNT_INVITATION_PG_CONCURRENCY') !== '1', 'Executed separately on the exact disposable Compliance PostgreSQL test database.');

it('QA-R02 QA-R03 initializes a first exact generation-zero observation then rejects pointer replacement', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => []]);
    $person->forceFill(['workos_invitation_id' => null, 'invitation_generation' => 0, 'invitation_observation_sequence' => 0, 'invitation_observation_hash' => null, 'invitation_verified_at' => null])->save();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 0, 'observation_sequence' => 1, 'verified_at' => now()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_initial', 'state' => 'expired']];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'applied');
    $before = $person->fresh()->getAttributes();
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['observation_sequence'] = 2;
    $input['payload']['invitation']['id'] = 'invitation_replacement';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect($person->fresh()->getAttributes())->toBe($before);
});

it('TA-R01 rejects a higher-sequence new invitation after each initialized null guard', function (string $guard) {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => []]);
    $person->forceFill(['workos_invitation_id' => null, 'invitation_generation' => 0, 'invitation_observation_sequence' => 0, 'invitation_observation_hash' => null, 'invitation_verified_at' => null])->save();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 0, 'observation_sequence' => 1, 'verified_at' => now()->subDay()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => null, 'state' => 'none']];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful();
    $person->refresh()->forceFill(['invitation_observation_sequence' => in_array($guard, ['sequence', 'observed'], true) ? 1 : 0, 'invitation_observation_hash' => in_array($guard, ['hash', 'observed'], true) ? $person->invitation_observation_hash : null, 'invitation_verified_at' => in_array($guard, ['verified', 'observed'], true) ? $person->invitation_verified_at : null])->save();
    seedAccessCatalog();
    $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->sole()->id);
    PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $person->id, 'confirmed_enabled' => true, 'version' => 7, 'operation_id' => (string) Str::uuid()]);
    $course = Course::factory()->create();
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->completed()->create(['user_id' => $person->id]);
    Certificate::factory()->create(['user_id' => $person->id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    $tables = ['users', 'person_account_links', 'product_operation_receipts', 'person_product_accesses', 'role_user', 'user_training_assignments', 'certificates'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['observation_sequence'] = 99;
    $input['payload']['invitation'] = ['id' => 'invitation_forbidden_replacement', 'state' => 'expired'];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all())->toBe($before);
})->with(['sequence', 'hash', 'verified', 'observed']);
