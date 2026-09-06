<?php

use App\Actions\Modules\CreateModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\DiscardModuleDraft;
use App\Actions\Modules\PublishModuleVersion;
use App\Enums\ModuleVersionStatus;
use App\Enums\PlatformPermission;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function moduleDiscardFixture(): array
{
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'DISCARD-01', 'Original module');

    return [$actor, $module, $module->versions()->sole(), app(DiscardModuleDraft::class)];
}

it('discards an initial draft without deleting its media or assessment and records actor and reason', function () {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $video = Video::factory()->create(['company_id' => null, 'lesson_id' => $draft->id]);
    $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $draft->id]);
    $option = QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id]);
    $before = [$video->fresh()->getAttributes(), $question->fresh()->getAttributes(), $option->fresh()->getAttributes()];

    $discarded = $action->handle($draft, $actor, '  No longer needed  ', $action->revision($draft));

    expect($discarded->status)->toBe(ModuleVersionStatus::Discarded)
        ->and($discarded->isEditable())->toBeFalse()
        ->and([$video->fresh()->getAttributes(), $question->fresh()->getAttributes(), $option->fresh()->getAttributes()])->toBe($before);
    $audit = AuditLog::withoutGlobalScopes()->where('action', 'shared_module.draft_discarded')->sole();
    expect($audit->after)->toBe(['status' => 'discarded', 'reason' => 'No longer needed'])
        ->and($audit->platform_account_id)->toBe($actor->id);
    expect(fn () => app(PublishModuleVersion::class)->handle($discarded, $actor))->toThrow(LogicException::class);
    expect(fn () => app(CreateModuleDraft::class)->handle($discarded, $actor))->toThrow(LogicException::class);
});

it('preserves the published version and allows a replacement draft with a new version number', function () {
    [$actor, $module, $published, $action] = moduleDiscardFixture();
    $published->update(['status' => ModuleVersionStatus::Published]);
    $before = $published->fresh()->getAttributes();
    $draft = app(CreateModuleDraft::class)->handle($published, $actor);
    $draft->update(['title' => 'Abandoned edits']);

    $action->handle($draft, $actor, 'Restart', $action->revision($draft));
    $replacement = app(CreateModuleDraft::class)->handle($published, $actor);

    expect($published->fresh()->getAttributes())->toBe($before)
        ->and($replacement->version_number)->toBe(3)
        ->and($replacement->title)->toBe('Original module')
        ->and($draft->fresh()->title)->toBe('Abandoned edits');
});

it('rejects a referenced draft and preserves the course composition', function () {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $course = CourseVersion::factory()->shared()->create();
    $pivot = CourseVersionModule::create(['course_version_id' => $course->id, 'lesson_id' => $draft->id, 'position' => 1, 'is_required' => true]);
    $before = $pivot->fresh()->getAttributes();

    expect(fn () => $action->handle($draft, $actor, 'Remove', $action->revision($draft)))->toThrow(ValidationException::class)
        ->and($draft->fresh()->status)->toBe(ModuleVersionStatus::Draft)
        ->and($pivot->fresh()->getAttributes())->toBe($before)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'shared_module.draft_discarded')->exists())->toBeFalse();
});

it('rejects stale confirmation after content assessment or media changes', function (string $change) {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $revision = $action->revision($draft);
    match ($change) {
        'content' => $draft->update(['content_markdown' => 'Saved elsewhere']),
        'assessment' => Question::factory()->create(['company_id' => null, 'lesson_id' => $draft->id]),
        'media' => Video::factory()->create(['company_id' => null, 'lesson_id' => $draft->id]),
    };

    expect(fn () => $action->handle($draft, $actor, 'Discard', $revision))->toThrow(ValidationException::class)
        ->and($draft->fresh()->status)->toBe(ModuleVersionStatus::Draft);
})->with(['content', 'assessment', 'media']);

it('rejects publication archive or repeated discard after confirmation', function (string $change) {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $revision = $action->revision($draft);
    $draft->update($change === 'archived' ? ['lineage_archived_at' => now()] : ['status' => $change]);

    expect(fn () => $action->handle($draft, $actor, 'Discard', $revision))->toThrow(ValidationException::class)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'shared_module.draft_discarded')->exists())->toBeFalse();
})->with(['published', 'archived', 'discarded']);

it('requires a bounded nonblank reason in the action', function (string $reason) {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    expect(fn () => $action->handle($draft, $actor, $reason, $action->revision($draft)))->toThrow(ValidationException::class)
        ->and($draft->fresh()->status)->toBe(ModuleVersionStatus::Draft);
})->with(['   ', str_repeat('x', 501)]);

it('denies direct action after platform authority is revoked', function (string $change) {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $actor->update($change === 'role' ? ['is_platform_admin' => false] : ['status' => 'inactive']);
    try {
        $action->handle($draft, $actor, 'Discard', $action->revision($draft));
        $this->fail('Expected authorization denial');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    expect($draft->fresh()->status)->toBe(ModuleVersionStatus::Draft);
})->with(['role', 'status']);

it('confirms and discards through the module detail screen with localized feedback', function () {
    [$actor, $module, $draft] = moduleDiscardFixture();
    $this->withSession(['platform_account_id' => $actor->id, 'locale' => 'pt_BR']);
    Livewire::test('platform.shared-modules.show', ['module' => $module])
        ->assertSee(__('Discard draft'))
        ->call('confirmDiscard')->assertSet('confirmingDiscard', true)
        ->call('discardDraft')->assertHasErrors(['discardReason' => 'required'])
        ->set('discardReason', 'No longer needed')->call('discardDraft')->assertHasNoErrors()
        ->assertSet('confirmingDiscard', false)
        ->assertSee(__('Module draft discarded. Published versions and history remain available.'))
        ->assertSee(__('Discarded'))
        ->assertDontSeeHtml('wire:click="confirmDiscard"');
    expect($draft->fresh()->status)->toBe(ModuleVersionStatus::Discarded);
    $this->get(route('platform.shared-modules.editor', $module))->assertNotFound();
});

it('denies an already open discard dialog after platform access is revoked', function () {
    [$actor, $module, $draft] = moduleDiscardFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $screen = Livewire::test('platform.shared-modules.show', ['module' => $module])->call('confirmDiscard')->set('discardReason', 'Discard');
    $actor->update(['is_platform_admin' => false]);
    $screen->call('discardDraft')->assertForbidden();
    expect($draft->fresh()->status)->toBe(ModuleVersionStatus::Draft);
});

it('declares the discard permission prerequisites', function () {
    expect(PlatformPermission::SharedModulesDiscardDraft->prerequisites())->toBe([
        PlatformPermission::SharedModulesView, PlatformPermission::SharedModulesUpdate,
    ]);
});

it('keeps company-owned modules outside the platform discard boundary', function () {
    [$actor, $module, $draft, $action] = moduleDiscardFixture();
    $private = ModuleVersion::factory()->create();
    try {
        $action->handle($private, $actor, 'Discard', $action->revision($private));
        $this->fail('Expected ownership denial');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    expect($private->fresh()->status)->toBe(ModuleVersionStatus::Draft);
    $this->withSession(['platform_account_id' => $actor->id]);
    $this->get(route('platform.shared-modules.show', $private))->assertNotFound();
});

it('denies direct module detail access to a non-platform account', function () {
    [$actor, $module] = moduleDiscardFixture();
    $account = Account::factory()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    $this->get(route('platform.shared-modules.show', $module))->assertRedirect(route('platform.login'));
    Livewire::test('platform.shared-modules.show', ['module' => $module])->assertForbidden();
});
