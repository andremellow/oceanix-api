<?php

use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Courses\PrepareSharedCourseEditor;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Videos\RequestVideoUpload;
use App\Actions\Videos\SyncVideoAsset;
use App\Contracts\VideoProvider;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoUpload;
use App\Enums\CourseVersionStatus;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function exactCopyFixture(?string $content = "  <h2>Emergency procedure</h2>\n<p>Keep <strong>three points</strong> of contact.</p><img src=\"/storage/content-images/safety-diagram.png\" alt=\"Safety diagram\"><div data-oceanix-video></div>  ", bool $shared = true): array
{
    $course = Course::factory()->create(['is_shared' => $shared, 'company_id' => $shared ? null : app(TenantContext::class)->id(), 'code' => 'EXACT-COURSE', 'title' => ' Course identity title ', 'description' => ' Course description ']);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course, 'title' => ' Different edition title ', 'description' => '', 'completion_rule' => 'all_required_lessons']);
    $module = Lesson::factory()->create([
        'company_id' => $course->company_id, 'is_shared' => $shared, 'course_version_id' => $version->id,
        'code' => 'EXACT-MODULE', 'version_number' => 4, 'status' => 'published', 'title' => ' Module title ',
        'description' => null, 'content_markdown' => $content, 'position' => 7, 'is_required' => false,
        'minimum_watch_percentage' => 83, 'passing_score' => 79,
    ]);
    Video::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $module->id, 'is_current' => false, 'provider_asset_id' => 'obsolete-asset']);
    Video::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $module->id, 'provider_asset_id' => 'managed-asset', 'provider_playback_id' => 'managed-playback', 'duration_seconds' => 127, 'metadata' => ['caption' => ' Original caption ', 'nested' => ['empty' => '', 'absent' => null]], 'replacement_generation' => 9]);
    foreach ([3, 8, 11, 19, 22] as $index => $position) {
        $question = Question::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $module->id, 'position' => $position, 'type' => $index % 2 ? 'multiple_choice' : 'single_choice', 'prompt' => " Question {$position}? \n", 'weight' => $index + 2, 'max_attempts' => $index + 1]);
        foreach ([2, 6, 9, 12] as $optionIndex => $optionPosition) {
            QuestionOption::factory()->create(['company_id' => $course->company_id, 'question_id' => $question->id, 'position' => $optionPosition, 'text' => " Answer {$position}/{$optionPosition} ", 'is_correct' => $optionIndex === 0 || ($index % 2 && $optionIndex === 1)]);
        }
    }
    $course->update(['current_published_version_id' => $version->id]);

    return [$course, $version, ModuleVersion::findOrFail($module->id), Account::factory()->platformAdmin()->create()];
}

// Independent persisted oracle: deliberately does not use the production comparison helper.
function exactCopyContent(Lesson $module): array
{
    $row = DB::table('lessons')->where('id', $module->id)->first();
    $fields = ['code', 'title', 'description', 'content_markdown', 'type', 'position', 'is_required', 'minimum_watch_percentage', 'passing_score'];
    $video = DB::table('videos')->where('lesson_id', $module->id)->where('is_current', true)->latest('id')->first();

    return [
        'module' => array_intersect_key((array) $row, array_flip($fields)),
        'video' => $video === null ? null : array_intersect_key((array) $video, array_flip(['provider', 'provider_asset_id', 'provider_playback_id', 'duration_seconds', 'status', 'metadata'])),
        'questions' => DB::table('questions')->where('lesson_id', $module->id)->orderBy('position')->orderBy('id')->get()->map(fn ($question) => [
            'fields' => array_intersect_key((array) $question, array_flip(['type', 'prompt', 'position', 'max_attempts', 'weight'])),
            'options' => DB::table('question_options')->where('question_id', $question->id)->orderBy('position')->orderBy('id')->get()->map(fn ($option) => array_intersect_key((array) $option, array_flip(['text', 'is_correct', 'position'])))->all(),
        ])->all(),
    ];
}

function exactCopyPrepare(Course $course, Account $actor): CourseVersion
{
    $prepare = app(PrepareSharedCourseEditor::class);
    $draft = $course->manualDraftVersion();

    return $prepare->handle($course->fresh(), $actor, $prepare->revision($course->fresh(), $draft));
}

it('preserves every persisted field on module copy including rich empty and null content', function (?string $content): void {
    [$course, $source, $module, $actor] = exactCopyFixture($content);
    $before = exactCopyContent($module);
    $copy = app(CreateModuleDraft::class)->handle($module, $actor);

    expect(exactCopyContent($copy))->toBe($before)
        ->and(exactCopyContent($module))->toBe($before)
        ->and($copy->source_lesson_id)->toBe($module->id)
        ->and($copy->lineage_uuid)->toBe($module->lineage_uuid)
        ->and($copy->version_number)->toBe(5)
        ->and($copy->videos()->count())->toBe(1)
        ->and($copy->video->replacement_generation)->toBe(0);
})->with([null, '', "  ## Safety\n\n![Diagram](/storage/content-images/safety-diagram.png)\n\n:::video\n", '<h2>Safety</h2><p>Exact <em>rich</em> text.</p>']);

it('preserves legacy inline content ownership code and valid lineage versions', function (bool $shared): void {
    [$course, $source, $module, $actor] = exactCopyFixture(shared: $shared);
    $source->moduleCompositions()->delete();
    $before = exactCopyContent($module);
    $copy = app(CreateDraftFromVersion::class)->handle($source, $shared ? $actor : null)->lessons()->sole();

    expect(exactCopyContent($copy))->toBe($before)
        ->and(exactCopyContent($module))->toBe($before)
        ->and($copy->company_id)->toBe($module->company_id)
        ->and($copy->is_shared)->toBe($shared)
        ->and($copy->lineage_uuid)->toBe($module->lineage_uuid)
        ->and($copy->source_lesson_id)->toBe($module->id)
        ->and($copy->version_number)->toBe(5)
        ->and($copy->video->company_id)->toBe($module->company_id)
        ->and($copy->questions()->pluck('company_id')->unique()->all())->toBe([$module->company_id])
        ->and($copy->questions()->first()->options()->pluck('company_id')->unique()->all())->toBe([$module->company_id]);
})->with([true, false]);

it('reuses only identical preexisting drafts and preserves attached edits on repeated opening', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    app(CreateDraftFromVersion::class)->handle($source, $actor);
    $draft = exactCopyPrepare($course, $actor);
    expect($draft->moduleCompositions()->sole()->lesson_id)->toBe($existing->id);
    $existing->update(['content_markdown' => 'My existing edits']);
    exactCopyPrepare($course, $actor);
    expect($existing->fresh()->content_markdown)->toBe('My existing edits')
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($existing->id)
        ->and($module->fresh()->content_markdown)->not->toBe('My existing edits');
});

it('rejects preexisting draft differences across the full content graph without mutations', function (string $table, string $field, mixed $value): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $id = match ($table) {
        'lessons' => $existing->id,
        'videos' => $existing->video->id,
        'questions' => $existing->questions()->first()->id,
        'question_options' => $existing->questions()->first()->options()->first()->id,
    };
    DB::table($table)->where('id', $id)->update([$field => $value]);
    $before = exactCopyContent($existing);
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);

    expect(fn () => exactCopyPrepare($course, $actor))->toThrow(ValidationException::class)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and(exactCopyContent($existing))->toBe($before)
        ->and($existing->fresh()->source_lesson_id)->toBe($module->id);
})->with([
    ['lessons', 'code', 'DIFFERENT'], ['lessons', 'title', 'Changed'], ['lessons', 'description', ''],
    ['lessons', 'content_markdown', ''], ['lessons', 'position', 8], ['lessons', 'is_required', true],
    ['lessons', 'minimum_watch_percentage', 84], ['lessons', 'passing_score', 80],
    ['videos', 'provider', 'another-provider'], ['videos', 'provider_asset_id', 'another-asset'],
    ['videos', 'provider_playback_id', null], ['videos', 'duration_seconds', null], ['videos', 'status', 'processing'], ['videos', 'metadata', '{}'],
    ['questions', 'type', 'multiple_choice'], ['questions', 'prompt', 'Changed?'], ['questions', 'position', 4], ['questions', 'max_attempts', 7], ['questions', 'weight', 9],
    ['question_options', 'text', 'Changed'], ['question_options', 'is_correct', false], ['question_options', 'position', 3],
]);

it('rolls back earlier module copies when a later existing draft conflicts', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $earlier = Lesson::factory()->create(['is_shared' => true, 'company_id' => null, 'course_version_id' => $source->id, 'position' => 2, 'status' => 'published']);
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $existing->update(['content_markdown' => '']);
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    $before = $draft->moduleCompositions()->get()->toArray();
    $count = Lesson::count();
    expect(fn () => exactCopyPrepare($course, $actor))->toThrow(ValidationException::class)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($before)
        ->and(Lesson::count())->toBe($count)
        ->and($existing->fresh()->content_markdown)->toBe('');
});

it('shows the conflict on create and reopen without navigating to different content', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    app(CreateModuleDraft::class)->handle($module, $actor)->update(['content_markdown' => '']);
    $this->withSession(['platform_account_id' => $actor->id]);
    $message = __('A module already has a draft with different content. Resolve that module draft before opening this course draft. Existing work has been preserved.');
    Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->call('createDraft')->assertHasErrors('draft')->assertSee($message)->assertNoRedirect();
    Livewire::test('platform.shared-courses.show', ['course' => $course->fresh()])
        ->call('editDraft')->assertHasErrors('draft')->assertSee($message)->assertNoRedirect();
    expect($course->manualDraftVersion()->moduleCompositions()->sole()->lesson_id)->toBe($module->id);
});

it('opens and saves a clean course draft without normalizing any source content', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $courseBefore = $course->fresh()->getAttributes();
    $sourceBefore = $source->fresh()->getAttributes();
    $moduleBefore = exactCopyContent($module);
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    expect($draft->only(['title', 'description', 'completion_rule']))->toBe($source->only(['title', 'description', 'completion_rule']));
    exactCopyPrepare($course, $actor);
    $copy = $draft->moduleCompositions()->sole()->moduleVersion;
    $pivotBefore = $draft->moduleCompositions()->get()->toArray();
    $draftBefore = $draft->fresh()->getAttributes();
    $this->withSession(['platform_account_id' => $actor->id]);
    Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSet('editorDirty', false)
        ->assertSet('modules.0.content_markdown', fn ($value) => str_contains($value, 'Emergency procedure') && str_contains($value, 'Safety diagram') && str_contains($value, 'data-oceanix-video'))
        ->assertSet('modules.0.questions.4.prompt', " Question 22? \n")
        ->call('saveDraft', false)->assertHasNoErrors()->assertSet('editorDirty', false);

    expect(exactCopyContent($copy))->toBe($moduleBefore)
        ->and(exactCopyContent($module))->toBe($moduleBefore)
        ->and($course->fresh()->getAttributes())->toBe($courseBefore)
        ->and($source->fresh()->getAttributes())->toBe($sourceBefore)
        ->and($draft->fresh()->getAttributes())->toBe($draftBefore)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($pivotBefore);
});

it('copies the current media even when a newer noncurrent record exists', function (bool $legacy): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    Video::factory()->create(['company_id' => null, 'lesson_id' => $module->id, 'is_current' => false, 'provider_asset_id' => 'newer-noncurrent-asset']);
    $before = exactCopyContent($module);
    expect($module->fresh()->video?->provider_asset_id)->toBe('managed-asset')
        ->and(ModuleVersion::with('video')->findOrFail($module->id)->video?->provider_asset_id)->toBe('managed-asset');
    if ($legacy) {
        $source->moduleCompositions()->delete();
        $copy = app(CreateDraftFromVersion::class)->handle($source, $actor)->lessons()->sole();
    } else {
        $copy = app(CreateModuleDraft::class)->handle($module, $actor);
    }

    expect(exactCopyContent($copy))->toBe($before)
        ->and($copy->video?->provider_asset_id)->toBe('managed-asset');
})->with([true, false]);

it('rejects an already-draft legacy source without creating a second lineage draft', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $source->moduleCompositions()->delete();
    DB::table('lessons')->where('id', $module->id)->update(['status' => 'draft']);
    $before = exactCopyContent($module);
    $sourceBefore = $module->fresh()->getAttributes();
    $versionCount = CourseVersion::count();
    expect(fn () => app(CreateDraftFromVersion::class)->handle($source, $actor))->toThrow(ValidationException::class)
        ->and(CourseVersion::count())->toBe($versionCount)
        ->and(ModuleVersion::where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->count())->toBe(1)
        ->and(exactCopyContent($module))->toBe($before)
        ->and($module->fresh()->getAttributes())->toBe($sourceBefore);
});

it('rejects missing media questions and options in an existing draft', function (string $missing): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    match ($missing) {
        'video' => $existing->video->delete(),
        'question' => $existing->questions()->first()->delete(),
        'option' => $existing->questions()->first()->options()->first()->delete(),
    };
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    $before = exactCopyContent($existing);
    expect(fn () => exactCopyPrepare($course, $actor))->toThrow(ValidationException::class)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and(exactCopyContent($existing))->toBe($before);
})->with(['video', 'question', 'option']);

it('keeps empty source content empty when opening and saving the editor', function (?string $content): void {
    [$course, $source, $module, $actor] = exactCopyFixture($content);
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    exactCopyPrepare($course, $actor);
    $copy = $draft->moduleCompositions()->sole()->moduleVersion;
    $before = exactCopyContent($module);
    $this->withSession(['platform_account_id' => $actor->id]);
    Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSet('modules.0.content_markdown', '')
        ->assertSet('editorDirty', false)
        ->call('saveDraft', false)->assertHasNoErrors();
    expect(exactCopyContent($copy))->toBe($before)
        ->and($copy->fresh()->content_markdown)->toBe($content);
})->with([null, '']);

it('returns no current media when only noncurrent videos exist', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    DB::table('videos')->where('lesson_id', $module->id)->update(['is_current' => false]);
    expect($module->fresh()->video)->toBeNull()
        ->and(ModuleVersion::with('video')->findOrFail($module->id)->video)->toBeNull();
    $copy = app(CreateModuleDraft::class)->handle($module, $actor);
    expect($copy->video)->toBeNull()->and($copy->videos()->count())->toBe(0);
});

it('clones and prepares an empty course without inventing module content', function (): void {
    $course = Course::factory()->shared()->create();
    $source = CourseVersion::factory()->published()->create(['course_id' => $course, 'title' => 'Empty edition', 'description' => null]);
    $actor = Account::factory()->platformAdmin()->create();
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    $prepared = exactCopyPrepare($course, $actor);

    expect($prepared->id)->toBe($draft->id)
        ->and($draft->only(['title', 'description', 'completion_rule']))->toBe($source->only(['title', 'description', 'completion_rule']))
        ->and($prepared->moduleCompositions()->count())->toBe(0)
        ->and($prepared->lessons()->count())->toBe(0)
        ->and($source->moduleCompositions()->count())->toBe(0)
        ->and($source->lessons()->count())->toBe(0);
    expect(exactCopyPrepare($course, $actor)->id)->toBe($draft->id);
});

it('presents a legacy lineage draft conflict without creating course or module records', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $source->moduleCompositions()->delete();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $before = exactCopyContent($existing);
    $sourceBefore = exactCopyContent($module);
    $count = CourseVersion::count();
    $this->withSession(['platform_account_id' => $actor->id]);
    Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->call('createDraft')->assertHasErrors('draft')->assertNoRedirect()
        ->assertSee(__('A module is archived or already has an open draft version. Resolve that module before creating this course draft. Existing work has been preserved.'));
    expect(CourseVersion::count())->toBe($count)
        ->and(ModuleVersion::where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->count())->toBe(1)
        ->and(exactCopyContent($existing))->toBe($before)
        ->and(exactCopyContent($module))->toBe($sourceBefore)
        ->and($source->moduleCompositions()->count())->toBe(0);
});

it('copies the requested legacy snapshot using the highest lineage version for allocation', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $source->moduleCompositions()->delete();
    $later = ModuleVersion::create([
        ...$module->only(['company_id', 'is_shared', 'code', 'lineage_uuid', 'type', 'position', 'is_required', 'minimum_watch_percentage', 'passing_score']),
        'course_version_id' => null, 'version_number' => 9, 'status' => 'retired',
        'title' => 'Different later title', 'content_markdown' => 'Later content must not be copied',
        'source_lesson_id' => $module->id,
    ]);
    $before = exactCopyContent($module);
    $copy = app(CreateDraftFromVersion::class)->handle($source, $actor)->lessons()->sole();
    expect($copy->version_number)->toBe(10)
        ->and($copy->source_lesson_id)->toBe($module->id)
        ->and(exactCopyContent($copy))->toBe($before)
        ->and(exactCopyContent($module))->toBe($before)
        ->and($later->fresh()->content_markdown)->toBe('Later content must not be copied');
});

it('rejects archived legacy lineages without writes', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $source->moduleCompositions()->delete();
    DB::table('lessons')->where('id', $module->id)->update(['lineage_archived_at' => now()]);
    $before = $module->fresh()->getAttributes();
    $courseCount = CourseVersion::count();
    $moduleCount = Lesson::count();
    $auditCount = DB::table('audit_logs')->count();
    expect(fn () => app(CreateDraftFromVersion::class)->handle($source, $actor))->toThrow(ValidationException::class)
        ->and(CourseVersion::count())->toBe($courseCount)
        ->and(Lesson::count())->toBe($moduleCount)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount)
        ->and($module->fresh()->getAttributes())->toBe($before)
        ->and($source->moduleCompositions()->count())->toBe(0);
});

it('preserves immutable composition through requested replacement conflict and later promotion', function (string $status, bool $expired, bool $tied): void {
    $this->freezeTime();
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('createUpload')->once()->andReturn(new VideoUpload('cloudflare_stream', 'replacement-asset', 'https://upload.example/replacement'));
    $provider->shouldReceive('getAssetStatus')->once()->with('replacement-asset')->andReturn(new VideoAssetStatus(VideoStatus::Ready, 'replacement-playback', 300));
    app()->instance(VideoProvider::class, $provider);
    $upload = app(RequestVideoUpload::class)->handle($existing, platformActor: $actor);
    $candidate = Video::findOrFail($upload->videoId);
    $candidate->update(['status' => $status]);
    if ($expired) {
        DB::table('videos')->where('id', $candidate->id)->update(['created_at' => now()->subDays(2)]);
    }
    if ($tied) {
        $current = $existing->video;
        $newerCurrent = $current->replicate();
        $current->update(['is_current' => false]);
        $newerCurrent->fill(['is_current' => true, 'replacement_generation' => $candidate->replacement_generation])->save();
    }
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    $composition = $draft->moduleCompositions()->get()->toArray();
    $sourceBefore = exactCopyContent($module);
    $existingBefore = exactCopyContent($existing);
    $videosBefore = $existing->videos()->orderBy('id')->get()->toArray();

    expect(fn () => exactCopyPrepare($course, $actor))->toThrow(ValidationException::class)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($composition)
        ->and(exactCopyContent($module))->toBe($sourceBefore)
        ->and(exactCopyContent($existing))->toBe($existingBefore)
        ->and($existing->videos()->orderBy('id')->get()->toArray())->toBe($videosBefore);

    app(SyncVideoAsset::class)->handle($candidate);

    expect($existing->fresh()->video->id)->toBe($candidate->id)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and(exactCopyContent($module))->toBe($sourceBefore);
})->with([
    'uploading' => ['uploading', false, false],
    'processing' => ['processing', false, false],
    'expired upload' => ['uploading', true, false],
    'failed' => ['failed', false, false],
    'ready' => ['ready', false, false],
    'tied current generation' => ['processing', false, true],
]);

it('reuses identical drafts with obsolete replacement history that cannot promote', function (string $status): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $existing->video->update(['replacement_generation' => 3]);
    $obsolete = Video::factory()->create(['company_id' => null, 'lesson_id' => $existing->id, 'is_current' => false, 'replacement_generation' => 2, 'status' => $status]);
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('getAssetStatus')->once()->with($obsolete->provider_asset_id)->andReturn(new VideoAssetStatus(VideoStatus::Ready));
    app()->instance(VideoProvider::class, $provider);
    app(CreateDraftFromVersion::class)->handle($source, $actor);

    $draft = exactCopyPrepare($course, $actor);
    app(SyncVideoAsset::class)->handle($obsolete);

    expect($draft->moduleCompositions()->sole()->lesson_id)->toBe($existing->id)
        ->and($existing->fresh()->video->id)->not->toBe($obsolete->id)
        ->and(exactCopyContent($existing))->toBe(exactCopyContent($module));
})->with(['uploading', 'processing', 'failed', 'ready']);

it('reopens an attached edited draft with a pending replacement', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    app(CreateDraftFromVersion::class)->handle($source, $actor);
    $draft = exactCopyPrepare($course, $actor);
    $existing = $draft->moduleCompositions()->sole()->moduleVersion;
    $existing->update(['content_markdown' => 'Keep these draft edits']);
    $candidate = Video::factory()->processing()->create(['company_id' => null, 'lesson_id' => $existing->id, 'is_current' => false, 'replacement_generation' => 1]);

    exactCopyPrepare($course, $actor);

    expect($draft->moduleCompositions()->sole()->lesson_id)->toBe($existing->id)
        ->and($existing->fresh()->content_markdown)->toBe('Keep these draft edits')
        ->and($candidate->fresh()->is_current)->toBeFalse();
});

it('rolls back earlier module preparation when a later pending replacement conflicts', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    Lesson::factory()->create(['is_shared' => true, 'company_id' => null, 'course_version_id' => $source->id, 'position' => 2, 'status' => 'published']);
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    Video::factory()->processing()->create(['company_id' => null, 'lesson_id' => $existing->id, 'is_current' => false, 'replacement_generation' => 1]);
    $draft = app(CreateDraftFromVersion::class)->handle($source, $actor);
    $before = $draft->moduleCompositions()->get()->toArray();
    $counts = [Lesson::count(), Video::count(), Question::count(), QuestionOption::count(), AuditLog::withoutGlobalScopes()->count()];

    expect(fn () => exactCopyPrepare($course, $actor))->toThrow(ValidationException::class)
        ->and($draft->moduleCompositions()->get()->toArray())->toBe($before)
        ->and([Lesson::count(), Video::count(), Question::count(), QuestionOption::count(), AuditLog::withoutGlobalScopes()->count()])->toBe($counts);
});

it('discards a retained creation conflict in the same session with a required reason', function (): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    $existing = app(CreateModuleDraft::class)->handle($module, $actor);
    $existing->update(['content_markdown' => 'Independent authoring work']);
    $before = exactCopyContent($existing);
    $sourceBefore = exactCopyContent($module);
    $videosBefore = $existing->videos()->get()->toArray();
    $this->withSession(['platform_account_id' => $actor->id]);
    $show = Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->assertSet('discardRevision', '')
        ->call('createDraft')->assertHasErrors('draft')->assertNoRedirect()
        ->set('confirmingDiscard', true)
        ->call('discardDraft')->assertHasErrors(['discardReason' => 'required']);
    $draft = $course->manualDraftVersion();
    expect($draft->status)->toBe(CourseVersionStatus::Draft);
    $show->set('discardReason', 'Restart this course composition')
        ->call('discardDraft')->assertHasNoErrors()
        ->assertSet('confirmingDiscard', false)->assertSet('discardReason', '')->assertSet('discardRevision', '')
        ->assertSet('selectedVersionId', $source->id);

    expect($draft->fresh()->status)->toBe(CourseVersionStatus::Discarded)
        ->and(exactCopyContent($existing))->toBe($before)
        ->and($existing->videos()->get()->toArray())->toBe($videosBefore)
        ->and(exactCopyContent($module))->toBe($sourceBefore)
        ->and($draft->moduleCompositions()->sole()->lesson_id)->toBe($module->id)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->sole()->after['reason'])->toBe('Restart this course composition');
});

it('rejects stale or revoked discard after a retained creation conflict', function (string $change): void {
    [$course, $source, $module, $actor] = exactCopyFixture();
    app(CreateModuleDraft::class)->handle($module, $actor)->update(['content_markdown' => 'Independent authoring work']);
    $this->withSession(['platform_account_id' => $actor->id]);
    $show = Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->call('createDraft')->assertHasErrors('draft')->assertNoRedirect();
    $draft = $course->manualDraftVersion();
    if ($change === 'composition') {
        $draft->moduleCompositions()->sole()->update(['position' => 99]);
    } elseif ($change === 'draft') {
        DB::table('course_versions')->where('id', $draft->id)->update(['updated_at' => now()->addMinute()]);
    } else {
        $actor->update(['is_platform_admin' => false]);
    }
    if ($change === 'revocation') {
        $show->call('discardDraft')->assertForbidden();
    } else {
        $show->set('discardReason', 'Stale discard attempt')->call('discardDraft')->assertHasErrors('discard')
            ->assertSee(__('This draft changed elsewhere. Reload the page before trying again.'));
    }
    expect($draft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'shared_course.draft_discarded')->exists())->toBeFalse();
})->with(['composition', 'draft', 'revocation']);
