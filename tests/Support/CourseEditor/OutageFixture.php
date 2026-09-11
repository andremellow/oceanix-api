<?php

use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? 'create') === 'read') {
    fwrite(STDOUT, json_encode(['title' => Course::query()->findOrFail((int) $argv[2])->title], JSON_THROW_ON_ERROR));
    exit(0);
}

$company = Company::query()->where('slug', 'oceanix-demo')->firstOrFail();
app(TenantContext::class)->set($company);
$course = Course::query()->create([
    'company_id' => $company->id, 'code' => 'OUTAGE-EDITOR', 'title' => 'Outage editor original title',
    'description' => 'Synthetic editor fixture for the real stop and restart gate.', 'status' => 'draft', 'is_shared' => false,
]);
$version = CourseVersion::query()->create([
    'course_id' => $course->id, 'version_number' => 1, 'status' => 'draft',
    'title' => $course->title, 'description' => $course->description,
]);
$lesson = Lesson::query()->create([
    'company_id' => $company->id, 'course_version_id' => $version->id, 'title' => 'Outage editor lesson',
    'description' => 'Synthetic lesson', 'content_markdown' => 'Synthetic content', 'position' => 1,
    'is_required' => true, 'minimum_watch_percentage' => 90, 'passing_score' => 70,
]);
$question = Question::query()->create([
    'company_id' => $company->id, 'lesson_id' => $lesson->id,
    'prompt' => 'Which recovery result is correct?', 'position' => 1, 'type' => 'single_choice',
]);
QuestionOption::query()->create(['company_id' => $company->id, 'question_id' => $question->id, 'text' => 'The staged value survives', 'position' => 1, 'is_correct' => true]);
QuestionOption::query()->create(['company_id' => $company->id, 'question_id' => $question->id, 'text' => 'The staged value is lost', 'position' => 2, 'is_correct' => false]);

fwrite(STDOUT, json_encode(['companySlug' => $company->slug, 'courseId' => $course->id, 'originalTitle' => $course->title], JSON_THROW_ON_ERROR));
