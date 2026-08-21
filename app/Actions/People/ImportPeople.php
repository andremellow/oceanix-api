<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

        return DB::transaction(function () use ($rows, $jobFunctionMappings, $departmentMappings): array {
            [$jobFunctions, $jobFunctionsCreated] = $this->resolveMappings(JobFunction::class, $jobFunctionMappings);
            [$departments, $departmentsCreated] = $this->resolveMappings(Department::class, $departmentMappings);
            $employeeRole = Role::query()->where('key', 'employee')->firstOrFail();
            $created = 0;
            $existing = 0;

            foreach ($rows as $row) {
                $user = User::query()->where('email', $row['email'])->first();

                if ($user === null) {
                    $user = User::query()->create([
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'email_verified_at' => now(),
                        'status' => UserStatus::Active,
                    ]);
                    $created++;
                } else {
                    $existing++;
                }

                $user->roles()->syncWithoutDetaching($employeeRole);

                if ($row['job_function'] !== '') {
                    $user->jobFunctions()->syncWithoutDetaching($jobFunctions[$row['job_function']]);
                }

                if ($row['department'] !== '') {
                    $user->departments()->syncWithoutDetaching($departments[$row['department']]);
                }
            }

            $result = [
                'created' => $created,
                'existing' => $existing,
                'job_functions_created' => $jobFunctionsCreated,
                'departments_created' => $departmentsCreated,
            ];

            $this->audit->log('people.imported', metadata: $result + ['rows' => count($rows)]);

            return $result;
        });
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
