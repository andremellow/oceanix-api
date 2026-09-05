<?php

namespace App\Actions\Modules;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateAndAttachSharedModule
{
    public function __construct(private readonly CreateModule $createModule, private readonly AuditLogger $audit) {}

    public function handle(CourseVersion $version, Account $actor, string $code, string $title, ?string $description = null): Module
    {
        $normalizedCode = strtoupper(trim($code));

        return DB::transaction(function () use ($version, $actor, $normalizedCode, $title, $description): Module {
            $authorizedActor = Account::query()
                ->whereKey($actor->id)
                ->where('is_platform_admin', true)
                ->where('status', 'active')
                ->first();

            if ($authorizedActor === null) {
                throw new LogicException('Only an active platform administrator can create shared content.');
            }

            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $lockedVersion = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ((int) $lockedVersion->course_id !== (int) $course->id) {
                throw new LogicException('The draft changed courses while it was being locked.');
            }

            if ($lockedVersion->status !== CourseVersionStatus::Draft || $lockedVersion->publication_kind !== 'manual') {
                throw new LogicException('Modules can only be added to a draft course version.');
            }

            if (! $course->is_shared || $course->company_id !== null || $course->status === CourseStatus::Archived) {
                throw new LogicException('New shared modules can only be added to shared courses.');
            }

            if (Module::query()->where('is_shared', true)->whereNull('company_id')->whereRaw('UPPER(code) = ?', [$normalizedCode])->exists()) {
                throw ValidationException::withMessages(['code' => __('A shared module with this code already exists.')]);
            }

            try {
                $module = $this->createModule->handle($authorizedActor, $normalizedCode, trim($title), filled($description) ? trim((string) $description) : null);

                CourseVersionModule::query()->create([
                    'course_version_id' => $lockedVersion->id,
                    'module_version_id' => $module->id,
                    'position' => ((int) $lockedVersion->moduleCompositions()->max('position')) + 1,
                    'is_required' => true,
                ]);
                $this->audit->log('shared_module.attached', $module, after: ['course_version_id' => $lockedVersion->id], platformActor: $authorizedActor);
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw ValidationException::withMessages(['code' => __('A shared module with this code already exists.')]);
                }

                throw $exception;
            }

            return $module;
        });
    }
}
