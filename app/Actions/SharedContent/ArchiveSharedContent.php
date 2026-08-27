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
        if ($content->status === $archivedStatus) {
            return $content;
        }

        return DB::transaction(function () use ($content, $actor, $reason, $archivedStatus): Model {
            $locked = $content->newQuery()->withoutGlobalScopes()->lockForUpdate()->findOrFail($content->id);
            $before = $locked->status instanceof \BackedEnum ? $locked->status->value : (string) $locked->status;
            $locked->update(['status' => $archivedStatus]);
            $this->audit->log(
                $locked instanceof Course ? 'shared_course.archived' : 'shared_module.archived',
                $locked,
                before: ['status' => $before],
                after: ['status' => $archivedStatus->value],
                metadata: ['reason' => trim($reason)],
                platformActor: $actor,
            );

            return $locked->refresh();
        });
    }
}
