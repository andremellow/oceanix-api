<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Models\AccountRequestOperation;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\PersonAccountLink;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class ImportPeople
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{row: int, name: string, email: string, job_function: string, department: string}>  $rows
     * @param  array<string, string>  $jobFunctionMappings
     * @param  array<string, string>  $departmentMappings
     * @return array{created: int, existing: int, job_functions_created: int, departments_created: int}
     */
    public function handle(array $rows, array $jobFunctionMappings, array $departmentMappings): array
    {
        Gate::authorize(Permission::PeopleImport->value);

        $actor = auth()->user();
        [$jobFunctions, $jobFunctionsCreated, $departments, $departmentsCreated] = DB::transaction(function () use ($jobFunctionMappings, $departmentMappings) {
            [$functions, $functionCount] = $this->resolveMappings(JobFunction::class, $jobFunctionMappings);
            [$departments, $departmentCount] = $this->resolveMappings(Department::class, $departmentMappings);

            return [$functions, $functionCount, $departments, $departmentCount];
        });
        $employeeRole = Role::query()->where('key', 'employee')->firstOrFail();
        $result = ['created' => 0, 'existing' => 0, 'pending' => 0, 'job_functions_created' => $jobFunctionsCreated, 'departments_created' => $departmentsCreated, 'rows' => []];
        foreach ($rows as $row) {
            Gate::authorize(Permission::PeopleImport->value);
            $user = User::query()->where('email', $row['email'])->first();
            $pending = $user ? AccountRequestOperation::where('company_id', $actor->company_id)->where('user_id', $user->id)->where('kind', 'person.register')->where('state', '!=', 'confirmed')->first() : null;
            if (! $user || $pending) {
                $operationId = $pending?->id ?? Uuid::uuid5(Uuid::NAMESPACE_URL, 'company-registration:'.$actor->company_id.':'.strtolower($row['email']).':'.$row['name'])->toString();
                $operation = app(RegisterPerson::class)->handle($actor, $operationId, $pending?->payload['payload']['name'] ?? $row['name'], $row['email'], import: true);
                if ($operation->state !== 'confirmed') {
                    $result['pending']++;
                    $result['rows'][] = ['row' => $row['row'], 'outcome' => $operation->state, 'operation_id' => $operation->id];

                    continue;
                }
                $user = User::findOrFail($operation->user_id);
                $result['created']++;
            } else {
                if (! PersonAccountLink::where('company_id', $user->company_id)->where('user_id', $user->id)->exists()) {
                    $adoption = app(AdoptPeopleIntoAccount::class)->handle($actor, dryRun: false, personIds: [$user->id], import: true);
                    if (($adoption[0]['outcome'] ?? 'unknown') !== 'confirmed') {
                        $result['pending']++;
                        $result['rows'][] = ['row' => $row['row'], 'outcome' => $adoption[0]['outcome'] ?? 'unknown'];

                        continue;
                    }
                }
                $result['existing']++;
            }
            // Each successful row commits independently; remote uncertainty never rolls it back.
            DB::transaction(function () use ($user, $row, $employeeRole, $jobFunctions, $departments) {
                $user->roles()->syncWithoutDetaching($employeeRole);
                if ($row['job_function'] !== '') {
                    $user->jobFunctions()->syncWithoutDetaching($jobFunctions[$row['job_function']]);
                }
                if ($row['department'] !== '') {
                    $user->departments()->syncWithoutDetaching($departments[$row['department']]);
                }
            });
            $result['rows'][] = ['row' => $row['row'], 'outcome' => 'confirmed'];
        }
        $this->audit->log('people.imported', metadata: array_diff_key($result, ['rows' => true]) + ['rows' => count($rows)]);

        return $result;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, string>  $mappings
     * @return array{0: array<string, int>, 1: int}
     */
    private function resolveMappings(string $modelClass, array $mappings): array
    {
        $resolved = [];
        $created = 0;

        foreach ($mappings as $source => $selection) {
            if ($selection === 'create') {
                $model = $modelClass::query()->create([
                    'name' => $source,
                    'code' => $this->uniqueCode($modelClass, $source),
                    'status' => 'active',
                ]);
                $created++;
            } else {
                $model = $modelClass::query()->find($selection);

                if ($model === null) {
                    throw new RuntimeException(__('An organization mapping is no longer available. Refresh the preview.'));
                }
            }

            $resolved[$source] = (int) $model->getKey();
        }

        return [$resolved, $created];
    }

    /** @param class-string<Model> $modelClass */
    private function uniqueCode(string $modelClass, string $name): string
    {
        $base = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->limit(32, '')->toString() ?: 'ITEM';
        $code = $base;
        $suffix = 2;

        while ($modelClass::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 35, '').'-'.$suffix++;
        }

        return $code;
    }
}
