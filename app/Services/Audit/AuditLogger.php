<?php

namespace App\Services\Audit;

use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Administrative trail for configuration changes — separate from execution evidence. */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?Account $platformActor = null,
    ): AuditLog {
        if ($platformActor !== null) {
            $id = DB::table('audit_logs')->insertGetId([
                'company_id' => null,
                'actor_id' => null,
                'platform_account_id' => $platformActor->id,
                'action' => $action,
                'auditable_type' => $subject !== null ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
                'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'created_at' => now(),
            ]);

            return AuditLog::query()->withoutGlobalScopes()->findOrFail($id);
        }

        return AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
