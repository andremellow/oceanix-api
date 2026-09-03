<?php

namespace App\Actions\SharedContent;

use App\Enums\CourseStatus;
use App\Enums\ModuleStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class ArchiveSharedContent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Course|Module */
    public function handle(Course|Module $content, Account $actor, string $reason): Model
    {
        if (! $actor->is_platform_admin || ! $content->is_shared || $content->company_id !== null) {
            throw new LogicException('Only a platform administrator can archive shared content.');
        }
        if (trim($reason) === '') {
            throw new LogicException('An archive reason is required.');
        }

        $archivedStatus = match (true) {
            $content instanceof Course => CourseStatus::Archived,
            $content instanceof ModuleVersion => ModuleVersionStatus::Archived,
            default => ModuleStatus::Archived,
        };
        if (($content instanceof Module && $content->lineage_archived_at !== null)
            || (! $content instanceof Module && $content->status === $archivedStatus)) {
            return $content;
        }

        return DB::transaction(function () use ($content, $actor, $reason, $archivedStatus): Model {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can archive shared content.');
            }

            if ($content instanceof Module) {
                $lineageRootId = $content instanceof ModuleVersion ? ($content->module_id ?? $content->id) : $content->id;
                Module::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($lineageRootId);
            }
            $locked = $content->newQuery()->withoutGlobalScopes()->lockForUpdate()->findOrFail($content->id);
            $before = $locked instanceof Module
                ? ['lineage_archived_at' => $locked->lineage_archived_at?->toISOString()]
                : ['status' => $locked->status instanceof \BackedEnum ? $locked->status->value : (string) $locked->status];
            if ($locked instanceof Module) {
                if ($locked->lineage_archived_at !== null) {
                    return $locked;
                }
                ModuleVersion::query()->withoutGlobalScopes()->where('lineage_uuid', $locked->lineage_uuid)->lockForUpdate()->get()
                    ->each->update(['lineage_archived_at' => now()]);
                $locked->refresh();
            } else {
                $locked->update(['status' => $archivedStatus]);
            }
            $this->audit->log(
                $locked instanceof Course ? 'shared_course.archived' : 'shared_module.archived',
                $locked,
                before: $before,
                after: $locked instanceof Module
                    ? ['lineage_archived_at' => $locked->lineage_archived_at?->toISOString()]
                    : ['status' => $archivedStatus->value],
                metadata: ['reason' => trim($reason)],
                platformActor: $authorized,
            );

            return $locked->refresh();
        }, 3);
    }
}
