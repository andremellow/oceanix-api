<?php

use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Courses\DiscardSharedCourseDraft;
use App\Actions\Courses\PrepareSharedCourseEditor;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\PublishSharedCourseDraft;
use App\Actions\Courses\RemoveSharedCourseModule;
use App\Actions\Courses\SaveSharedCourseEditorDraft;
use App\Actions\Modules\CreateAndAttachSharedModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\SharedContent\ArchiveSharedContent;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SharedContentPropagation;
use App\Models\SharedContentPropagationItem;
use App\Models\UserTrainingAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\LessonContentSanitizer;
use App\Services\Modules\SharedModuleDraftWriter;
use App\Services\SharedContent\SharedContentCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

function sharedPublishedCourseWithAssessment(int $questionCount = 5): array
{
    $course = Course::factory()->shared()->create(['code' => 'HA-PO-OPE-002', 'title' => 'Procedimento de Fabricação']);
    $published = CourseVersion::factory()->published()->create(['course_id' => $course, 'version_number' => 2, 'publication_kind' => 'manual']);
    $module = Module::factory()->shared()->create([
        'course_version_id' => null, 'code' => 'course-2-module-1', 'title' => 'Fabricação e Montagem',
        'version_number' => 2, 'status' => 'published', 'position' => 1,
    ]);
    CourseVersionModule::query()->create(['course_version_id' => $published->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    foreach (range(1, $questionCount) as $position) {
        $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $module->id, 'position' => $position]);
        foreach (range(1, 4) as $optionPosition) {
            QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'position' => $optionPosition, 'is_correct' => $optionPosition === 1]);
        }
    }
    $course->update(['current_published_version_id' => $published->id]);

    return [$course, $published, $module];
}

function prepareSharedEditor(Course $course, Account $actor): CourseVersion
{
    $draft = $course->manualDraftVersion() ?? throw new RuntimeException('Manual draft missing.');
    $action = app(PrepareSharedCourseEditor::class);

    return $action->handle($course, $actor, $action->revision($course->fresh(), $draft->fresh()));
}

it('enforces the active platform administrator role on shared-course routes', function (): void {
    $course = Course::factory()->shared()->create();
    CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual']);
    $active = Account::factory()->platformAdmin()->create();
    $inactive = Account::factory()->platformAdmin()->create(['status' => 'inactive']);
    $nonAdmin = Account::factory()->create(['is_platform_admin' => false, 'status' => 'active']);

    $this->withSession(['platform_account_id' => $active->id])->get(route('platform.shared-courses.show', $course))->assertOk();
    $this->withSession(['platform_account_id' => $inactive->id])->get(route('platform.shared-courses.show', $course))->assertRedirect(route('platform.login'));
    $this->withSession(['platform_account_id' => $nonAdmin->id])->get(route('platform.shared-courses.editor', $course))->assertRedirect(route('platform.login'));
});

it('rejects inactive and non-admin actors in draft creation preparation and publication without writes', function (array $actorState): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->create($actorState);
    $versionCount = CourseVersion::query()->count();

    expect(fn () => app(CreateDraftFromVersion::class)->handle($published, $actor))->toThrow(LogicException::class)
        ->and(CourseVersion::query()->count())->toBe($versionCount);

    $valid = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $valid);
    $prepare = app(PrepareSharedCourseEditor::class);
    $pivotId = $draft->moduleCompositions()->sole()->lesson_id;
    expect(fn () => $prepare->handle($course, $actor, $prepare->revision($course->fresh(), $draft->fresh())))->toThrow(LogicException::class)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($pivotId)
        ->and(ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->exists())->toBeFalse()
        ->and(fn () => app(PublishSharedCourseDraft::class)->handle($draft, $actor))->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Draft);
})->with([
    'inactive admin' => [['is_platform_admin' => true, 'status' => 'inactive']],
    'active non-admin' => [['is_platform_admin' => false, 'status' => 'active']],
]);

it('copies the exact shared composition while preserving all five questions and twenty answers', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();

    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $composition = $draft->moduleCompositions()->sole();

    expect($draft->course_id)->toBe($course->id)
        ->and($draft->version_number)->toBe(3)
        ->and($draft->status)->toBe(CourseVersionStatus::Draft)
        ->and($draft->publication_kind)->toBe('manual')
        ->and($draft->source_course_version_id)->toBe($published->id)
        ->and($composition->lesson_id)->toBe($module->id)
        ->and($composition->position)->toBe(1)
        ->and($composition->is_required)->toBeTrue()
        ->and($module->fresh()->questions()->count())->toBe(5)
        ->and(QuestionOption::query()->whereIn('question_id', $module->questions()->pluck('id'))->count())->toBe(20)
        ->and($published->fresh()->moduleCompositions()->sole()->lesson_id)->toBe($module->id);

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'course_version.draft_created')->sole();
    expect($audit->platform_account_id)->toBe($actor->id)
        ->and($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->auditable_id)->toBe($draft->id)
        ->and($audit->after)->toMatchArray(['from_version' => 2, 'version_number' => 3]);
});

it('copies multiple shared references in exact source order with required flags unchanged', function (): void {
    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course, 'version_number' => 7]);
    $alpha = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    $beta = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $alpha->id, 'position' => 2, 'is_required' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $beta->id, 'position' => 1, 'is_required' => true]);
    $actor = Account::factory()->platformAdmin()->create();

    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);

    expect($source->moduleCompositions()->get()->map(fn ($row): array => [$row->id, $row->lesson_id, $row->position, (bool) $row->is_required])->all())
        ->toBe([
            [$source->moduleCompositions()->where('lesson_id', $beta->id)->value('id'), $beta->id, 1, true],
            [$source->moduleCompositions()->where('lesson_id', $alpha->id)->value('id'), $alpha->id, 2, false],
        ])
        ->and($draft->moduleCompositions()->get()->map(fn ($row): array => [$row->lesson_id, $row->position, (bool) $row->is_required])->all())
        ->toBe([[$beta->id, 1, true], [$alpha->id, 2, false]])
        ->and($draft->moduleCompositions()->pluck('lesson_id')->all())->toBe($source->moduleCompositions()->pluck('lesson_id')->all());
});

it('treats canonical compositions as authoritative and ignores hybrid legacy rows', function (): void {
    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $canonical = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $canonical->id, 'position' => 1, 'is_required' => true]);
    $legacy = Module::factory()->shared()->create(['course_version_id' => $source->id, 'status' => 'published', 'position' => 2]);
    $source->moduleCompositions()->where('lesson_id', $legacy->id)->delete();
    $lessonCount = Module::query()->count();
    $actor = Account::factory()->platformAdmin()->create();

    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);

    expect($draft->moduleCompositions()->pluck('lesson_id')->all())->toBe([$canonical->id])
        ->and($draft->lessons()->count())->toBe(0)
        ->and(Module::query()->count())->toBe($lessonCount)
        ->and($legacy->fresh())->not->toBeNull();
});

it('copies tenant canonical private module references exactly', function (): void {
    $course = Course::factory()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $module = Module::factory()->create(['course_version_id' => $source->id, 'position' => 3, 'is_required' => false]);

    $draft = app(CreateDraftFromVersion::class)->handle($source);

    expect($draft->moduleCompositions()->count())->toBe(1)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and($draft->moduleCompositions()->sole()->position)->toBe(3)
        ->and($draft->moduleCompositions()->sole()->is_required)->toBeFalse()
        ->and($draft->lessons()->count())->toBe(0);
});

it('atomically rejects an ineligible composition on a platform shared course', function (): void {
    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $private = Module::factory()->create(['course_version_id' => null]);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $private->id, 'position' => 1, 'is_required' => true]);
    $actor = Account::factory()->platformAdmin()->create();
    $before = CourseVersion::query()->withoutGlobalScopes()->count();

    expect(fn () => app(CreateDraftFromVersion::class)->handle($source, $actor))->toThrow(LogicException::class)
        ->and(CourseVersion::query()->withoutGlobalScopes()->count())->toBe($before)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'course_version.draft_created')->exists())->toBeFalse();
});

it('rejects draft creation after a referenced module lineage is archived without writes', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    app(ArchiveSharedContent::class)->handle($module, $actor, 'No longer eligible');

    expect(fn () => app(CreateDraftFromVersion::class)->handle($published, $actor))->toThrow(LogicException::class)
        ->and($course->versions()->where('status', 'draft')->where('publication_kind', 'manual')->exists())->toBeFalse()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'course_version.draft_created')->exists())->toBeFalse();
});

it('rejects discard after its module lineage is archived without status pivot or audit mutation', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $revision = app(DiscardSharedCourseDraft::class)->revision($draft);
    $before = $draft->moduleCompositions()->get()->toArray();
    app(ArchiveSharedContent::class)->handle($module, $actor, 'No longer eligible');

    expect(fn () => app(DiscardSharedCourseDraft::class)->handle($draft, $actor, 'Reset', $revision))->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($before)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->exists())->toBeFalse();
});

it('rolls back the new draft compositions and audit when draft creation fails after copying', function (): void {
    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course]);
    foreach ([1, 2] as $position) {
        $module = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
        CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $module->id, 'position' => $position, 'is_required' => true]);
    }
    $actor = Account::factory()->platformAdmin()->create();
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('log')->once()->andThrow(new RuntimeException('simulated audit failure'));
    $beforeVersions = CourseVersion::query()->withoutGlobalScopes()->count();
    $beforeCompositions = CourseVersionModule::query()->count();

    expect(fn () => (new CreateDraftFromVersion($audit))->handle($source, $actor))
        ->toThrow(RuntimeException::class, 'simulated audit failure')
        ->and(CourseVersion::query()->withoutGlobalScopes()->count())->toBe($beforeVersions)
        ->and(CourseVersionModule::query()->count())->toBe($beforeCompositions)
        ->and($course->versions()->where('status', 'draft')->exists())->toBeFalse()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'course_version.draft_created')->exists())->toBeFalse();
});

it('discards a broken empty draft without deleting its graph and allows a clean replacement draft', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $broken = CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 3, 'publication_kind' => 'manual']);
    $action = app(DiscardSharedCourseDraft::class);

    $discarded = $action->handle($broken, $actor, 'Cliente vai recomeçar', $action->revision($broken));
    $replacement = app(CreateDraftFromVersion::class)->handle($published, $actor);

    expect($discarded->status)->toBe(CourseVersionStatus::Discarded)
        ->and($replacement->version_number)->toBe(4)
        ->and($replacement->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and($published->moduleCompositions()->count())->toBe(1)
        ->and($module->fresh()->questions()->count())->toBe(5)
        ->and(QuestionOption::query()->whereIn('question_id', $module->questions()->pluck('id'))->count())->toBe(20);

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->sole();
    expect($audit->platform_account_id)->toBe($actor->id)
        ->and($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->before)->toBe(['status' => 'draft', 'composition' => []])
        ->and($audit->after)->toBe(['status' => 'discarded', 'reason' => 'Cliente vai recomeçar', 'composition' => []]);
});

it('discards a nonempty draft while preserving every pivot and assessment record', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $pivotIds = $draft->moduleCompositions()->pluck('id')->all();
    $questionIds = $module->questions()->pluck('id')->all();
    $optionIds = QuestionOption::query()->whereIn('question_id', $questionIds)->pluck('id')->all();
    $action = app(DiscardSharedCourseDraft::class);

    $action->handle($draft, $actor, 'Conteúdo será refeito', $action->revision($draft));

    expect($draft->fresh()->status)->toBe(CourseVersionStatus::Discarded)
        ->and($draft->fresh()->moduleCompositions()->pluck('id')->all())->toBe($pivotIds)
        ->and($published->fresh()->moduleCompositions()->pluck('lesson_id')->all())->toBe([$module->id])
        ->and($module->fresh())->not->toBeNull()
        ->and($module->questions()->pluck('id')->all())->toBe($questionIds)
        ->and(QuestionOption::query()->whereIn('question_id', $questionIds)->pluck('id')->all())->toBe($optionIds);
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->sole();
    expect($audit->before['composition'])->toHaveCount(1)
        ->and($audit->after['composition'])->toHaveCount(1)
        ->and($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->platform_account_id)->toBe($actor->id);
});

it('normalizes discarded composition away from mutable module drafts without deleting their graph', function (): void {
    [$course, $published, $publishedModule] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $editable = ModuleVersion::factory()->create(['module_id' => $publishedModule->id, 'version_number' => 3]);
    $newModule = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create(['course_version_id' => null, 'source_lesson_id' => null, 'version_number' => 1])->id);
    $newQuestion = Question::factory()->create(['company_id' => null, 'lesson_id' => $newModule->id]);
    QuestionOption::factory()->count(4)->create(['company_id' => null, 'question_id' => $newQuestion->id]);
    $replacementPivot = $draft->moduleCompositions()->sole();
    $replacementPivot->update(['lesson_id' => $editable->id]);
    $newPivot = CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $newModule->id, 'position' => 2, 'is_required' => false]);
    $action = app(DiscardSharedCourseDraft::class);

    $discarded = $action->handle($draft, $actor, 'Abandonar alterações', $action->revision($draft));

    expect($discarded->moduleCompositions()->get()->map(fn ($row): array => [$row->id, $row->lesson_id, $row->position, (bool) $row->is_required])->all())
        ->toBe([[$replacementPivot->id, $publishedModule->id, 1, true]])
        ->and($editable->fresh())->not->toBeNull()
        ->and($newModule->fresh())->not->toBeNull()
        ->and($newModule->questions()->whereKey($newQuestion->id)->exists())->toBeTrue()
        ->and(QuestionOption::query()->where('question_id', $newQuestion->id)->count())->toBe(4)
        ->and(CourseVersionModule::query()->whereKey($newPivot->id)->exists())->toBeFalse();

    $before = AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->sole()->before['composition'];
    $after = AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->sole()->after['composition'];
    expect($before)->toBe([
        ['composition_id' => $replacementPivot->id, 'module_version_id' => $editable->id, 'position' => 1, 'is_required' => true],
        ['composition_id' => $newPivot->id, 'module_version_id' => $newModule->id, 'position' => 2, 'is_required' => false],
    ])->and($after)->toBe([
        ['composition_id' => $replacementPivot->id, 'module_version_id' => $publishedModule->id, 'position' => 1, 'is_required' => true],
    ]);

    $editable->update(['title' => 'Later reuse mutation']);
    expect($discarded->fresh()->moduleCompositions()->sole()->lesson_id)->toBe($publishedModule->id);
});

it('atomically rejects an invalid declared discard source', function (string $invalidSource): void {
    $course = Course::factory()->shared()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual']);
    $source = match ($invalidSource) {
        'tenant' => Module::factory()->create(['status' => 'published']),
        'archived' => Module::factory()->shared()->create(['status' => 'archived']),
        default => Module::factory()->shared()->create(['status' => 'published']),
    };
    $lineage = $invalidSource === 'lineage-mismatch' ? (string) Str::uuid() : $source->lineage_uuid;
    $editable = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create([
        'status' => 'draft', 'version_number' => 2, 'source_lesson_id' => $source->id, 'lineage_uuid' => $lineage,
    ])->id);
    $pivot = CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $editable->id, 'position' => 1, 'is_required' => true]);
    $before = $pivot->only(['id', 'course_version_id', 'lesson_id', 'position', 'is_required']);
    $actor = Account::factory()->platformAdmin()->create();
    $action = app(DiscardSharedCourseDraft::class);

    expect(fn () => $action->handle($draft, $actor, 'Must remain', $action->revision($draft)))
        ->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and(CourseVersionModule::query()->findOrFail($pivot->id)->only(['id', 'course_version_id', 'lesson_id', 'position', 'is_required']))->toBe($before)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->exists())->toBeFalse();
})->with(['lineage-mismatch', 'tenant', 'archived']);

it('serializes discard against publication so a stale publisher cannot revive the draft', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $stalePublisherVersion = CourseVersion::query()->findOrFail($draft->id);
    $discard = app(DiscardSharedCourseDraft::class);

    $discard->handle($draft, $actor, 'Publication cancelled', $discard->revision($draft));

    expect(fn () => app(PublishSharedCourseDraft::class)->handle($stalePublisherVersion, $actor))
        ->toThrow(CoursePublicationException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Discarded)
        ->and($course->fresh()->current_published_version_id)->toBe($published->id);
});

it('serializes publication against discard so a stale discard cannot retire published work', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $discard = app(DiscardSharedCourseDraft::class);
    $staleRevision = $discard->revision($draft);

    app(PublishSharedCourseDraft::class)->handle($draft, $actor);

    expect(fn () => $discard->handle($draft, $actor, 'Too late', $staleRevision))->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Published)
        ->and($course->fresh()->current_published_version_id)->toBe($draft->id);
});

it('removes only the requested association with reason and audit and compacts positions', function (): void {
    [$course, $published, $first] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $second = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    $third = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $second->id, 'position' => 2, 'is_required' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $third->id, 'position' => 3, 'is_required' => true]);
    $target = $draft->moduleCompositions()->where('lesson_id', $second->id)->sole();
    $action = app(RemoveSharedCourseModule::class);

    $action->handle($draft, $target->id, $actor, 'Módulo incluído por engano', $action->revision($draft));

    expect($draft->fresh()->moduleCompositions()->pluck('lesson_id')->all())->toBe([$first->id, $third->id])
        ->and($draft->fresh()->moduleCompositions()->pluck('position')->all())->toBe([1, 2])
        ->and($second->fresh())->not->toBeNull()
        ->and($first->fresh()->questions()->count())->toBe(5);
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.module_removed')->sole();
    expect($audit->platform_account_id)->toBe($actor->id)
        ->and($audit->company_id)->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->before)->toMatchArray(['course_version_id' => $draft->id, 'module_version_id' => $second->id, 'position' => 2, 'is_required' => false])
        ->and($audit->after)->toBe(['reason' => 'Módulo incluído por engano']);
});

it('rejects every ineligible course version and actor for removal and discard without side effects', function (): void {
    $active = Account::factory()->platformAdmin()->create();
    $inactive = Account::factory()->platformAdmin()->create(['status' => 'inactive']);
    $nonAdmin = Account::factory()->create(['is_platform_admin' => false, 'status' => 'active']);
    $cases = [];

    foreach ([$inactive, $nonAdmin] as $actor) {
        $course = Course::factory()->shared()->create();
        $cases[] = [CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual']), $actor];
    }
    $privateCourse = Course::factory()->create();
    $cases[] = [CourseVersion::factory()->create(['course_id' => $privateCourse, 'publication_kind' => 'manual']), $active];
    $archived = Course::factory()->shared()->create(['status' => CourseStatus::Archived]);
    $cases[] = [CourseVersion::factory()->create(['course_id' => $archived, 'publication_kind' => 'manual']), $active];
    foreach ([CourseVersionStatus::Published, CourseVersionStatus::Discarded] as $status) {
        $course = Course::factory()->shared()->create();
        $cases[] = [CourseVersion::factory()->create(['course_id' => $course, 'status' => $status, 'publication_kind' => 'manual']), $active];
    }
    $propagationCourse = Course::factory()->shared()->create();
    $cases[] = [CourseVersion::factory()->create(['course_id' => $propagationCourse, 'publication_kind' => 'shared_propagation']), $active];

    foreach ($cases as [$version, $actor]) {
        $module = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
        $composition = CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
        $remove = app(RemoveSharedCourseModule::class);
        $discard = app(DiscardSharedCourseDraft::class);
        $statusBefore = $version->getRawOriginal('status');

        expect(fn () => $remove->handle($version, $composition->id, $actor, 'Reason', $remove->revision($version)))->toThrow(LogicException::class)
            ->and(fn () => $discard->handle($version, $actor, 'Reason', $discard->revision($version)))->toThrow(LogicException::class)
            ->and($version->fresh()->getRawOriginal('status'))->toBe($statusBefore)
            ->and($version->fresh()->moduleCompositions()->whereKey($composition->id)->exists())->toBeTrue()
            ->and($module->fresh())->not->toBeNull();
    }
    expect(AuditLog::query()->withoutGlobalScopes()->whereIn('action', ['shared_course.module_removed', 'shared_course.draft_discarded'])->exists())->toBeFalse();
});

it('rejects blank reasons stale confirmations revoked actors and ineligible drafts without mutation', function (): void {
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $composition = $draft->moduleCompositions()->sole();
    $remove = app(RemoveSharedCourseModule::class);
    $discard = app(DiscardSharedCourseDraft::class);
    $staleRemoval = $remove->revision($draft);
    $staleDiscard = $discard->revision($draft);
    CourseVersionModule::query()->whereKey($composition->id)->update(['is_required' => false]);

    expect(fn () => $remove->handle($draft, $composition->id, $actor, ' ', $staleRemoval))->toThrow(ValidationException::class)
        ->and(fn () => $remove->handle($draft, $composition->id, $actor, 'Reason', $staleRemoval))->toThrow(ValidationException::class)
        ->and(fn () => $discard->handle($draft, $actor, '', $discard->revision($draft)))->toThrow(ValidationException::class)
        ->and(fn () => $discard->handle($draft, $actor, 'Reason', $staleDiscard))->toThrow(ValidationException::class);

    $actor->update(['is_platform_admin' => false]);
    expect(fn () => $discard->handle($draft, $actor, 'Reason', $discard->revision($draft)))->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and($draft->fresh()->moduleCompositions()->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->whereIn('action', ['shared_course.module_removed', 'shared_course.draft_discarded'])->exists())->toBeFalse();
});

function assertInvalidCompositionSave(callable $payload): void
{
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $second = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'draft']);
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $second->id, 'position' => 2, 'is_required' => true]);
    $save = app(SaveSharedCourseEditorDraft::class);
    $revision = $save->revision($course->fresh(), $draft->fresh());
    $columns = ['id', 'course_version_id', 'lesson_id', 'position', 'is_required', 'created_at', 'updated_at'];
    $snapshot = fn (): array => $draft->fresh()->moduleCompositions()->get()->map(function ($row) use ($columns): array {
        $values = $row->only($columns);
        $values['created_at'] = $row->created_at?->toISOString();
        $values['updated_at'] = $row->updated_at?->toISOString();

        return $values;
    })->all();
    $before = $snapshot();
    $questionIds = Question::query()->whereIn('lesson_id', collect($before)->pluck('lesson_id'))->pluck('id')->all();
    $optionIds = QuestionOption::query()->whereIn('question_id', $questionIds)->pluck('id')->all();

    try {
        $save->handle($course, $draft, $actor, ['code' => $course->code, 'title' => $course->title, 'description' => $course->description], ['description' => $draft->description], $payload($before), $revision, []);
        Assert::fail('Expected the invalid composition payload to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('modules')
            ->and($exception->errors()['modules'])->toBe([__('One or more modules are unavailable.')]);
    }

    expect($snapshot())->toBe($before)
        ->and(Question::query()->whereIn('lesson_id', collect($before)->pluck('lesson_id'))->pluck('id')->all())->toBe($questionIds)
        ->and(QuestionOption::query()->whereIn('question_id', $questionIds)->pluck('id')->all())->toBe($optionIds);
}

it('rejects an empty editor payload without changing composition or content', function (): void {
    assertInvalidCompositionSave(fn (): array => []);
});

it('rejects a reordered editor payload without changing composition or content', function (): void {
    assertInvalidCompositionSave(fn (array $before): array => [['id' => $before[1]['lesson_id']], ['id' => $before[0]['lesson_id']]]);
});

it('rejects an unknown-module editor payload without changing composition or content', function (): void {
    assertInvalidCompositionSave(fn (): array => [['id' => 999999]]);
});

it('rolls back course fields and preserves composition when the module writer fails', function (): void {
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $module = $draft->moduleCompositions()->with('moduleVersion')->sole()->moduleVersion;
    $writer = Mockery::mock(SharedModuleDraftWriter::class);
    $writer->shouldReceive('prepare')->once()->andReturn(['module' => $module]);
    $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('simulated writer failure'));
    $action = new SaveSharedCourseEditorDraft($writer);
    $revision = $action->revision($course, $draft);
    $compositionBefore = $draft->moduleCompositions()->get()->map->only(['id', 'lesson_id', 'position', 'is_required'])->all();

    expect(fn () => $action->handle(
        $course, $draft, $actor,
        ['code' => $course->code, 'title' => 'Must roll back', 'description' => $course->description],
        ['description' => 'Must roll back'], [['id' => $module->id]], $revision, [$module->id => 'revision'],
    ))->toThrow(RuntimeException::class, 'simulated writer failure')
        ->and($course->fresh()->title)->not->toBe('Must roll back')
        ->and($draft->fresh()->description)->not->toBe('Must roll back')
        ->and($draft->fresh()->moduleCompositions()->get()->map->only(['id', 'lesson_id', 'position', 'is_required'])->all())->toBe($compositionBefore);
});

it('saves multiple modules successfully while leaving every composition row unchanged', function (): void {
    $course = Course::factory()->shared()->create(['code' => 'SAVE-101', 'title' => 'Before']);
    $draft = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual', 'description' => 'Before version']);
    $first = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'draft', 'title' => 'First before', 'content_markdown' => '<p>First before</p>'])->id);
    $second = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'draft', 'title' => 'Second before', 'content_markdown' => '<p>Second before</p>'])->id);
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $first->id, 'position' => 1, 'is_required' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $second->id, 'position' => 2, 'is_required' => true]);
    foreach (range(1, 5) as $position) {
        $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $first->id, 'position' => $position]);
        foreach (range(1, 4) as $optionPosition) {
            QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'position' => $optionPosition, 'is_correct' => $optionPosition === 1]);
        }
    }
    $actor = Account::factory()->platformAdmin()->create();
    $writer = app(SharedModuleDraftWriter::class);
    $action = app(SaveSharedCourseEditorDraft::class);
    $before = $draft->moduleCompositions()->get()->map->only(['id', 'lesson_id', 'position', 'is_required'])->all();
    $questionIds = $first->questions()->orderBy('position')->pluck('id')->all();
    $optionIds = QuestionOption::query()->whereIn('question_id', $questionIds)->orderBy('question_id')->orderBy('position')->pluck('id')->all();
    $payload = fn (ModuleVersion $module, string $title, string $content): array => [
        'id' => $module->id, 'title' => $title, 'description' => "{$title} description",
        'content_markdown' => $content, 'content_dirty' => true,
        'minimum_watch_percentage' => 85, 'passing_score' => 75,
        'questions' => $module->questions()->with('options')->orderBy('position')->get()->map(fn ($question): array => [
            'id' => $question->id, 'prompt' => "Updated {$question->id}", 'type' => 'single_choice', 'max_attempts' => 2,
            'options' => $question->options->sortBy('position')->values()->map(fn ($option): array => [
                'id' => $option->id, 'text' => "Updated {$option->id}", 'is_correct' => $option->position === 1,
            ])->all(),
        ])->all(),
    ];

    $result = $action->handle(
        $course, $draft, $actor,
        ['code' => ' save-202 ', 'title' => 'After', 'description' => 'After catalog'],
        ['description' => 'After version'],
        [$payload($first, 'First after', '<p>First after</p>'), $payload($second, 'Second after', '<p>Second after</p>')],
        $action->revision($course, $draft),
        [$first->id => $writer->revision($first), $second->id => $writer->revision($second)],
    );

    expect($course->fresh()->code)->toBe('SAVE-202')
        ->and($course->fresh()->title)->toBe('After')
        ->and($course->fresh()->description)->toBe('After catalog')
        ->and($draft->fresh()->description)->toBe('After version')
        ->and($first->fresh()->title)->toBe('First after')
        ->and($first->fresh()->content_markdown)->toContain('First after')
        ->and($second->fresh()->title)->toBe('Second after')
        ->and($second->fresh()->content_markdown)->toContain('Second after')
        ->and($first->questions()->orderBy('position')->pluck('id')->all())->toBe($questionIds)
        ->and($first->questions()->orderBy('position')->pluck('prompt')->all())->toBe(array_map(fn ($id): string => "Updated {$id}", $questionIds))
        ->and(QuestionOption::query()->whereIn('question_id', $questionIds)->orderBy('question_id')->orderBy('position')->pluck('id')->all())->toBe($optionIds)
        ->and(QuestionOption::query()->whereIn('question_id', $questionIds)->orderBy('question_id')->orderBy('position')->pluck('text')->all())->toBe(array_map(fn ($id): string => "Updated {$id}", $optionIds))
        ->and(QuestionOption::query()->whereIn('question_id', $questionIds)->count())->toBe(20)
        ->and($draft->fresh()->moduleCompositions()->get()->map->only(['id', 'lesson_id', 'position', 'is_required'])->all())->toBe($before)
        ->and($result['module_revisions'])->toHaveKeys([$first->id, $second->id]);
});

it('rolls back a real first module graph write when the second module writer fails', function (): void {
    $course = Course::factory()->shared()->create(['title' => 'Before']);
    $draft = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual']);
    $first = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create(['status' => 'draft', 'title' => 'First before'])->id);
    $second = ModuleVersion::query()->findOrFail(Module::factory()->shared()->create(['status' => 'draft', 'title' => 'Second before'])->id);
    foreach ([$first, $second] as $position => $module) {
        CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $module->id, 'position' => $position + 1, 'is_required' => true]);
    }
    foreach (range(1, 5) as $position) {
        $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $first->id, 'position' => $position]);
        QuestionOption::factory()->count(4)->create(['company_id' => null, 'question_id' => $question->id]);
    }
    $graphBefore = [$first->questions()->with('options')->get()->toArray(), $draft->moduleCompositions()->get()->toArray()];
    $baseWriter = app(SharedModuleDraftWriter::class);
    $writer = new class(app(LessonContentSanitizer::class)) extends SharedModuleDraftWriter
    {
        private int $writes = 0;

        public function write(array $prepared): void
        {
            $this->writes++;
            if ($this->writes === 2) {
                throw new RuntimeException('second writer failed');
            }
            parent::write($prepared);
        }
    };
    $payload = fn (ModuleVersion $module, string $title): array => [
        'id' => $module->id, 'title' => $title, 'description' => null, 'content_markdown' => null, 'content_dirty' => false,
        'minimum_watch_percentage' => 80, 'passing_score' => 70,
        'questions' => $module->questions()->with('options')->get()->map(fn ($q): array => [
            'id' => $q->id, 'prompt' => 'Changed prompt', 'type' => 'single_choice', 'max_attempts' => 2,
            'options' => $q->options->map(fn ($o, $index): array => ['id' => $o->id, 'text' => 'Changed option', 'is_correct' => $index === 0])->all(),
        ])->all(),
    ];
    $actor = Account::factory()->platformAdmin()->create();

    expect(fn () => (new SaveSharedCourseEditorDraft($writer))->handle(
        $course, $draft, $actor,
        ['code' => $course->code, 'title' => 'Changed course', 'description' => null], ['description' => null],
        [$payload($first, 'First changed'), $payload($second, 'Second changed')],
        app(SaveSharedCourseEditorDraft::class)->revision($course, $draft),
        [$first->id => $baseWriter->revision($first), $second->id => $baseWriter->revision($second)],
    ))->toThrow(RuntimeException::class, 'second writer failed')
        ->and($course->fresh()->title)->toBe('Before')
        ->and($first->fresh()->title)->toBe('First before')
        ->and($first->questions()->with('options')->get()->toArray())->toBe($graphBefore[0])
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($graphBefore[1])
        ->and($first->questions()->count())->toBe(5)
        ->and(QuestionOption::query()->whereIn('question_id', $first->questions()->pluck('id'))->count())->toBe(20);
});

it('rejects direct module creation on propagation drafts without content pivot or audit writes', function (): void {
    $course = Course::factory()->shared()->create();
    $propagation = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'shared_propagation']);
    $actor = Account::factory()->platformAdmin()->create();
    $modulesBefore = Module::query()->count();
    $pivotsBefore = CourseVersionModule::query()->count();

    expect(fn () => app(CreateAndAttachSharedModule::class)->handle($propagation, $actor, 'PROP-NEW', 'Forbidden'))->toThrow(LogicException::class)
        ->and(Module::query()->count())->toBe($modulesBefore)
        ->and(CourseVersionModule::query()->count())->toBe($pivotsBefore)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_module.attached')->exists())->toBeFalse();
});

it('allows propagation drafts beside one manual draft but rejects a second manual draft at the database boundary', function (): void {
    $course = Course::factory()->shared()->create();
    CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 1, 'publication_kind' => 'manual']);
    CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 2, 'publication_kind' => 'shared_propagation']);

    expect($course->versions()->where('status', 'draft')->count())->toBe(2)
        ->and(fn () => CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 3, 'publication_kind' => 'manual']))->toThrow(QueryException::class);
});

it('fails migration preflight without silently changing duplicate production drafts', function (): void {
    DB::statement('DROP INDEX IF EXISTS course_versions_one_manual_draft_unique');
    $course = Course::factory()->shared()->create();
    $first = CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 1, 'publication_kind' => 'manual']);
    $second = CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 2, 'publication_kind' => 'manual']);
    $migration = require database_path('migrations/2026_09_04_120000_enforce_one_manual_course_draft.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and($first->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and($second->fresh()->status)->toBe(CourseVersionStatus::Draft);
});

it('requires a confirmation reason in the editor and removes only the selected draft association', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    prepareSharedEditor($course, $actor);
    $this->withSession(['platform_account_id' => $actor->id]);

    $component = Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('confirmModuleRemoval', 0)
        ->assertSet('confirmingModuleRemoval', true)
        ->assertSee(__('Remove module from this draft?'))
        ->assertSee('course-2-module-1')
        ->assertSee('Fabricação e Montagem')
        ->assertSee(__('Module version :number · :status', ['number' => 3, 'status' => __('Draft')]))
        ->assertSee('HA-PO-OPE-002')
        ->assertSee('Procedimento de Fabricação')
        ->assertSee(__('Course draft version :number · :status', ['number' => 3, 'status' => __('Draft')]))
        ->assertSee(__('Removing module…'))
        ->assertSeeHtml('min-w-0 space-y-3')
        ->assertSeeHtml('break-words font-bold')
        ->assertSeeHtml('break-words font-semibold')
        ->assertSeeHtml('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="removeModule"')
        ->assertSeeHtml('w-full whitespace-normal sm:w-auto')
        ->call('removeModule')
        ->assertHasErrors(['moduleRemovalReason' => 'required'])
        ->set('moduleRemovalReason', 'Não pertence a esta versão')
        ->call('removeModule')
        ->assertHasNoErrors()
        ->assertSet('confirmingModuleRemoval', false)
        ->assertSee(__('Module removed from the draft. Its content was not deleted.'));

    expect($draft->fresh()->moduleCompositions()->count())->toBe(0)
        ->and($published->fresh()->moduleCompositions()->count())->toBe(1)
        ->and($module->fresh()->questions()->count())->toBe(5)
        ->and(QuestionOption::query()->whereIn('question_id', $module->questions()->pluck('id'))->count())->toBe(20);
});

it('renders the incident-shaped module with all five questions and twenty answers in the editor', function (): void {
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    app(CreateDraftFromVersion::class)->handle($published, $actor);
    prepareSharedEditor($course, $actor);
    $this->withSession(['platform_account_id' => $actor->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSee(trans_choice('ui.questions_count', 5, ['count' => 5]))
        ->assertSet('modules.0.questions', function (array $questions): bool {
            return count($questions) === 5
                && collect($questions)->every(fn (array $question): bool => count($question['options']) === 4)
                && collect($questions)->sum(fn (array $question): int => count($question['options'])) === 20;
        });
});

it('keeps direct editor mounting read-only and requires explicit preparation intent', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $pivotBefore = $draft->moduleCompositions()->sole()->only(['id', 'lesson_id', 'position', 'is_required']);
    $moduleCount = ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->count();
    $this->withSession(['platform_account_id' => $actor->id]);

    $this->get(route('platform.shared-courses.editor', ['course' => $course]))->assertStatus(409);

    expect($draft->fresh()->moduleCompositions()->sole()->only(['id', 'lesson_id', 'position', 'is_required']))->toBe($pivotBefore)
        ->and(ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->count())->toBe($moduleCount);

    $prepared = prepareSharedEditor($course, $actor);
    $this->get(route('platform.shared-courses.editor', ['course' => $course]))->assertOk();
    expect($prepared->moduleCompositions()->sole()->moduleVersion->status)->toBe(ModuleVersionStatus::Draft);
});

it('rejects stale editor preparation without creating drafts or changing pivots', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $action = app(PrepareSharedCourseEditor::class);
    $revision = $action->revision($course->fresh(), $draft->fresh());
    $draft->moduleCompositions()->sole()->update(['is_required' => false]);

    expect(fn () => $action->handle($course, $actor, $revision))->toThrow(ValidationException::class)
        ->and(ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->exists())->toBeFalse()
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id);
});

it('rolls back multi-module preparation when a later draft creation fails', function (): void {
    $course = Course::factory()->shared()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'manual']);
    $first = Module::factory()->shared()->create(['status' => 'published']);
    $second = Module::factory()->shared()->create(['status' => 'published']);
    foreach ([$first, $second] as $index => $module) {
        CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $module->id, 'position' => $index + 1, 'is_required' => true]);
    }
    $creator = new class extends CreateModuleDraft
    {
        private int $calls = 0;

        public function handle(ModuleVersion $source, Account $actor): ModuleVersion
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('second preparation failed');
            }

            return parent::handle($source, $actor);
        }
    };
    $action = new PrepareSharedCourseEditor($creator);
    $before = $draft->moduleCompositions()->orderBy('position')->get()->toArray();
    $actor = Account::factory()->platformAdmin()->create();

    expect(fn () => $action->handle($course, $actor, $action->revision($course->fresh(), $draft->fresh())))
        ->toThrow(RuntimeException::class, 'second preparation failed')
        ->and($draft->moduleCompositions()->orderBy('position')->get()->toArray())->toBe($before)
        ->and(ModuleVersion::query()->whereIn('lineage_uuid', [$first->lineage_uuid, $second->lineage_uuid])->where('status', 'draft')->exists())->toBeFalse();
});

it('discards through the detail confirmation and revokes both lifecycle actions immediately', function (): void {
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $this->withSession(['platform_account_id' => $actor->id]);

    Livewire\Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->set('confirmingDiscard', true)
        ->assertSee(__('Discard this draft?'))
        ->assertSee($course->code)
        ->assertSee($course->title)
        ->assertSee(__('Version :number', ['number' => $draft->version_number]))
        ->assertSee($draft->status->label())
        ->assertSee(__('Discarding draft…'))
        ->assertSeeHtml('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="discardDraft"')
        ->assertSeeHtml('w-full whitespace-normal sm:w-auto')
        ->call('discardDraft')
        ->assertHasErrors(['discardReason' => 'required'])
        ->set('discardReason', 'Recomeçar composição')
        ->call('discardDraft')
        ->assertHasNoErrors()
        ->assertSee(__('Draft discarded. Draft-only edits and associations were abandoned; shared modules and published versions remain available.'));

    expect($draft->fresh()->status)->toBe(CourseVersionStatus::Discarded);

    $replacement = app(CreateDraftFromVersion::class)->handle($published, $actor);
    prepareSharedEditor($course, $actor);
    $editor = Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])->call('confirmModuleRemoval', 0);
    $actor->update(['is_platform_admin' => false]);
    $editor->set('moduleRemovalReason', 'Tentativa após revogação')->call('removeModule')->assertForbidden();

    expect($replacement->fresh()->moduleCompositions()->count())->toBe(1);
});

it('selects only the manual draft when propagation and manual drafts coexist', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->create();
    $propagation = CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 1, 'publication_kind' => 'shared_propagation', 'title' => 'Technical propagation']);
    $manual = CourseVersion::factory()->create(['course_id' => $course, 'version_number' => 2, 'publication_kind' => 'manual', 'title' => 'Manual draft']);
    $this->withSession(['platform_account_id' => $actor->id]);

    Livewire\Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->assertSee(__('Edit draft'))
        ->assertSee(__('Discard draft'));
    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSet('version.id', $manual->id)
        ->assertSet('version.id', fn (int $id): bool => $id !== $propagation->id);
});

it('uses only the manual draft for assignment impact when propagation and manual drafts coexist', function (): void {
    $course = Course::factory()->shared()->create(['current_published_version_id' => null]);
    $propagation = CourseVersion::factory()->create([
        'course_id' => $course, 'version_number' => 1, 'publication_kind' => 'shared_propagation',
    ]);
    $manual = CourseVersion::factory()->create([
        'course_id' => $course, 'version_number' => 2, 'publication_kind' => 'manual',
    ]);
    UserTrainingAssignment::factory()->create([
        'course_id' => $course->id, 'course_version_id' => $manual->id, 'status' => 'pending',
    ]);
    UserTrainingAssignment::factory()->inProgress()->create([
        'course_id' => $course->id, 'course_version_id' => $propagation->id,
    ]);

    expect(app(SharedContentCatalog::class)->coursePublicationImpact($course->fresh()))
        ->toBe(['not_started' => 1, 'in_progress' => 0]);
});

it('never exposes a propagation-only draft as editable and rejects direct saves', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->create();
    $propagation = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'shared_propagation']);
    $this->withSession(['platform_account_id' => $actor->id]);

    Livewire\Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->assertSee(__('New draft version'))
        ->assertDontSee(__('Edit draft'))
        ->assertSet('discardRevision', '')
        ->assertDontSeeHtml('wire:click="$set(\'confirmingDiscard\', true)"');
    $this->get(route('platform.shared-courses.editor', ['course' => $course]))->assertNotFound();

    $save = app(SaveSharedCourseEditorDraft::class);
    expect(fn () => $save->handle(
        $course, $propagation, $actor,
        ['code' => $course->code, 'title' => $course->title, 'description' => $course->description],
        ['description' => $propagation->description], [], $save->revision($course, $propagation), [],
    ))->toThrow(LogicException::class);
});

it('rejects technical propagation versions from both manual publication entries without writes or audit', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->create();
    $propagation = CourseVersion::factory()->create(['course_id' => $course, 'publication_kind' => 'shared_propagation']);
    $before = $propagation->refresh()->toArray();
    $auditCount = AuditLog::query()->withoutGlobalScopes()->count();

    expect(fn () => app(PublishSharedCourseDraft::class)->handle($propagation, $actor))->toThrow(LogicException::class)
        ->and(fn () => app(PublishCourseVersion::class)->handle($propagation, $actor))->toThrow(LogicException::class)
        ->and($propagation->fresh()->toArray())->toBe($before)
        ->and(AuditLog::query()->withoutGlobalScopes()->count())->toBe($auditCount);
});

it('rejects publication when a composed module lineage was archived after draft creation', function (): void {
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    app(ArchiveSharedContent::class)->handle($module, $actor, 'Withdraw module');
    $pivotBefore = $draft->moduleCompositions()->get()->toArray();

    expect(fn () => app(PublishSharedCourseDraft::class)->handle($draft, $actor))->toThrow(CoursePublicationException::class)
        ->and($draft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and($published->fresh()->status)->toBe(CourseVersionStatus::Published)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($pivotBefore);
});

it('denies discard immediately after platform access is revoked', function (): void {
    [$course, $published] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    app(CreateDraftFromVersion::class)->handle($published, $actor);
    prepareSharedEditor($course, $actor);
    $this->withSession(['platform_account_id' => $actor->id]);

    $show = Livewire\Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->set('discardReason', 'No longer authorized');
    $actor->update(['is_platform_admin' => false]);

    $show->call('discardDraft')->assertForbidden();
});

it('serializes two manual draft writers on PostgreSQL connections', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require a database whose name contains "test".');
    }

    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course, 'publication_kind' => 'manual']);
    $module = Module::factory()->shared()->create(['course_version_id' => null, 'status' => 'published']);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $firstActor = Account::factory()->platformAdmin()->create();
    $secondActor = Account::factory()->platformAdmin()->create();
    $barrier = 9042025;
    DB::commit(); // Make the fixture visible to both independent PostgreSQL sessions.
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static function (int $sourceId, int $actorId, int $barrier, string $name): string {
        return sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_draft_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $source = App\Models\CourseVersion::query()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    app(App\Actions\Courses\CreateDraftFromVersion::class)->handle($source, $actor);
    echo 'RESULT:created';
} catch (App\Exceptions\CoursePublicationException) {
    echo 'RESULT:exists';
}
PHP, $name, $barrier, $sourceId, $actorId);
    };
    $first = new Process([PHP_BINARY, '-r', $script($source->id, $firstActor->id, $barrier, 'first')], base_path(), timeout: 30);
    $second = new Process([PHP_BINARY, '-r', $script($source->id, $secondActor->id, $barrier, 'second')], base_path(), timeout: 30);
    $first->start();
    $second->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_draft_first','oceanix_draft_second') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $first->wait();
    $second->wait();
    $results = [$first->getOutput(), $second->getOutput()];
    sort($results);

    DB::purge();
    DB::reconnect();
    $draft = CourseVersion::query()->where('course_id', $course->id)->where('status', 'draft')->where('publication_kind', 'manual')->sole();
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($results)->toBe(['RESULT:created', 'RESULT:exists'])
        ->and($draft->moduleCompositions()->count())->toBe(1)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'course_version.draft_created')->where('auditable_id', $draft->id)->count())->toBe(1);

    DB::table('audit_logs')->where('action', 'course_version.draft_created')->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->where('id', $module->id)->delete();
    DB::table('accounts')->whereIn('id', [$firstActor->id, $secondActor->id])->delete();
    DB::beginTransaction(); // Restore RefreshDatabase's expected transaction boundary.
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL two-process action gate.');

it('serializes competing PostgreSQL publish and discard actions without partial state', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    prepareSharedEditor($course, $actor);
    $discard = app(DiscardSharedCourseDraft::class);
    $revision = $discard->revision($draft->fresh());
    $barrier = 9042026;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");

    $script = static fn (string $operation): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_concurrency_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $draft = App\Models\CourseVersion::query()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class;
}
PHP,
        $operation, $barrier, $draft->id, $actor->id,
        $operation === 'publish'
            ? 'app(App\Actions\Courses\PublishSharedCourseDraft::class)->handle($draft, $actor);'
            : sprintf("app(App\\Actions\\Courses\\DiscardSharedCourseDraft::class)->handle(\$draft, \$actor, 'Concurrent discard', '%s');", $revision),
        $operation,
    );
    $publishProcess = new Process([PHP_BINARY, '-r', $script('publish')], base_path(), timeout: 30);
    $discardProcess = new Process([PHP_BINARY, '-r', $script('discard')], base_path(), timeout: 30);
    $publishProcess->start();
    $discardProcess->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_concurrency_publish','oceanix_concurrency_discard') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $publishProcess->wait();
    $discardProcess->wait();

    DB::purge();
    DB::reconnect();
    $terminal = CourseVersion::query()->findOrFail($draft->id);
    $outputs = [$publishProcess->getOutput(), $discardProcess->getOutput()];
    expect($publishProcess->isSuccessful())->toBeTrue($publishProcess->getErrorOutput())
        ->and($discardProcess->isSuccessful())->toBeTrue($discardProcess->getErrorOutput())
        ->and(collect($outputs)->filter(fn (string $output): bool => in_array($output, ['RESULT:publish', 'RESULT:discard'], true))->count())->toBe(1)
        ->and($terminal->status)->toBeIn([CourseVersionStatus::Published, CourseVersionStatus::Discarded])
        ->and($terminal->moduleCompositions()->whereDoesntHave('moduleVersion')->exists())->toBeFalse()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->count())->toBe($terminal->status === CourseVersionStatus::Discarded ? 1 : 0);

    DB::table('audit_logs')->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->where('lineage_uuid', $module->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL competing-action gate.');

it('serializes competing PostgreSQL preparation and publication without partial module drafts', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    [$course, $published, $module] = sharedPublishedCourseWithAssessment();
    $actor = Account::factory()->platformAdmin()->create();
    $v1 = Module::factory()->shared()->create([
        'lineage_uuid' => $module->lineage_uuid, 'version_number' => 1, 'status' => 'retired',
    ]);
    DB::table('lessons')->where('id', $module->id)->update(['source_lesson_id' => $v1->id]);
    $module->refresh();
    $draft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $revision = app(PrepareSharedCourseEditor::class)->revision($course->fresh(), $draft->fresh());
    $barrier = 9042027;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $operation): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_prepare_publish_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $course = App\Models\Course::query()->findOrFail(%d);
    $draft = App\Models\CourseVersion::query()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class;
}
PHP,
        $operation, $barrier, $course->id, $draft->id, $actor->id,
        $operation === 'prepare'
            ? sprintf("app(App\\Actions\\Courses\\PrepareSharedCourseEditor::class)->handle(\$course, \$actor, '%s');", $revision)
            : 'app(App\Actions\Courses\PublishSharedCourseDraft::class)->handle($draft, $actor);',
        $operation,
    );
    $prepare = new Process([PHP_BINARY, '-r', $script('prepare')], base_path(), timeout: 30);
    $publish = new Process([PHP_BINARY, '-r', $script('publish')], base_path(), timeout: 30);
    $prepare->start();
    $publish->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_prepare_publish_prepare','oceanix_prepare_publish_publish') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $prepare->wait();
    $publish->wait();

    DB::purge();
    DB::reconnect();
    $terminal = CourseVersion::query()->findOrFail($draft->id);
    expect($prepare->isSuccessful())->toBeTrue($prepare->getErrorOutput())
        ->and($publish->isSuccessful())->toBeTrue($publish->getErrorOutput())
        ->and($terminal->status)->toBe(CourseVersionStatus::Published)
        ->and($terminal->moduleCompositions()->count())->toBe(1)
        ->and($terminal->moduleCompositions()->whereDoesntHave('moduleVersion')->exists())->toBeFalse()
        ->and(ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->exists())->toBeFalse();

    DB::table('audit_logs')->delete();
    $propagationIds = DB::table('shared_content_propagation_items')->where('course_id', $course->id)->pluck('propagation_id');
    DB::table('shared_content_propagation_items')->where('course_id', $course->id)->delete();
    DB::table('shared_content_propagations')->whereIn('id', $propagationIds)->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->where('lineage_uuid', $module->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL prepare/publish gate.');

it('serializes two PostgreSQL propagation actions to one idempotent result', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    $actor = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $old = Module::factory()->shared()->create(['status' => 'published', 'version_number' => 1]);
    $new = ModuleVersion::factory()->shared()->published()->create([
        'module_id' => $old->id, 'lineage_uuid' => $old->lineage_uuid, 'version_number' => 2,
        'content_markdown' => '<p>Safe propagated content</p>',
    ]);
    $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $new->id]);
    QuestionOption::factory()->correct()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 2]);
    CourseVersionModule::query()->create(['course_version_id' => $source->id, 'lesson_id' => $old->id, 'position' => 1, 'is_required' => true]);
    $course->update(['current_published_version_id' => $source->id]);
    $propagation = SharedContentPropagation::query()->create([
        'uuid' => (string) Str::uuid(), 'lesson_id' => $new->id, 'initiated_by_account_id' => $actor->id,
        'restart_in_progress' => false, 'status' => 'pending', 'affected_count' => 1,
        'not_started_count' => 0, 'in_progress_count' => 0,
    ]);
    $item = SharedContentPropagationItem::query()->create([
        'propagation_id' => $propagation->id, 'course_id' => $course->id, 'company_id' => $course->company_id,
        'status' => 'pending', 'source_course_version_id' => $source->id,
    ]);
    $barrier = 9042028;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $name): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_propagation_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $item = App\Models\SharedContentPropagationItem::query()->findOrFail(%d);
    $result = app(App\Actions\Courses\CreatePropagatedCourseVersion::class)->handle($item);
    echo 'RESULT:'.$result->id;
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class.':'.preg_replace('/\s+/', ' ', trim($e->getMessage()));
}
PHP, $name, $barrier, $item->id);
    $first = new Process([PHP_BINARY, '-r', $script('first')], base_path(), timeout: 30);
    $second = new Process([PHP_BINARY, '-r', $script('second')], base_path(), timeout: 30);
    $first->start();
    $second->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_propagation_first','oceanix_propagation_second') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $first->wait();
    $second->wait();

    DB::purge();
    DB::reconnect();
    $resultId = SharedContentPropagationItem::query()->findOrFail($item->id)->result_course_version_id;
    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($first->getOutput())->toBe('RESULT:'.$resultId)
        ->and($second->getOutput())->toBe('RESULT:'.$resultId)
        ->and(CourseVersion::query()->where('course_id', $course->id)->where('publication_kind', 'shared_propagation')->count())->toBe(1)
        ->and(CourseVersion::query()->findOrFail($resultId)->moduleCompositions()->sole()->lesson_id)->toBe($new->id);

    DB::table('audit_logs')->delete();
    DB::table('shared_content_propagation_items')->where('propagation_id', $propagation->id)->delete();
    DB::table('shared_content_propagations')->where('id', $propagation->id)->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->where('lineage_uuid', $old->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL propagation idempotence gate.');

it('serializes PostgreSQL course composition updates against publication', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course]);
    $actor = adminUser();
    $modules = collect([1, 2])->map(function (int $position) use ($course): ModuleVersion {
        $moduleId = Module::factory()->create([
            'company_id' => $course->company_id, 'is_shared' => false, 'status' => 'published',
            'content_markdown' => '<p>Safe content</p>', 'position' => $position,
        ])->id;
        $module = ModuleVersion::query()->findOrFail($moduleId);
        $question = Question::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $module->id]);
        QuestionOption::factory()->correct()->create(['company_id' => $course->company_id, 'question_id' => $question->id, 'position' => 1]);
        QuestionOption::factory()->create(['company_id' => $course->company_id, 'question_id' => $question->id, 'position' => 2]);

        return $module;
    });
    CourseVersionModule::query()->create(['course_version_id' => $draft->id, 'lesson_id' => $modules[0]->id, 'position' => 1, 'is_required' => true]);
    $barrier = 9042029;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $operation): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_compose_publish_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
app(App\Tenancy\TenantContext::class)->set(App\Models\Company::query()->withoutGlobalScopes()->findOrFail(%d));
try {
    $draft = App\Models\CourseVersion::query()->findOrFail(%d);
    $actor = App\Models\User::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class;
}
PHP,
        $operation, $barrier, $course->company_id, $draft->id, $actor->id,
        $operation === 'compose'
            ? sprintf('app(App\\Actions\\Courses\\UpdateCourseModuleComposition::class)->handle($draft, [%d], $actor);', $modules[1]->id)
            : 'app(App\Actions\Courses\PublishCourseVersion::class)->handle($draft, $actor);',
        $operation,
    );
    $compose = new Process([PHP_BINARY, '-r', $script('compose')], base_path(), timeout: 30);
    $publish = new Process([PHP_BINARY, '-r', $script('publish')], base_path(), timeout: 30);
    $compose->start();
    $publish->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_compose_publish_compose','oceanix_compose_publish_publish') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $compose->wait();
    $publish->wait();

    DB::purge();
    DB::reconnect();
    app(TenantContext::class)->set($course->company);
    $terminal = CourseVersion::query()->findOrFail($draft->id);
    $pivotIds = $terminal->moduleCompositions()->pluck('lesson_id')->all();
    expect($compose->isSuccessful())->toBeTrue($compose->getErrorOutput())
        ->and($publish->isSuccessful())->toBeTrue($publish->getErrorOutput())
        ->and($terminal->status)->toBe(CourseVersionStatus::Published)
        ->and($pivotIds)->toHaveCount(1)
        ->and($pivotIds[0])->toBeIn([$modules[0]->id, $modules[1]->id]);

    DB::table('audit_logs')->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->whereIn('id', $modules->pluck('id'))->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL composition/publication gate.');

it('serializes PostgreSQL second-generation draft creation against lineage archive', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    $actor = Account::factory()->platformAdmin()->create();
    $v1 = Module::factory()->shared()->create(['version_number' => 1, 'status' => 'retired']);
    $v2 = ModuleVersion::factory()->shared()->published()->create([
        'module_id' => $v1->id, 'lineage_uuid' => $v1->lineage_uuid, 'version_number' => 2,
    ]);
    $barrier = 9042030;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $operation): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_lineage_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $version = App\Models\ModuleVersion::query()->withoutGlobalScopes()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class;
}
PHP,
        $operation, $barrier, $v2->id, $actor->id,
        $operation === 'draft'
            ? 'app(App\Actions\Modules\CreateModuleDraft::class)->handle($version, $actor);'
            : "app(App\\Actions\\SharedContent\\ArchiveSharedContent::class)->handle(\$version, \$actor, 'Concurrent archive');",
        $operation,
    );
    $draftProcess = new Process([PHP_BINARY, '-r', $script('draft')], base_path(), timeout: 30);
    $archiveProcess = new Process([PHP_BINARY, '-r', $script('archive')], base_path(), timeout: 30);
    $draftProcess->start();
    $archiveProcess->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_lineage_draft','oceanix_lineage_archive') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $draftProcess->wait();
    $archiveProcess->wait();

    DB::purge();
    DB::reconnect();
    $lineage = ModuleVersion::query()->withoutGlobalScopes()->where('lineage_uuid', $v1->lineage_uuid)->orderBy('version_number')->get();
    expect($draftProcess->isSuccessful())->toBeTrue($draftProcess->getErrorOutput())
        ->and($archiveProcess->isSuccessful())->toBeTrue($archiveProcess->getErrorOutput())
        ->and($lineage->pluck('version_number')->all())->toBeIn([[1, 2], [1, 2, 3]])
        ->and($lineage->every(fn (ModuleVersion $version): bool => $version->lineage_archived_at !== null))->toBeTrue()
        ->and($lineage->where('version_number', 3)->count())->toBeLessThanOrEqual(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_module.archived')->count())->toBe(1);

    DB::table('audit_logs')->delete();
    DB::table('lessons')->where('lineage_uuid', $v1->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL stable-lineage gate.');

it('serializes every shared module save path against PostgreSQL lineage archive', function (string $saveKind): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    [$course, $published, $source] = sharedPublishedCourseWithAssessment(1);
    $actor = Account::factory()->platformAdmin()->create();
    $courseDraft = app(CreateDraftFromVersion::class)->handle($published, $actor);
    $courseDraft = prepareSharedEditor($course, $actor);
    $module = $courseDraft->moduleCompositions()->sole()->moduleVersion;
    $question = $module->questions()->with('options')->sole();
    $originalTitle = $module->title;
    $originalPrompt = $question->prompt;
    $intended = 'Concurrent save '.$saveKind;
    $barrier = 9042100 + array_search($saveKind, ['course', 'assessment', 'module'], true);
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");

    $saveBody = match ($saveKind) {
        'course' => <<<'PHP'
$course = App\Models\Course::query()->findOrFail(%d);
$courseVersion = App\Models\CourseVersion::query()->findOrFail(%d);
$action = app(App\Actions\Courses\SaveSharedCourseEditorDraft::class);
$writer = app(App\Services\Modules\SharedModuleDraftWriter::class);
$payload = module_payload($module, '%s');
$action->handle($course, $courseVersion, $actor,
    ['code' => $course->code, 'title' => $course->title, 'description' => $course->description],
    ['description' => $courseVersion->description], [$payload], $action->revision($course, $courseVersion),
    [$module->id => $writer->revision($module)]);
PHP,
        'assessment' => <<<'PHP'
$question = $module->questions()->with('options')->sole();
$payload = ['questions' => [[
    'id' => $question->id, 'prompt' => '%s', 'type' => $question->type->value,
    'max_attempts' => $question->max_attempts,
    'options' => $question->options->map(fn ($option) => [
        'id' => $option->id, 'text' => $option->text, 'is_correct' => (bool) $option->is_correct,
    ])->all(),
]]];
$action = app(App\Actions\Modules\SaveModuleAssessment::class);
$action->handle($module, $actor, $payload, $action->revision($module));
PHP,
        'module' => <<<'PHP'
$writer = app(App\Services\Modules\SharedModuleDraftWriter::class);
app(App\Actions\Modules\SaveSharedModuleEditorDraft::class)
    ->handle($module, $actor, module_payload($module, '%s'), $writer->revision($module));
PHP,
    };
    $saveBody = $saveKind === 'course'
        ? sprintf($saveBody, $course->id, $courseDraft->id, $intended)
        : sprintf($saveBody, $intended);
    $script = static function (string $operation, string $saveKind, int $barrier, int $moduleId, int $actorId, string $saveBody): string {
        $body = $operation === 'save'
            ? $saveBody
            : "app(App\\Actions\\SharedContent\\ArchiveSharedContent::class)->handle(\$module, \$actor, 'Concurrent archive');";

        return sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function module_payload($module, $title) {
    return [
        'id' => $module->id, 'title' => $title, 'description' => $module->description,
        'content_markdown' => $module->content_markdown, 'content_dirty' => false,
        'minimum_watch_percentage' => $module->minimum_watch_percentage, 'passing_score' => $module->passing_score,
        'questions' => $module->questions()->with('options')->orderBy('position')->get()->map(fn ($question) => [
            'id' => $question->id, 'prompt' => $question->prompt, 'type' => $question->type->value,
            'max_attempts' => $question->max_attempts,
            'options' => $question->options->map(fn ($option) => [
                'id' => $option->id, 'text' => $option->text, 'is_correct' => (bool) $option->is_correct,
            ])->all(),
        ])->all(),
    ];
}
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_save_archive_%s_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $module = App\Models\ModuleVersion::query()->withoutGlobalScopes()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class.':'.preg_replace('/\s+/', ' ', trim($e->getMessage()));
}
PHP, $operation, $saveKind, $barrier, $moduleId, $actorId, $body, $operation);
    };
    $save = new Process([PHP_BINARY, '-r', $script('save', $saveKind, $barrier, $module->id, $actor->id, $saveBody)], base_path(), timeout: 30);
    $archive = new Process([PHP_BINARY, '-r', $script('archive', $saveKind, $barrier, $module->id, $actor->id, $saveBody)], base_path(), timeout: 30);
    $save->start();
    $archive->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_save_archive_save_{$saveKind}','oceanix_save_archive_archive_{$saveKind}') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $save->wait();
    $archive->wait();

    DB::purge();
    DB::reconnect();
    $module = ModuleVersion::query()->withoutGlobalScopes()->findOrFail($module->id);
    $question = $module->questions()->with('options')->sole();
    $saveCommitted = $save->getOutput() === 'RESULT:save';
    $expectedRejection = match ($saveKind) {
        'course' => 'RESULT:rejected:Illuminate\\Validation\\ValidationException:One or more modules are unavailable.',
        'assessment' => 'RESULT:rejected:LogicException:Assessments can only be edited on platform-owned shared module drafts.',
        'module' => 'RESULT:rejected:LogicException:Archived shared module lineages cannot be edited.',
    };
    expect($save->isSuccessful())->toBeTrue($save->getErrorOutput())
        ->and($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
        ->and($archive->getOutput())->toBe('RESULT:archive')
        ->and($save->getOutput())->toBeIn(['RESULT:save', $expectedRejection])
        ->and($module->lineage_archived_at)->not->toBeNull()
        ->and($module->questions()->count())->toBe(1)
        ->and($question->options()->count())->toBe(4)
        ->and($saveKind === 'assessment' ? $question->prompt : $module->title)
        ->toBe($saveCommitted ? $intended : ($saveKind === 'assessment' ? $originalPrompt : $originalTitle))
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_module.archived')->count())->toBe(1);

    DB::table('audit_logs')->delete();
    DB::table('courses')->where('id', $course->id)->delete();
    DB::table('lessons')->where('lineage_uuid', $source->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->with(['course', 'assessment', 'module'])
    ->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL save/archive matrix.');

it('serializes PostgreSQL module publication against lineage archive with exact legal outcomes', function (): void {
    $database = (string) config('database.connections.pgsql.database');
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('PostgreSQL concurrency tests require an isolated test database.');
    }
    $actor = Account::factory()->platformAdmin()->create();
    $sourceId = Module::factory()->shared()->create(['status' => 'published', 'version_number' => 1])->id;
    $source = ModuleVersion::query()->withoutGlobalScopes()->findOrFail($sourceId);
    $draft = app(CreateModuleDraft::class)->handle($source, $actor);
    $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $draft->id]);
    QuestionOption::factory()->correct()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 2]);
    $barrier = 9042200;
    DB::commit();
    DB::statement("select pg_advisory_lock({$barrier})");
    $script = static fn (string $operation): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("set application_name = 'oceanix_publish_archive_%s'");
Illuminate\Support\Facades\DB::statement('select pg_advisory_lock_shared(%d)');
try {
    $draft = App\Models\ModuleVersion::query()->withoutGlobalScopes()->findOrFail(%d);
    $actor = App\Models\Account::query()->findOrFail(%d);
    %s
    echo 'RESULT:%s';
} catch (Throwable $e) {
    echo 'RESULT:rejected:'.$e::class.':'.preg_replace('/\s+/', ' ', trim($e->getMessage()));
}
PHP,
        $operation, $barrier, $draft->id, $actor->id,
        $operation === 'publish'
            ? 'app(App\Actions\Modules\PublishModuleVersion::class)->handle($draft, $actor);'
            : "app(App\\Actions\\SharedContent\\ArchiveSharedContent::class)->handle(\$draft, \$actor, 'Concurrent archive');",
        $operation,
    );
    $publish = new Process([PHP_BINARY, '-r', $script('publish')], base_path(), timeout: 30);
    $archive = new Process([PHP_BINARY, '-r', $script('archive')], base_path(), timeout: 30);
    $publish->start();
    $archive->start();
    $deadline = microtime(true) + 10;
    do {
        $waiting = (int) DB::scalar("select count(*) from pg_stat_activity where application_name in ('oceanix_publish_archive_publish','oceanix_publish_archive_archive') and wait_event_type = 'Lock'");
    } while ($waiting < 2 && microtime(true) < $deadline);
    expect($waiting)->toBe(2);
    DB::statement("select pg_advisory_unlock({$barrier})");
    $publish->wait();
    $archive->wait();

    DB::purge();
    DB::reconnect();
    $draft = ModuleVersion::query()->withoutGlobalScopes()->findOrFail($draft->id);
    $published = $publish->getOutput() === 'RESULT:publish';
    $lineage = ModuleVersion::query()->withoutGlobalScopes()
        ->where('lineage_uuid', $draft->lineage_uuid)->orderBy('version_number')->get();
    $expectedStatuses = $published
        ? [ModuleVersionStatus::Retired, ModuleVersionStatus::Published]
        : [ModuleVersionStatus::Published, ModuleVersionStatus::Draft];
    expect($publish->isSuccessful())->toBeTrue($publish->getErrorOutput())
        ->and($archive->isSuccessful())->toBeTrue($archive->getErrorOutput())
        ->and($archive->getOutput())->toBe('RESULT:archive')
        ->and($publish->getOutput())->toBeIn([
            'RESULT:publish',
            'RESULT:rejected:LogicException:Only a draft shared module version can be published.',
        ])
        ->and($draft->status)->toBe($published ? ModuleVersionStatus::Published : ModuleVersionStatus::Draft)
        ->and($lineage->pluck('status')->all())->toBe($expectedStatuses)
        ->and($lineage->where('status', ModuleVersionStatus::Published)->count())->toBe(1)
        ->and($draft->lineage_archived_at)->not->toBeNull()
        ->and($lineage->pluck('version_number')->all())->toBe([1, 2])
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'shared_module.archived')->count())->toBe(1);

    DB::table('audit_logs')->delete();
    $lineageIds = DB::table('lessons')->where('lineage_uuid', $draft->lineage_uuid)->pluck('id');
    $propagationIds = DB::table('shared_content_propagations')->whereIn('lesson_id', $lineageIds)->pluck('id');
    DB::table('shared_content_propagation_items')->whereIn('propagation_id', $propagationIds)->delete();
    DB::table('shared_content_propagations')->whereIn('id', $propagationIds)->delete();
    DB::table('lessons')->where('lineage_uuid', $draft->lineage_uuid)->delete();
    DB::table('accounts')->where('id', $actor->id)->delete();
    DB::beginTransaction();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || getenv('RUN_POSTGRES_CONCURRENCY_TESTS') !== '1', 'Opt-in real PostgreSQL module publish/archive gate.');
