<?php

use App\Actions\Documents\UploadLessonDocument;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__, 3).'/public'.$path)) {
    return false;
}
require __DIR__.'/QaBootstrap.php';
Route::middleware('web')->get('/__pdf-qa/login/{user}', function (int $user) {
    $actor = User::withoutGlobalScopes()->findOrFail($user);
    Auth::login($actor);
    session(['company_id' => $actor->company_id]);

    return response('Disposable PDF QA session ready.');
});
Route::middleware(['web', 'auth'])->get('/__pdf-qa/published-upload', function () use ($directory) {
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
    abort_unless(auth()->id() === $manifest['author'], 403);
    $copy = json_decode(file_get_contents($directory.'/copy.json'), true);
    app(TenantContext::class)->set(auth()->user()->company);

    return app(UploadLessonDocument::class)->handle(
        new UploadedFile(base_path('tests/Fixtures/lesson-guide.pdf'), 'Published attempt.pdf', 'application/pdf', null, true),
        'shared-module', $copy['module'], $copy['source'], auth()->user()->account, 'published',
    );
});
$app->handleRequest(Request::capture());
