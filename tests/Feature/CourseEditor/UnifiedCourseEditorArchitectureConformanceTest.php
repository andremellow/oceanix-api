<?php

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\MutateCourseAssessmentStructure;
use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Courses\ReorderDirectCourseContent;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\Videos\SyncVideoAsset;
use App\Contracts\VideoProvider;
use App\Data\Video\VideoAssetStatus;
use App\Enums\VideoStatus;
use App\Livewire\CourseEditor\Contexts\CompanyCourseEditorContext;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Question;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array<string, list<array<string, mixed>>> */
function architectureConformanceSnapshot(): array
{
    return collect([
        'courses',
        'course_versions',
        'course_version_lessons',
        'lessons',
        'questions',
        'question_options',
        'videos',
    ])->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
    ])->all();
}

/** @return array<string, mixed> */
function architectureStructurePayload(EditorFixture $fixture, string $operation, string $revision): array
{
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->orderBy('position')->firstOrFail();

    return match ($operation) {
        'add-record' => ['expected_revision' => $revision],
        'remove-record' => ['record_id' => $recordId, 'expected_revision' => $revision],
        'reorder-records' => ['ordered_ids' => array_reverse($fixture->recordIds), 'expected_revision' => $revision],
        'add-question' => ['record_id' => $recordId, 'expected_revision' => $revision],
        'remove-question' => ['record_id' => $recordId, 'question_id' => $question->id, 'expected_revision' => $revision],
        'add-option' => ['question_id' => $question->id, 'expected_revision' => $revision],
        'remove-option' => ['question_id' => $question->id, 'option_id' => $question->options->firstOrFail()->id, 'expected_revision' => $revision],
        'reorder-questions' => ['record_id' => $recordId, 'ordered_ids' => $fixture->root->versions()->firstOrFail()->lessons()->findOrFail($recordId)->questions()->orderByDesc('position')->pluck('id')->all(), 'expected_revision' => $revision],
        'reorder-options' => ['question_id' => $question->id, 'ordered_ids' => $question->options->sortByDesc('position')->pluck('id')->all(), 'expected_revision' => $revision],
        'change-composition' => ['ordered_ids' => [], 'expected_revision' => $revision],
    };
}

function invokeCompanyStructureAction(EditorFixture $fixture, string $operation, ?string $revision): void
{
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->orderBy('position')->firstOrFail();

    match ($operation) {
        'add-record' => app(AddDirectCourseLesson::class)->handle($version, $fixture->user, $revision),
        'remove-record' => app(RemoveDirectCourseLesson::class)->handle($version, $fixture->user, $recordId, $revision),
        'reorder-records' => app(ReorderDirectCourseContent::class)->handle($version, $fixture->user, 'lessons', null, array_reverse($fixture->recordIds), $revision),
        'add-question' => app(MutateCourseAssessmentStructure::class)->handle($version, $fixture->user, 'add_question', $recordId, null, $revision),
        'remove-question' => app(MutateCourseAssessmentStructure::class)->handle($version, $fixture->user, 'remove_question', $recordId, $question->id, $revision),
        'add-option' => app(MutateCourseAssessmentStructure::class)->handle($version, $fixture->user, 'add_option', $question->id, null, $revision),
        'remove-option' => app(MutateCourseAssessmentStructure::class)->handle($version, $fixture->user, 'remove_option', $question->id, $question->options->firstOrFail()->id, $revision),
        'reorder-questions' => app(ReorderDirectCourseContent::class)->handle($version, $fixture->user, 'questions', $recordId, $version->lessons()->findOrFail($recordId)->questions()->orderByDesc('position')->pluck('id')->all(), $revision),
        'reorder-options' => app(ReorderDirectCourseContent::class)->handle($version, $fixture->user, 'options', $question->id, $question->options->sortByDesc('position')->pluck('id')->all(), $revision),
        'change-composition' => app(UpdateCourseModuleComposition::class)->handle($version, [], $fixture->user, $revision),
    };
}

it('reauthorizes every company video-library operation after mount before provider or preview access', function (string $operation): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    Livewire::actingAs($fixture->user);
    $editor = Livewire::test($fixture->context->component, $fixture->routeParameters());
    $record = $editor->get('records')[0];

    if ($operation !== 'open') {
        $editor->set('videoLibraryOpen', true)
            ->set('videoLibraryRecordId', $record['id'])
            ->set('videoLibraryRecordKey', $record['key']);
    }

    $fixture->user->roles()->detach();
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldNotReceive('listAssets');
    $provider->shouldNotReceive('createAssetPreviewAuthorization');
    app()->instance(VideoProvider::class, $provider);

    match ($operation) {
        'open' => $editor->call('openEditorVideoLibrary', 'records.0.content_markdown')->assertForbidden(),
        'search' => $editor->call('searchVideoLibrary')->assertForbidden(),
        'select' => $editor->call('selectLibraryVideo', 'forbidden-asset')->assertForbidden()->assertNotDispatched('oceanix:insert-video'),
    };

    $editor->assertSet('videoLibraryItems', fn (?array $items): bool => blank($items));
})->with(['open', 'search', 'select']);

it('rejects missing and stale revisions in every company structure Action with an exact zero-write snapshot', function (string $operation, ?string $revision): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $before = architectureConformanceSnapshot();

    $exception = null;
    try {
        invokeCompanyStructureAction($fixture, $operation, $revision);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->when(
        $revision === null,
        fn ($value) => $value->toBeInstanceOf(TypeError::class),
        fn ($value) => $value->toBeInstanceOf(ValidationException::class),
    );

    expect(architectureConformanceSnapshot())->toBe($before);
})->with([
    'add record' => 'add-record',
    'remove record' => 'remove-record',
    'reorder records' => 'reorder-records',
    'add question' => 'add-question',
    'remove question' => 'remove-question',
    'add option' => 'add-option',
    'remove option' => 'remove-option',
    'reorder questions' => 'reorder-questions',
    'reorder options' => 'reorder-options',
    'change composition' => 'change-composition',
])->with([
    'missing revision' => null,
    'stale revision' => 'stale-editor-revision',
]);

it('uses one authenticated context boundary for every company structure operation and performs zero writes without an actor', function (string $operation): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $revision = app(EditorRevision::class)->forCompanyCourse($course, $version);
    $payload = architectureStructurePayload($fixture, $operation, $revision);
    $before = architectureConformanceSnapshot();
    auth()->logout();

    expect(fn () => app(CompanyCourseEditorContext::class)->performStructure($course->id, $operation, $payload))
        ->toThrow(HttpException::class);

    expect(architectureConformanceSnapshot())->toBe($before);
})->with([
    'add-record', 'remove-record', 'reorder-records', 'add-question', 'remove-question',
    'add-option', 'remove-option', 'reorder-questions', 'reorder-options', 'change-composition',
]);

it('authorizes an editor video sync before provider access and persists a successful authorized result', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $lesson = $version->lessons()->firstOrFail();
    $video = Video::factory()->processing()->create([
        'company_id' => $course->company_id,
        'lesson_id' => $lesson,
        'is_current' => true,
        'provider_asset_id' => 'authorized-sync-asset',
    ]);
    $revision = app(EditorRevision::class)->forCompanyCourse($course, $version);
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('getAssetStatus')->once()->with('authorized-sync-asset')->andReturn(
        new VideoAssetStatus(VideoStatus::Ready, 'authorized-playback', 321, ['hls' => 'https://video.example/authorized.m3u8']),
    );
    app()->instance(VideoProvider::class, $provider);

    $result = app(SyncVideoAsset::class)->forCompanyEditor($video, $fixture->user, $revision);

    expect($result->status)->toBe(VideoStatus::Ready)
        ->and($result->provider_playback_id)->toBe('authorized-playback')
        ->and($result->duration_seconds)->toBe(321);
});

it('denies revoked editor video sync before both the ready fast path and provider access with zero writes', function (VideoStatus $status): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $lesson = $version->lessons()->firstOrFail();
    $video = Video::factory()->create([
        'company_id' => $course->company_id,
        'lesson_id' => $lesson,
        'is_current' => true,
        'status' => $status,
        'metadata' => $status === VideoStatus::Ready ? ['hls' => 'https://video.example/already-ready.m3u8'] : null,
        'provider_asset_id' => 'revoked-sync-asset',
    ]);
    $revision = app(EditorRevision::class)->forCompanyCourse($course, $version);
    $fixture->user->roles()->detach();
    $before = architectureConformanceSnapshot();
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldNotReceive('getAssetStatus');
    app()->instance(VideoProvider::class, $provider);

    expect(fn () => app(SyncVideoAsset::class)->forCompanyEditor($video, $fixture->user, $revision))
        ->toThrow(AuthorizationException::class);

    expect(architectureConformanceSnapshot())->toBe($before);
})->with([
    'already ready' => VideoStatus::Ready,
    'provider reconciliation required' => VideoStatus::Processing,
]);
