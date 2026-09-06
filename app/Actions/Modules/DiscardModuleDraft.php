<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Modules\ModuleLineageLock;
use App\Services\Modules\SharedModuleDraftWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DiscardModuleDraft
{
    public function __construct(private readonly ModuleLineageLock $lineageLock, private readonly AuditLogger $audit, private readonly SharedModuleDraftWriter $writer) {}

    public function handle(ModuleVersion $version, Account $actor, string $reason, string $expectedRevision): ModuleVersion
    {
        $reason = trim($reason);
        Validator::make(['discardReason' => $reason], ['discardReason' => ['required', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($version, $actor, $reason, $expectedRevision): ModuleVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            abort_unless($authorized, 403);
            $lineage = $this->lineageLock->versions([$version->id]);
            $draft = $lineage->firstWhere('id', $version->id);
            abort_unless($draft?->is_shared && $draft->company_id === null, 404);

            if (! $draft->isEditable() || $lineage->contains(fn ($row) => $row->lineage_archived_at !== null)) {
                throw ValidationException::withMessages(['discard' => __('This module draft is no longer available. Reload the page before trying again.')]);
            }
            if (! hash_equals($this->revision($draft), $expectedRevision)) {
                throw ValidationException::withMessages(['discard' => __('This draft changed elsewhere. Reload the page before trying again.')]);
            }
            // Composition writers acquire the same lineage lock before attaching a module.
            // Do not take course locks here: course operations lock courses before lineages.
            if ($draft->course_version_id !== null || CourseVersionModule::query()->withoutGlobalScopes()->where('lesson_id', $draft->id)->exists()) {
                throw ValidationException::withMessages(['discard' => __('Remove this draft from its course compositions before discarding it.')]);
            }

            $draft->update(['status' => ModuleVersionStatus::Discarded]);
            $this->audit->log('shared_module.draft_discarded', $draft,
                before: ['status' => ModuleVersionStatus::Draft->value],
                after: ['status' => ModuleVersionStatus::Discarded->value, 'reason' => $reason],
                platformActor: $authorized,
            );

            return $draft->refresh();
        }, 3);
    }

    public function revision(ModuleVersion $version): string
    {
        return hash('sha256', json_encode([
            $version->id,
            $version->getRawOriginal('status'),
            $this->writer->revision($version),
            $version->videos()->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }
}
