<?php

require __DIR__.'/QaBootstrap.php';

use App\Actions\Modules\CreateModuleDraft;
use App\Enums\Permission;
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

if (($argv[1] ?? '') === 'archive-count') {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    $id = $argv[2] ?? '';
    abort_unless(in_array($id, array_column($manifest['library']['company'], 'id'), true), 404);
    echo LessonDocument::where('public_id', $id)->sole()->archive()->count();
    exit;
}
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
if (preg_match('/^(fail|restore)-(list|archive|reuse)$/', $argv[1] ?? '', $failure)) {
    $path = $directory.'/fail-'.$failure[2];
    if ($failure[1] === 'fail') {
        touch($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
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
if (in_array($argv[1] ?? '', ['revoke-library', 'restore-library'], true)) {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    $user = User::withoutGlobalScopes()->findOrFail($manifest['libraryUser']);
    app(TenantContext::class)->set($user->company);
    $role = Role::findOrFail($manifest['libraryRole']);
    if ($argv[1] === 'revoke-library') {
        $user->roles()->detach($role);
    } else {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
    exit;
}
if (in_array($argv[1] ?? '', ['revoke-archive-permission', 'restore-archive-permission'], true)) {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    $user = User::withoutGlobalScopes()->findOrFail($manifest['libraryUser']);
    app(TenantContext::class)->set($user->company);
    $role = Role::findOrFail($manifest['libraryRole']);
    $id = App\Models\Permission::where('key', Permission::LessonDocumentsArchive->value)->sole()->id;
    if ($argv[1] === 'revoke-archive-permission') {
        $role->permissions()->detach($id);
    } else {
        $role->permissions()->syncWithoutDetaching([$id]);
    }
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
$libraryUser = User::factory()->create(['name' => 'PDF Library Editor']);
$editorRole = Role::factory()->create(['key' => 'pdf-editor', 'name' => 'PDF editor']);
$libraryRole = Role::factory()->create(['key' => 'pdf-library', 'name' => 'PDF library operator']);
foreach ([$editorRole->id => [Permission::CoursesUpdate], $libraryRole->id => [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]] as $roleId => $grants) {
    $ids = [];
    foreach (Permission::withPrerequisites($grants) as $key) {
        $permission = Permission::from($key);
        $ids[] = App\Models\Permission::firstOrCreate(['key' => $key], ['label' => $permission->label(), 'group' => $permission->group()])->id;
    }
    Role::findOrFail($roleId)->permissions()->sync($ids);
    $libraryUser->roles()->attach($roleId);
}
$manifest['libraryUser'] = $libraryUser->id;
$manifest['libraryRole'] = $libraryRole->id;
$companyB = Company::factory()->create(['slug' => 'pdf-qa-b', 'name' => 'PDF QA Company B']);
$authorB = User::factory()->create(['name' => 'Company B PDF Author']);
$roleB = Role::factory()->create(['key' => 'admin', 'name' => 'Administrator']);
$authorB->roles()->attach($roleB);
$manifest['authorB'] = $authorB->id;
$emptyCompany = Company::factory()->create(['slug' => 'pdf-qa-empty']);
app(TenantContext::class)->set($emptyCompany);
$emptyAuthor = User::factory()->create();
$emptyAuthor->roles()->attach(Role::factory()->create(['key' => 'admin']));
$manifest['emptyAuthor'] = $emptyAuthor->id;
$emptyCourse = Course::factory()->draft()->create(['title' => 'Empty library course']);
$emptyVersion = CourseVersion::factory()->create(['course_id' => $emptyCourse->id]);
Lesson::factory()->create(['course_version_id' => $emptyVersion->id, 'content_markdown' => $html]);
$manifest['emptyEditor'] = '/c/pdf-qa-empty/courses/'.$emptyCourse->id.'/editor';
app(TenantContext::class)->set($company);
$manifest['library'] = [];
foreach (['company' => $company->id, 'platform' => null, 'foreign' => $companyB->id] as $owner => $companyId) {
    $count = $owner === 'foreign' ? 1 : 65;
    foreach (range(1, $count) as $i) {
        $uuid = (string) Str::uuid();
        $name = $i === 65 ? ucfirst($owner).' reusable guide.pdf' : ($i === 64 ? str_repeat('Long name ', 20).'.pdf' : ucfirst($owner).' duplicate.pdf');
        $pdf = LessonDocument::create(['public_id' => $uuid, 'company_id' => $companyId, 'is_shared' => $companyId === null, 'name' => $name, 'disk' => 'lesson_documents', 'path' => $uuid.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => filesize(base_path('tests/Fixtures/lesson-guide.pdf'))]);
        Storage::disk('lesson_documents')->put($pdf->path, file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')));
        $manifest['library'][$owner][] = ['id' => $uuid, 'name' => $name];
        if ($i >= 64) {
            $source = Lesson::factory()->create(['company_id' => $companyId, 'is_shared' => $companyId === null, 'content_markdown' => '<p><a href="/lesson-documents/'.$uuid.'">Original library source</a></p>']);
            $source->documents()->attach($pdf);
        }
    }
}
file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
