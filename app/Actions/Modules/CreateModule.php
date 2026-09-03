<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Module;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateModule
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Account $actor, string $code, string $title, ?string $description = null): Module
    {
        if (! $actor->is_platform_admin) {
            throw new LogicException('Only a platform administrator can create shared content.');
        }

        $data = Validator::make(compact('code', 'title', 'description'), ['code' => ['required', 'string', 'max:80'], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']])->validate();
        $data['code'] = strtoupper(trim($data['code']));
        if (Module::query()->whereNull('company_id')->where('is_shared', true)->whereRaw('UPPER(code) = ?', [$data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => __('A shared module with this code already exists.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $data): Module {
                $module = Module::query()->create([
                    'company_id' => null,
                    'is_shared' => true,
                    'code' => strtoupper(trim($data['code'])),
                    'lineage_uuid' => (string) Str::uuid(),
                    'version_number' => 1,
                    'title' => trim($data['title']),
                    'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                    'status' => ModuleVersionStatus::Draft,
                    'type' => 'video',
                    'position' => 1,
                    'is_required' => true,
                    'minimum_watch_percentage' => 90,
                    'passing_score' => 70,
                    'published_by_account_id' => $actor->id,
                ]);
                $this->audit->log('shared_module.created', $module, after: ['code' => $module->code, 'version' => $module->version_number], platformActor: $actor);

                return $module;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['code' => __('A shared module with this code already exists.')]);
            }

            throw $exception;
        }
    }
}
