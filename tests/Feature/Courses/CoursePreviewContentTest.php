<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('projects only exact composed items with static choices and sanitized content without training writes', function () {
    Http::preventStrayRequests();
    $version = CourseVersion::factory()->create();
    $included = Lesson::factory()->create(['course_version_id' => $version->id, 'title' => 'Included item', 'content_markdown' => '<h2>Saved content</h2><script>steal()</script><img src="javascript:bad">']);
    $excluded = Lesson::factory()->create(['course_version_id' => $version->id, 'title' => 'Excluded item', 'position' => 2]);
    CourseVersionModule::where('lesson_id', $excluded->id)->delete();
    $question = Question::factory()->create(['lesson_id' => $included->id, 'prompt' => 'Distinct question']);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'text' => 'First editorial choice']);
    QuestionOption::factory()->create(['question_id' => $question->id, 'text' => 'Second editorial choice']);
    $link = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $token = basename($link['url']);
    trainableAssignment();
    $tables = ['user_training_assignments', 'course_attempts', 'lesson_attempts', 'question_attempts', 'lesson_progress', 'compliance_events', 'certificates'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()]);
    $this->get($link['url'])->assertOk()->assertSee('Included item')->assertDontSee('Excluded item');
    $item = CourseVersionModule::where('lesson_id', $included->id)->first();
    $url = route('course-preview.item', ['token' => $token, 'kind' => 'composition', 'item' => $item->id]);
    $this->get($url)->assertOk()->assertSee('Distinct question')->assertSee('First editorial choice')->assertSee('Second editorial choice')->assertDontSee('is_correct')->assertDontSee('steal()')->assertDontSee('javascript:')->assertDontSee('type="radio"', false)->assertDontSee('my-training');
    $this->postJson($url, ['answer' => 1])->assertStatus(405);
    $this->get(route('course-preview.item', ['token' => $token, 'kind' => 'lesson', 'item' => $excluded->id]))->assertNotFound();
    $other = Lesson::factory()->create();
    $this->get(route('course-preview.item', ['token' => $token, 'kind' => 'composition', 'item' => 999999]))->assertNotFound();
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->get()->toJson())->toBe($rows);
    }
    Http::assertNothingSent();
});
it('supports legacy lessons only when no composition exists and follows saved edits', function () {
    $version = CourseVersion::factory()->create();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id, 'content_markdown' => 'Legacy body']);
    $version->moduleCompositions()->delete();
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $url = route('course-preview.item', ['token' => basename($data['url']), 'kind' => 'lesson', 'item' => $lesson->id]);
    $this->get($url)->assertOk()->assertSee('Legacy body');
    $lesson->update(['content_markdown' => 'Saved new body']);
    $this->get($url)->assertOk()->assertSee('Saved new body')->assertDontSee('Legacy body');
});
it('gives unknown and malformed links private generic unavailable responses', function (string $token) {
    $this->get('/preview/courses/'.$token)->assertNotFound()->assertSee('Esta prévia está indisponível.')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->getJson('/preview/courses/'.$token)->assertNotFound()->assertJsonStructure(['error', 'message'])->assertDontSee($token);
})->with(['invalid', str_repeat('a', 64)]);

it('rejects real foreign compositions and legacy lesson identifiers', function () {
    $version = CourseVersion::factory()->create();
    $own = Lesson::factory()->create(['course_version_id' => $version->id, 'title' => 'Allowed']);
    $ownCompany = currentCompany();
    $foreignCompany = Company::factory()->create();
    $foreignVersion = CourseVersion::factory()->for(Course::factory()->create(['company_id' => $foreignCompany->id]))->create();
    $foreign = Lesson::factory()->create(['company_id' => $foreignCompany->id, 'course_version_id' => $foreignVersion->id, 'title' => 'Foreign confidential item']);
    $foreignItem = CourseVersionModule::where('lesson_id', $foreign->id)->first();
    app(TenantContext::class)->set($ownCompany);
    $link = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $token = basename($link['url']);
    $this->get(route('course-preview.item', ['token' => $token, 'kind' => 'composition', 'item' => $foreignItem->id]))->assertNotFound()->assertDontSee($foreign->title);
    $version->moduleCompositions()->delete();
    $this->get(route('course-preview.item', ['token' => $token, 'kind' => 'lesson', 'item' => $foreign->id]))->assertNotFound()->assertDontSee($foreign->title);
    $this->get(route('course-preview.item', ['token' => $token, 'kind' => 'lesson', 'item' => $own->id]))->assertOk()->assertSee('Allowed');
});
