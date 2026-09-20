<?php

use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Documents\ReuseLessonDocument;
use App\Actions\Documents\UploadLessonDocument;
use App\Enums\Permission;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

function draftRemovalPdfFixture(string $origin = 'uploaded'): array
{
    $fixture = EditorFixture::create(EditorContextCase::all()['company course']);
    grantPermissions($fixture->user, [Permission::LessonDocumentsReuse]);
    $source = Lesson::findOrFail($fixture->recordIds[0]);
    $target = Lesson::findOrFail($fixture->recordIds[1]);
    $version = $target->courseVersion;
    $revision = fn (): string => app(EditorRevision::class)->forCompanyCourse($fixture->root->fresh(), $version->fresh());
    $upload = UploadedFile::fake()->createWithContent('Retained guide.pdf', file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')))->mimeType('application/pdf');
    $result = app(UploadLessonDocument::class)->handle($upload, 'company-course', $fixture->root->id, $origin === 'uploaded' ? $target->id : $source->id, $fixture->user, $revision());
    $document = LessonDocument::where('public_id', $result['id'])->sole();
    app(ReuseLessonDocument::class)->handle($document->public_id, 'company-course', $fixture->root->id, $origin === 'uploaded' ? $source->id : $target->id, $fixture->user, $revision());
    foreach ([$source, $target] as $lesson) {
        $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Retained guide</a></p>']);
    }

    return [$fixture, $source, $target, $document, $version, $revision];
}

function draftRemovalSnapshot(): array
{
    return collect(['lessons', 'lesson_document', 'lesson_documents', 'course_version_lessons', 'questions', 'question_options'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
}

beforeEach(fn () => Storage::fake('lesson_documents'));

it('removes a draft lesson with PDFs while preserving other saved uses and ordering', function (string $origin): void {
    [$fixture, $source, $target, $document, $version, $revision] = draftRemovalPdfFixture($origin);
    $metadata = $document->getAttributes();
    $sourceAttributes = $source->fresh()->getAttributes();
    $sourcePivot = DB::table('lesson_document')->where('lesson_id', $source->id)->first();
    $bytes = Storage::disk($document->disk)->get($document->path);
    // A second PDF has no surviving use, but its immutable metadata and bytes must also remain.
    $upload = UploadedFile::fake()->createWithContent('Only here.pdf', $bytes)->mimeType('application/pdf');
    $result = app(UploadLessonDocument::class)->handle($upload, 'company-course', $fixture->root->id, $target->id, $fixture->user, $revision());
    $exclusive = LessonDocument::where('public_id', $result['id'])->sole();
    $exclusiveMetadata = $exclusive->getAttributes();

    app(RemoveDirectCourseLesson::class)->handle($version, $fixture->user, $target->id, $revision());

    expect(Lesson::find($target->id))->toBeNull()
        ->and(DB::table('lesson_document')->where('lesson_id', $target->id)->count())->toBe(0)
        ->and($version->lessons()->orderBy('position')->pluck('id')->all())->toBe([$source->id, $fixture->recordIds[2]])
        ->and($version->lessons()->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($version->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())->toBe([$source->id, $fixture->recordIds[2]])
        ->and($version->moduleCompositions()->orderBy('position')->pluck('position')->all())->toBe([1, 2])
        ->and($source->fresh()->getAttributes())->toBe($sourceAttributes)
        ->and(DB::table('lesson_document')->where('lesson_id', $source->id)->first())->toEqual($sourcePivot)
        ->and($document->fresh()->getAttributes())->toBe($metadata)
        ->and($exclusive->fresh()->getAttributes())->toBe($exclusiveMetadata)
        ->and(Storage::disk($document->disk)->get($document->path))->toBe($bytes)
        ->and(Storage::disk($exclusive->disk)->get($exclusive->path))->toBe($bytes);
    $url = route('courses.lessons.documents', ['company' => currentCompany(), 'course' => $fixture->root, 'lesson' => $source, 'document' => $document->public_id]);
    expect($this->actingAs($fixture->user)->get($url)->assertOk()->streamedContent())->toBe($bytes);
})->with(['uploaded', 'reused']);

it('preserves all PDF associations when draft removal is denied', function (string $boundary): void {
    [$fixture, , $target, $document, $version, $revision] = draftRemovalPdfFixture();
    $expectedRevision = $revision();
    $exception = AuthorizationException::class;
    if ($boundary === 'revoked') {
        $fixture->user->roles()->detach();
    } elseif ($boundary === 'published') {
        $version->update(['status' => 'published']);
    } else {
        $target->update(['title' => 'Changed in another session']);
        $exception = ValidationException::class;
    }
    $before = draftRemovalSnapshot();
    $bytes = Storage::disk($document->disk)->get($document->path);

    expect(fn () => app(RemoveDirectCourseLesson::class)->handle($version, $fixture->user, $target->id, $expectedRevision))->toThrow($exception);

    expect(draftRemovalSnapshot())->toBe($before)
        ->and(Storage::disk($document->disk)->get($document->path))->toBe($bytes);
})->with(['revoked', 'published', 'stale']);

it('rolls back PDF detachment when lesson deletion fails', function (): void {
    [$fixture, , $target, $document, $version, $revision] = draftRemovalPdfFixture();
    $before = draftRemovalSnapshot();
    $bytes = Storage::disk($document->disk)->get($document->path);
    $dispatcher = Lesson::getEventDispatcher();
    Lesson::setEventDispatcher(clone $dispatcher);
    Lesson::deleting(function (Lesson $lesson) use ($target): void {
        if ($lesson->id === $target->id) {
            expect($lesson->documents()->count())->toBe(0);
            throw new RuntimeException('Simulated lesson deletion failure');
        }
    });
    try {
        expect(fn () => app(RemoveDirectCourseLesson::class)->handle($version, $fixture->user, $target->id, $revision()))
            ->toThrow(RuntimeException::class, 'Simulated lesson deletion failure');
    } finally {
        Lesson::setEventDispatcher($dispatcher);
    }

    expect(draftRemovalSnapshot())->toBe($before)
        ->and(Storage::disk($document->disk)->get($document->path))->toBe($bytes);
});
