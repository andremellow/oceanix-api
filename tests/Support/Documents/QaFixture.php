<?php

require __DIR__.'/QaBootstrap.php';

use App\Actions\Modules\CreateModuleDraft;
use App\Models\Account;
use App\Models\Company;
use App\Models\Course;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (($argv[1] ?? '') === 'revoke') {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    $actor = User::withoutGlobalScopes()->findOrFail($manifest['author']);
    app(TenantContext::class)->set($actor->company);
    UserTrainingAssignment::query()->findOrFail((int) $argv[2])->update(['status' => 'cancelled']);
    exit;
}
if (($argv[1] ?? '') === 'fail-storage') {
    touch($directory.'/fail-storage');
    exit;
}
if (($argv[1] ?? '') === 'restore-storage') {
    if (is_file($directory.'/fail-storage')) {
        unlink($directory.'/fail-storage');
    }
    exit;
}
if (($argv[1] ?? '') === 'expire-preview') {
    // Test-only clock/expiry fixture mutation, never operational data.
    CoursePreviewLink::query()->update(['expires_at' => now()->subMinute()]);
    exit;
}
if (($argv[1] ?? '') === 'prepare-copy') {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    $actor = User::withoutGlobalScopes()->findOrFail($manifest['author']);
    app(TenantContext::class)->set($actor->company);
    $module = Module::withoutGlobalScopes()->findOrFail($manifest['sharedModule']);
    $source = $module->versions()->where('status', 'draft')->firstOrFail();
    $source->update(['status' => 'published']);
    $draft = app(CreateModuleDraft::class)->handle($source, $actor->account);
    $copy = ['module' => $module->id, 'source' => $source->id, 'draft' => $draft->id, 'sourceHtml' => $source->fresh()->content_markdown, 'draftHtml' => $draft->content_markdown, 'documentIds' => $draft->documents()->pluck('public_id')->all(), 'preview' => $manifest['sharedModulePreview']];
    file_put_contents($directory.'/copy.json', json_encode($copy, JSON_PRETTY_PRINT));
    echo json_encode($copy, JSON_PRETTY_PRINT);
    exit;
}
if (is_file($directory.'/database.sqlite')) {
    throw new RuntimeException('Use a new disposable directory; existing fixtures are retained.');
}
touch($directory.'/database.sqlite');
mkdir($directory.'/sessions');
Artisan::call('migrate', ['--force' => true]);
$company = Company::factory()->create(['slug' => 'pdf-qa', 'name' => 'PDF QA Company']);
app(TenantContext::class)->set($company);
$account = Account::factory()->platformAdmin()->create();
$author = User::factory()->create(['account_id' => $account->id, 'name' => 'PDF QA Author']);
$role = Role::factory()->create(['key' => 'admin', 'name' => 'Administrator']);
$author->roles()->attach($role);
$learner = User::factory()->create(['name' => 'PDF QA Learner']);
$unrelated = User::factory()->create(['name' => 'Unrelated PDF QA user']);
$html = '<p><strong>Before</strong> Read guide after <a href="https://example.com">ordinary link</a></p>';
$html .= '<p>prefix<strong> leading </strong><em>trailing </em>separator</p><p>0</p><p><strong>'.str_repeat('Long selected text ', 35).'</strong><em>formatted end </em>separator</p>';
$companyCourse = Course::factory()->draft()->create(['title' => 'PDF company course']);
$companyVersion = CourseVersion::factory()->create(['course_id' => $companyCourse->id]);
$lesson = Lesson::factory()->create(['course_version_id' => $companyVersion->id, 'content_markdown' => $html, 'title' => 'Company PDF lesson']);
$module = Module::factory()->shared()->create(['status' => 'active', 'title' => 'PDF shared module']);
$moduleVersion = ModuleVersion::factory()->create(['module_id' => $module->id, 'content_markdown' => $html, 'title' => 'Shared PDF lesson']);
$sharedCourse = Course::factory()->shared()->draft()->create(['title' => 'PDF shared course']);
$sharedVersion = CourseVersion::factory()->create(['course_id' => $sharedCourse->id]);
$composition = CourseVersionModule::create(['course_version_id' => $sharedVersion->id, 'lesson_id' => $moduleVersion->id, 'position' => 1, 'is_required' => true]);
$document = LessonDocument::create(['public_id' => (string) Str::uuid(), 'company_id' => $company->id, 'is_shared' => false, 'name' => 'QA guide.pdf', 'disk' => 'lesson_documents', 'path' => 'qa-guide.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => filesize(base_path('tests/Fixtures/lesson-guide.pdf'))]);
Storage::disk('lesson_documents')->put($document->path, file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')));
$lesson->documents()->attach($document);
$lesson->update(['content_markdown' => $html.'<p><a href="/lesson-documents/'.$document->public_id.'" target="_blank" rel="noopener noreferrer">Saved PDF guide</a></p>']);
$assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner->id, 'course_id' => $companyCourse->id, 'course_version_id' => $companyVersion->id]);
$token = bin2hex(random_bytes(32));
CoursePreviewLink::create(['course_version_id' => $companyVersion->id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => $token, 'generated_at' => now(), 'expires_at' => now()->addDay()]);
$manifest = ['author' => $author->id, 'learner' => $learner->id, 'unrelated' => $unrelated->id, 'assignment' => $assignment->id, 'sharedModule' => $module->id,
    'companyEditor' => '/c/pdf-qa/courses/'.$companyCourse->id.'/editor', 'sharedCourseEditor' => '/platform/shared-courses/'.$sharedCourse->id.'/editor', 'sharedModuleEditor' => '/platform/shared-modules/'.$module->id.'/editor',
    'learnerLesson' => '/c/pdf-qa/my-training/'.$assignment->id.'/lessons/'.$lesson->id,
    'companyPreview' => '/c/pdf-qa/courses/'.$companyCourse->id.'/lessons/'.$lesson->id.'/preview',
    'sharedCoursePreview' => '/platform/shared-courses/'.$sharedCourse->id.'/versions/'.$sharedVersion->id.'/preview?kind=composition&item='.$composition->id,
    'sharedModulePreview' => '/platform/shared-modules/'.$module->id.'/preview',
    'preview' => '/preview/courses/'.$token.'/items/composition/'.$lesson->courseVersion->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id,
    'document' => $document->public_id, 'lesson' => $lesson->id];
file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
