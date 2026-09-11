<?php

use App\Enums\Permission;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Client;
use Tests\Support\CourseEditor\BrowserEnvironment;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;
use Tests\TestCase;

beforeEach(fn () => BrowserEnvironment::assertReady());

/** @return array{EditorFixture, Webpage} */
function openUnifiedEditor(TestCase $test, string $contextName, bool $withAttachedVideo = false): array
{
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);

    if ($withAttachedVideo) {
        attachVideoForUnifiedBrowserFixture($fixture);
    }

    if ($fixture->user !== null) {
        $test->actingAs($fixture->user);
    }

    if ($fixture->session !== []) {
        $test->withSession($fixture->session);
    }

    return [$fixture, visit($fixture->url())];
}

function attachVideoForUnifiedBrowserFixture(EditorFixture $fixture): Video
{
    $record = $fixture->context->name === 'company-course'
        ? Lesson::query()->findOrFail($fixture->recordIds[0])
        : ModuleVersion::query()->findOrFail($fixture->recordIds[0]);

    return Video::factory()->create([
        'lesson_id' => $record->id,
        'company_id' => $record->company_id,
        'provider_asset_id' => $fixture->token.'-attached-video',
        'status' => VideoStatus::Ready,
        'is_current' => true,
        'replacement_generation' => 1,
        'metadata' => ['hls' => 'https://video.example/manifest.m3u8', 'width' => 1280, 'height' => 720],
    ]);
}

test('every context exposes the same observable editor contract', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);

    $page->assertNoJavascriptErrors()
        ->assertPresent("[data-editor-root][data-editor-context=\"{$fixture->context->name}\"]")
        ->assertPresent('[data-editor-save]')
        ->assertPresent('[data-editor-status][data-editor-state]')
        ->assertPresent('[data-editor-record][data-record-type][data-record-key]')
        ->assertPresent('[data-editor-field][data-field-name]');

    expect($page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-editor-record]')]
            .every(record => /^(course|version|lesson|module-version|question|option):\d+$/.test(record.dataset.recordKey))
    JS))->toBeTrue();

    foreach ($fixture->context->notApplicable as $action => $reason) {
        expect($reason)->not->toBeEmpty();
        $page->assertNotPresent("[data-editor-structure-action=\"{$action}\"]");
    }
})->with('course editor contexts');

test('every context keeps video access in the rich-content toolbar without a standalone record footer', function (string $contextName): void {
    [, $page] = openUnifiedEditor($this, $contextName, withAttachedVideo: true);
    $videoControl = '[data-editor-media-action="open-library"][data-editor-action-detail="open-video-library"]';

    $page->assertNoJavascriptErrors()
        ->assertPresent($videoControl);

    $evidence = $page->script(<<<'JS'
        () => {
            const root = document.querySelector('[data-editor-root]');
            const legacyCopy = ['No video attached', 'Choose or upload video', 'Replace video', 'Remove video'];

            return {
                legacyCopy: legacyCopy.filter(copy => root.innerText.includes(copy)),
                toolbarControlCount: root.querySelectorAll('[data-editor-media-action="open-library"][data-editor-action-detail="open-video-library"]').length,
            };
        }
    JS);

    expect($evidence['legacyCopy'])->toBe([])
        ->and($evidence['toolbarControlCount'])->toBeGreaterThan(0);

    $page->click($videoControl)
        ->wait(0.5)
        ->assertPresent('[data-editor-upload-picker][data-record-key]')
        ->assertPresent('[wire\\:click="searchVideoLibrary"]');
})->with('course editor contexts');

test('hydration exposes an explicit loading state and locks operational actions until ready', function (string $contextName): void {
    [, $page] = openUnifiedEditor($this, $contextName);

    $evidence = $page->script(<<<'JS'
        async () => {
            const root = document.querySelector('[data-editor-root]');
            const state = Alpine.$data(root);
            const action = document.querySelector('[data-editor-action-detail="add-question"]');
            state.ready = false;
            state.synchronizeOperationalControls();
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const authored = [...root.querySelectorAll('[data-editor-field] input:not([type="hidden"]), [data-editor-field] textarea, [data-editor-field] select, [data-editor-field] [contenteditable]')];
            const operational = [...root.querySelectorAll('[data-editor-structure-action], [data-editor-media-action], [data-editor-save], [data-editor-save-close], [data-editor-cancel]')];
            const locked = control => control.disabled
                || control.getAttribute('aria-disabled') === 'true'
                || control.closest('[inert]') !== null
                || (control.hasAttribute('contenteditable') && control.getAttribute('contenteditable') !== 'true');
            const loading = {
                state: root.dataset.editorHydrationState,
                shellVisible: root.getBoundingClientRect().height > 0,
                visible: document.querySelector('[data-editor-loading]').getBoundingClientRect().height > 0,
                text: document.querySelector('[data-editor-loading]').textContent,
                disabled: action.disabled,
                describedBy: action.getAttribute('aria-describedby') || '',
                authoredCount: authored.length,
                operationalCount: operational.length,
                authoredLocked: authored.every(locked),
                operationalLocked: operational.every(locked),
            };
            state.ready = true;
            state.synchronizeOperationalControls();
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            return {
                loading,
                readyState: root.dataset.editorHydrationState,
                loadingHidden: document.querySelector('[data-editor-loading]').getBoundingClientRect().height === 0,
                enabled: !action.disabled,
            };
        }
    JS);

    expect($evidence['loading']['state'])->toBe('loading')
        ->and($evidence['loading']['shellVisible'])->toBeTrue()
        ->and($evidence['loading']['visible'])->toBeTrue()
        ->and($evidence['loading']['text'])->toContain('Loading editor')
        ->and($evidence['loading']['text'])->toContain('locking actions')
        ->and($evidence['loading']['disabled'])->toBeTrue()
        ->and($evidence['loading']['describedBy'])->toContain('editor-operational-guidance')
        ->and($evidence['loading']['authoredCount'])->toBeGreaterThan(0)
        ->and($evidence['loading']['operationalCount'])->toBeGreaterThan(0)
        ->and($evidence['loading']['authoredLocked'])->toBeTrue()
        ->and($evidence['loading']['operationalLocked'])->toBeTrue()
        ->and($evidence['readyState'])->toBe('ready')
        ->and($evidence['loadingHidden'])->toBeTrue()
        ->and($evidence['enabled'])->toBeTrue();
})->with('course editor contexts');

test('active uploads reactively lock every editor close path with exact wait guidance', function (string $contextName): void {
    [, $page] = openUnifiedEditor($this, $contextName);

    $evidence = $page->script(<<<'JS'
        async () => {
            const root = document.querySelector('[data-editor-root]');
            const state = Alpine.$data(root);
            const cancel = root.querySelector('[data-editor-cancel]');
            const saveClose = root.querySelector('[data-editor-save-close]');
            const guidance = root.querySelector('[data-editor-upload-close-guidance]');
            const originalUrl = location.href;
            const activeUpload = document.createElement('div');
            activeUpload.dataset.editorUpload = '';
            activeUpload.dataset.uploadState = 'uploading';
            activeUpload.dataset.recordKey = 'lesson:test-upload-lock';
            root.append(activeUpload);

            state.uploadInProgress = true;
            state.synchronizeOperationalControls();
            await Alpine.nextTick();
            cancel.click();
            await Alpine.nextTick();

            const active = {
                cancelLocked: cancel.getAttribute('aria-disabled') === 'true',
                saveCloseLocked: saveClose.disabled,
                cancelDescription: cancel.getAttribute('aria-describedby'),
                saveCloseDescription: saveClose.getAttribute('aria-describedby'),
                guidanceId: guidance.id,
                guidanceText: guidance.textContent.trim(),
                guidanceExposed: getComputedStyle(guidance).display !== 'none',
                navigationBlocked: location.href === originalUrl,
            };

            activeUpload.remove();
            state.uploadInProgress = true;
            await Alpine.nextTick();
            state.uploadInProgress = false;
            state.synchronizeOperationalControls();
            await Alpine.nextTick();

            return {
                active,
                cancelUnlocked: cancel.getAttribute('aria-disabled') !== 'true',
                saveCloseUnlocked: !saveClose.disabled,
            };
        }
    JS);

    expect($evidence['active']['cancelLocked'])->toBeTrue()
        ->and($evidence['active']['saveCloseLocked'])->toBeTrue()
        ->and($evidence['active']['cancelDescription'])->toContain($evidence['active']['guidanceId'])
        ->and($evidence['active']['saveCloseDescription'])->toContain($evidence['active']['guidanceId'])
        ->and($evidence['active']['guidanceText'])->toBe('Wait for active uploads to finish before closing.')
        ->and($evidence['active']['guidanceExposed'])->toBeTrue()
        ->and($evidence['active']['navigationBlocked'])->toBeTrue();
})->with('course editor contexts');

test('a lost immediate-operation response becomes an action-specific danger alert while ordinary guidance stays status', function (string $contextName): void {
    [, $page] = openUnifiedEditor($this, $contextName);

    $evidence = $page->script(<<<'JS'
        async () => {
            const root = document.querySelector('[data-editor-root]');
            const state = Alpine.$data(root);
            const guidance = root.querySelector('[data-editor-operational-guidance]');
            const action = root.querySelector('[data-editor-action-detail="add-question"]');
            const identity = state.operationIdentity(action);
            state.activeOperationMeta[identity.key] = { ...identity, external: true };
            state.activeOperations.add(identity.key);
            state.finishExternalOperation({ key: identity.key, state: 'failed' });
            await Alpine.nextTick();

            const danger = {
                severity: guidance.dataset.guidanceSeverity,
                error: guidance.dataset.editorGuidanceError,
                role: guidance.getAttribute('role'),
                live: guidance.getAttribute('aria-live'),
                action: guidance.dataset.actionDetail,
                target: guidance.dataset.targetKey,
                text: guidance.textContent.trim(),
                dangerStyle: guidance.classList.contains('border-red-200')
                    && guidance.classList.contains('bg-red-50')
                    && guidance.classList.contains('text-red-900'),
            };

            state.showOperationalGuidance(action, 'Wait for the current editor operation to finish.');
            await Alpine.nextTick();
            return {
                danger,
                status: {
                    severity: guidance.dataset.guidanceSeverity,
                    role: guidance.getAttribute('role'),
                    live: guidance.getAttribute('aria-live'),
                },
            };
        }
    JS);

    expect($evidence['danger']['severity'])->toBe('danger')
        ->and($evidence['danger']['error'])->toBe('true')
        ->and($evidence['danger']['role'])->toBe('alert')
        ->and($evidence['danger']['live'])->toBe('assertive')
        ->and($evidence['danger']['action'])->toBe('add-question')
        ->and($evidence['danger']['target'])->toMatch('/^(lesson|module-version):\d+$/')
        ->and($evidence['danger']['text'])->toContain('response for add-question')
        ->and($evidence['danger']['text'])->toContain('was lost')
        ->and($evidence['danger']['text'])->toContain('Automatic retry is unavailable')
        ->and($evidence['danger']['text'])->toContain('outcome is unknown')
        ->and($evidence['danger']['dangerStyle'])->toBeTrue()
        ->and($evidence['status'])->toBe([
            'severity' => 'status',
            'role' => 'status',
            'live' => 'polite',
        ]);
})->with('course editor contexts');

test('an immediate action becomes locally pending before dispatch and its duplicate is locked', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'company course');
    $recordId = $fixture->recordIds[0];
    $before = Question::query()->where('lesson_id', $recordId)->count();
    $selector = '[data-editor-action-detail="add-question"]';

    $page->script(<<<'JS'
        () => {
            const control = document.querySelector('[data-editor-action-detail="add-question"]');
            control.addEventListener('click', () => {
                window.__editorPendingEvidence = {
                    state: control.dataset.editorOperationState,
                    busy: control.getAttribute('aria-busy'),
                    disabled: control.disabled,
                };
                control.click();
            }, { once: true });
        }
    JS);
    $page->click($selector)->wait(0.8);

    expect($page->script('() => window.__editorPendingEvidence'))->toBe([
        'state' => 'pending',
        'busy' => 'true',
        'disabled' => true,
    ])->and(Question::query()->where('lesson_id', $recordId)->count())->toBe($before + 1);
});

test('new lesson question and option actions focus the new first labelled authored field', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'company course');

    $page->click('[data-editor-action-detail="add-lesson"]')->wait(0.5);
    $lessonId = $fixture->root->versions()->where('status', 'draft')->sole()->lessons()->max('id');
    $lessonKey = 'lesson:'.$lessonId;
    expect($page->script('() => ({ record: document.activeElement.closest("[data-record-key]")?.dataset.recordKey, field: document.activeElement.closest("[data-editor-primary-field]")?.dataset.fieldName, labelled: Boolean(document.activeElement.labels?.length || document.activeElement.getAttribute("aria-label") || document.activeElement.getAttribute("aria-labelledby")) })'))
        ->toBe(['record' => $lessonKey, 'field' => 'module.title', 'labelled' => true]);

    $page->click("[data-record-key=\"{$lessonKey}\"] [data-editor-action-detail=\"add-question\"]")->wait(0.5);
    $question = Question::query()->where('lesson_id', $lessonId)->latest('id')->firstOrFail();
    $questionKey = 'question:'.$question->id;
    expect($page->script('() => ({ record: document.activeElement.closest("[data-record-key]")?.dataset.recordKey, field: document.activeElement.closest("[data-editor-primary-field]")?.dataset.fieldName, labelled: Boolean(document.activeElement.labels?.length || document.activeElement.getAttribute("aria-label") || document.activeElement.getAttribute("aria-labelledby")) })'))
        ->toBe(['record' => $questionKey, 'field' => 'question.prompt', 'labelled' => true]);

    $page->click("[data-record-key=\"{$questionKey}\"] [data-editor-action-detail=\"add-option\"]")->wait(0.5);
    $option = QuestionOption::query()->where('question_id', $question->id)->latest('id')->firstOrFail();
    expect($page->script('() => ({ record: document.activeElement.closest("[data-record-key]")?.dataset.recordKey, field: document.activeElement.closest("[data-editor-primary-field]")?.dataset.fieldName, labelled: Boolean(document.activeElement.labels?.length || document.activeElement.getAttribute("aria-label") || document.activeElement.getAttribute("aria-labelledby")) })'))
        ->toBe(['record' => 'option:'.$option->id, 'field' => 'option.text', 'labelled' => true]);
});

test('every context can add type and add another answer without saving the staged value', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->orderBy('position')->firstOrFail();
    $questionSelector = "[data-record-key=\"question:{$question->id}\"]";
    $addSelector = $questionSelector.' [data-editor-action-detail="add-option"]';
    $initialCount = $question->options()->count();
    $initialOptionIds = $question->options()->pluck('id')->all();
    $initialPrompt = $question->prompt;
    $existing = $question->options()->orderBy('position')->firstOrFail();
    $initialExistingText = $existing->text;

    $page->click($addSelector)->wait(0.5);

    $firstAdded = $question->options()->whereNotIn('id', $initialOptionIds)->firstOrFail();
    $persistedBefore = $firstAdded->text;
    $firstField = "[data-record-key=\"option:{$firstAdded->id}\"] [data-field-name=\"option.text\"] input";
    $promptField = $questionSelector.' [data-field-name="question.prompt"] input';
    $existingField = "[data-record-key=\"option:{$existing->id}\"] [data-field-name=\"option.text\"] input";
    $stagedText = $fixture->token.' staged browser answer';
    $stagedPrompt = $fixture->token.' staged browser prompt';
    $stagedExisting = $fixture->token.' staged existing browser answer';
    $page->fill($firstField, $stagedText)
        ->fill($promptField, $stagedPrompt)
        ->fill($existingField, $stagedExisting)
        ->wait(0.2);

    expect($page->script("() => ({ disabled: document.querySelector('{$addSelector}').disabled, ariaDisabled: document.querySelector('{$addSelector}').getAttribute('aria-disabled') })"))
        ->toBe(['disabled' => false, 'ariaDisabled' => null]);

    $page->click($addSelector)
        ->wait(0.7)
        ->assertValue($firstField, $stagedText)
        ->assertValue($promptField, $stagedPrompt)
        ->assertValue($existingField, $stagedExisting)
        ->assertPresent($questionSelector.' [data-record-key^="option:"]');

    $secondAdded = $question->options()->whereNotIn('id', [...$initialOptionIds, $firstAdded->id])->firstOrFail();
    $secondField = "[data-record-key=\"option:{$secondAdded->id}\"] [data-field-name=\"option.text\"] input";
    $secondStaged = $fixture->token.' second staged browser answer';
    $page->fill($secondField, $secondStaged)->assertValue($secondField, $secondStaged);

    expect($question->options()->count())->toBe($initialCount + 2)
        ->and($firstAdded->fresh()->text)->toBe($persistedBefore)
        ->and($question->fresh()->prompt)->toBe($initialPrompt)
        ->and($existing->fresh()->text)->toBe($initialExistingText)
        ->and($page->script("() => document.querySelectorAll('{$questionSelector} [data-record-key^=\"option:\"]').length"))->toBe($initialCount + 2);

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertValue($firstField, $stagedText)
        ->assertValue($secondField, $secondStaged)
        ->assertValue($promptField, $stagedPrompt)
        ->assertValue($existingField, $stagedExisting);
    expect($firstAdded->fresh()->text)->toBe($stagedText)
        ->and($secondAdded->fresh()->text)->toBe($secondStaged)
        ->and($question->fresh()->prompt)->toBe($stagedPrompt)
        ->and($existing->fresh()->text)->toBe($stagedExisting);
})->with('course editor contexts');

test('a value typed after an immediate request starts wins over the response morph and a later confirmed removal never resurrects', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->orderBy('position')->firstOrFail();
    $options = $question->options()->orderBy('position')->get();
    $option = $options->first();
    $originalOptionIds = $options->pluck('id')->all();
    $persistedBefore = $option->text;
    $beforeCount = $question->options()->count();
    $fieldSelector = "[data-record-key=\"option:{$option->id}\"] [data-field-name=\"option.text\"]";
    $inputSelector = $fieldSelector.' input';
    $addSelector = "[data-record-key=\"question:{$question->id}\"] [data-editor-action-detail=\"add-option\"]";
    $newer = $fixture->token.' typed after request start';
    $recordKey = $fixture->context->name === 'company-course' ? 'lesson:'.$recordId : 'module-version:'.$recordId;
    $payload = json_encode([
        'fieldSelector' => $fieldSelector,
        'inputSelector' => $inputSelector,
        'addSelector' => $addSelector,
        'value' => $newer,
    ], JSON_THROW_ON_ERROR);

    $script = str_replace('__PAYLOAD__', $payload, <<<'JS'
        () => {
            const payload = __PAYLOAD__;
            const root = document.querySelector('[data-editor-root]');
            const state = Alpine.$data(root);
            let applied = false;
            Livewire.hook('request', () => {
                if (applied) return;
                applied = true;
                setTimeout(() => {
                    const input = document.querySelector(payload.inputSelector);
                    input.addEventListener('input', event => { window.__editorNewerInputTrusted = event.isTrusted; }, { once: true });
                    input.focus();
                    input.select();
                    window.__editorNewerValueApplied = document.execCommand('insertText', false, payload.value);
                }, 0);
            });
            document.querySelector(payload.addSelector).click();
            return true;
        }
        JS);
    $revisionScript = "() => { const revisions = Alpine.\$data(document.querySelector('[data-editor-root]')).\$wire.revisions; return revisions['record:{$recordId}'] || revisions.root; }";
    $beforeRevision = $page->script($revisionScript);
    $started = $page->script($script);

    expect($started)->toBeTrue();
    $page->wait(1)
        ->assertScript('window.__editorNewerValueApplied === true')
        ->assertScript('window.__editorNewerInputTrusted === true')
        ->assertValue($inputSelector, $newer)
        ->assertDataAttribute($fieldSelector, 'editor-field-key', $recordKey.'/question:'.$question->id.'/option:'.$option->id.'/option.text');

    $afterRevision = $page->script($revisionScript);
    $added = $question->options()->whereNotIn('id', $originalOptionIds)->sole();
    expect($afterRevision)->not->toBe($beforeRevision)
        ->and($question->options()->count())->toBe($beforeCount + 1)
        ->and($option->fresh()->text)->toBe($persistedBefore);

    $confirmSelector = '[wire\\:click="confirmAnswerDestruction('.$recordId.', '.$question->id.', '.$added->id.')"]';
    $page->click($confirmSelector)
        ->wait(0.2)
        ->click('[data-editor-destructive-confirmation][wire\\:submit="performConfirmedDestructive"] [data-editor-destructive-submit]')
        ->wait(0.5)
        ->assertValue($inputSelector, $newer);
    $afterRemovalRevision = $page->script($revisionScript);
    expect($afterRemovalRevision)->not->toBe($afterRevision)
        ->and($question->options()->count())->toBe($beforeCount)
        ->and(QuestionOption::query()->find($added->id))->toBeNull();

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertValue($inputSelector, $newer);
    expect($option->fresh()->text)->toBe($newer)
        ->and(QuestionOption::query()->find($added->id))->toBeNull();
})->with('course editor contexts');

test('a newly created shared module focuses its first labelled title field', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'shared course');

    $page->click('[wire\\:click="openNewModuleModal"][data-editor-action-detail="composition-create"]')
        ->wait(0.2)
        ->fill('[wire\\:model="newModuleForm.code"]', 'FOCUS-'.$fixture->token)
        ->fill('[wire\\:model="newModuleForm.title"]', $fixture->token.' Focus module')
        ->click('form[wire\\:submit="createNewModule"] [data-editor-action-detail="composition-create"]')
        ->wait(1.5);

    $focus = $page->script(<<<'JS'
        () => ({
            record: document.activeElement.closest('[data-record-key]')?.dataset.recordKey || null,
            field: document.activeElement.closest('[data-editor-primary-field]')?.dataset.fieldName || null,
            labelled: Boolean(document.activeElement.labels?.length || document.activeElement.getAttribute('aria-label') || document.activeElement.getAttribute('aria-labelledby')),
            modalOpen: document.querySelector('form[wire\\:submit="createNewModule"]')?.closest('dialog')?.open || false,
            error: document.querySelector('[data-editor-operation][data-operation-state="failed"]')?.textContent.trim() || null,
        })
    JS);
    expect($focus['record'])->toStartWith('module-version:')
        ->and($focus['field'])->toBe('module.title')
        ->and($focus['labelled'])->toBeTrue()
        ->and($focus['modalOpen'])->toBeFalse()
        ->and($focus['error'])->toBeNull();
});

test('blur writes nothing and the common Save persists the authored value', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $fieldName = $fixture->context->name === 'shared-module' ? 'module.title' : 'course.title';
    $selector = "[data-editor-field][data-field-name=\"{$fieldName}\"] input";
    $before = $fixture->root->fresh()->title;
    $changed = "{$fixture->token} Explicit Save";

    $page->fill($selector, $changed)
        ->keys($selector, 'Tab')
        ->wait(0.8)
        ->assertDataAttribute('[data-editor-status]', 'editor-state', 'dirty');

    expect($fixture->root->fresh()->title)->toBe($before);

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->assertDataAttribute('[data-editor-status]', 'editor-state', 'saved');

    expect($fixture->root->fresh()->title)->toBe($changed);

    $page->refresh()->assertValue($selector, $changed);
})->with('course editor contexts');

test('shared course code uses native input state stays staged warns on navigation and survives Save reload', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'shared course');
    $selector = '[data-editor-field][data-field-name="course.code"] input';
    $before = $fixture->root->fresh()->code;
    $changed = 'browser-code-'.substr($fixture->token, -8);

    $page->fill($selector, $changed)
        ->assertValue($selector, $changed)
        ->assertDataAttribute('[data-editor-status]', 'editor-state', 'dirty')
        ->assertScript("!document.querySelector('[data-editor-save]').disabled")
        ->assertScript(<<<'JS'
            (() => {
                const event = new Event('beforeunload', { cancelable: true });
                window.dispatchEvent(event);
                return event.defaultPrevented;
            })()
        JS);

    expect($fixture->root->fresh()->code)->toBe($before);

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertValue($selector, strtoupper($changed));
    expect($fixture->root->fresh()->code)->toBe(strtoupper($changed));
});

test('single and multiple correctness use native change stay staged warn and survive Save reload', function (string $contextName, string $type): void {
    $context = EditorContextCase::all()[$contextName];
    $fixture = EditorFixture::create($context);
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->orderBy('position')->firstOrFail();
    if ($type === 'multiple_choice') {
        $question->update(['type' => $type]);
    }
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }
    $page = visit($fixture->url());
    $questionSelector = "[data-editor-record][data-record-key=\"question:{$question->id}\"]";
    $second = "{$questionSelector} input[aria-label=\"Mark answer 2 as correct\"]";
    $first = "{$questionSelector} input[aria-label=\"Mark answer 1 as correct\"]";
    $secondOption = $question->options->sortBy('position')->values()[1];

    $page->check($second)
        ->assertChecked($second)
        ->assertDataAttribute('[data-editor-status]', 'editor-state', 'dirty')
        ->assertScript("!document.querySelector('[data-editor-save]').disabled")
        ->assertScript(<<<'JS'
            (() => {
                const event = new Event('beforeunload', { cancelable: true });
                window.dispatchEvent(event);
                return event.defaultPrevented;
            })()
        JS);

    if ($type === 'single_choice') {
        $page->assertScript('!document.querySelector('.json_encode($first, JSON_THROW_ON_ERROR).').checked');
    } else {
        $page->assertChecked($first);
    }

    expect($secondOption->fresh()->is_correct)->toBeFalse();

    if ($type === 'single_choice') {
        $page->click("{$questionSelector} > div:first-child > div:last-child [data-editor-structure-action=\"move-down\"]")
            ->wait(0.5)
            ->assertChecked($second)
            ->assertScript('!document.querySelector('.json_encode($first, JSON_THROW_ON_ERROR).').checked');
    }

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertChecked($second);
    expect($secondOption->fresh()->is_correct)->toBeTrue();
    if ($type === 'single_choice') {
        expect($question->options()->orderBy('position')->firstOrFail()->is_correct)->toBeFalse();
    } else {
        expect($question->options()->where('is_correct', true)->count())->toBe(2);
    }
})->with('course editor contexts')->with(['single_choice', 'multiple_choice']);

test('a lost immediate response hard-locks canonical actions without replay and retains the local value', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $recordId = $fixture->recordIds[0];
    $fieldName = $fixture->context->name === 'shared-module' ? 'module.title' : 'course.title';
    $field = "[data-editor-field][data-field-name=\"{$fieldName}\"] input";
    $action = '[data-record-key][data-editor-record] [data-editor-action-detail="add-question"]';
    $local = $fixture->token.' retained after lost operation';
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $browserContext = $page->page()->context();
    $contextGuid = (new ReflectionProperty($browserContext, 'guid'))->getValue($browserContext);

    try {
        $page->fill($field, $local);
        iterator_to_array(Client::instance()->execute($contextGuid, 'setOffline', ['offline' => true]));
        $page->click($action)
            ->wait(0.8)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'unknown-outcome')
            ->assertValue($field, $local)
            ->assertVisible('[data-editor-operational-guidance][data-guidance-severity="danger"]')
            ->assertScript("document.querySelector('[data-editor-save]').disabled")
            ->assertScript("[...document.querySelectorAll('[data-editor-structure-action], [data-editor-media-action]')].every(control => control.disabled || control.getAttribute('aria-disabled') === 'true')");
        expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($before);
        iterator_to_array(Client::instance()->execute($contextGuid, 'setOffline', ['offline' => false]));
        $page->wait(1.2)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'unknown-outcome')
            ->assertValue($field, $local);
        expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($before);
    } finally {
        iterator_to_array(Client::instance()->execute($contextGuid, 'setOffline', ['offline' => false]));
        $page->script(<<<'JS'
            () => {
                const root = document.querySelector('[data-editor-root]');
                if (!root) return;
                const state = Alpine.$data(root);
                state.dirty = false;
                state.state = 'clean';
                state.destroy();
            }
        JS);
    }
})->with('course editor contexts');

test('stale and revoked immediate operations enter their cause-specific hard locks with zero writes', function (string $contextName, string $failure): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $recordId = $fixture->recordIds[0];
    $fieldName = $fixture->context->name === 'shared-module' ? 'module.title' : 'course.title';
    $field = "[data-editor-field][data-field-name=\"{$fieldName}\"] input";
    $recordKey = $fixture->context->name === 'company-course' ? 'lesson:'.$recordId : 'module-version:'.$recordId;
    $action = "[data-editor-record][data-record-key=\"{$recordKey}\"] [data-editor-action-detail=\"add-question\"]";
    $local = $fixture->token.' retained after '.$failure;
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $page->fill($field, $local);
    if ($failure === 'conflict') {
        Lesson::query()->findOrFail($recordId)->update(['title' => $fixture->token.' external stale']);
    } elseif ($fixture->user !== null) {
        $fixture->user->roles()->detach();
    } else {
        Account::query()->findOrFail($fixture->session['platform_account_id'])->update(['is_platform_admin' => false]);
    }

    try {
        $page->click($action)
            ->wait(0.8)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', $failure)
            ->assertValue($field, $local)
            ->assertScript("document.querySelector('[data-editor-save]').disabled")
            ->assertScript("[...document.querySelectorAll('[data-editor-structure-action], [data-editor-media-action]')].every(control => control.disabled || control.getAttribute('aria-disabled') === 'true')");
        expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($before);
    } finally {
        $page->script("() => { const root = document.querySelector('[data-editor-root]'); if (!root) return; const state = Alpine.\$data(root); state.dirty = false; state.state = 'clean'; state.destroy(); }");
    }
})->with('course editor contexts')->with(['conflict', 'permission-lost']);

test('concurrent conflict retains the newer local value and preserves the remote write', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $fieldName = $fixture->context->name === 'shared-module' ? 'module.title' : 'course.title';
    $selector = "[data-editor-field][data-field-name=\"{$fieldName}\"] input";
    $local = $fixture->token.' local conflict value';
    $remote = $fixture->token.' remote conflict value';

    try {
        $page->fill($selector, $local);
        $remoteTarget = $fixture->context->name === 'shared-module'
            ? ModuleVersion::query()->findOrFail($fixture->recordIds[0])
            : $fixture->root;
        $remoteTarget->update(['title' => $remote]);

        $page->click('[data-editor-save]')
            ->wait(0.5)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'conflict')
            ->assertValue($selector, $local)
            ->assertVisible('[data-editor-error][data-error-kind="conflict"]')
            ->click('[data-editor-error][data-error-kind="conflict"] [data-editor-conflict-action="reload"]')
            ->assertVisible('[data-editor-conflict-confirmation]')
            ->assertValue($selector, $local)
            ->click('[data-editor-conflict-action="confirm-reload"]')
            ->waitForText('All changes saved')
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'clean')
            ->assertValue($selector, $remote);

        expect($remoteTarget->fresh()->title)->toBe($remote);
    } finally {
        $page->script("() => { const state = Alpine.\$data(document.querySelector('[data-editor-root]')); state.dirty = false; state.state = 'clean'; state.destroy(); }");
    }
})->with('course editor contexts');

test('rendered validation retains the invalid value and recovers through Save without refresh', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'shared course');
    $selector = '[data-editor-field][data-field-name="course.title"] input';
    $before = $fixture->root->fresh()->title;
    $recovered = $fixture->token.' recovered validation value';

    try {
        $page->fill($selector, ' ')
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'dirty')
            ->assertScript("!document.querySelector('[data-editor-save]').disabled")
            ->click('[data-editor-save]')
            ->wait(0.5)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'validation-error')
            ->assertVisible('[data-editor-error][data-error-kind="validation-error"]')
            ->assertValue($selector, ' ');
        $invalid = $page->script(<<<'JS'
            () => {
                const control = document.querySelector('[data-editor-field][data-field-name="course.title"] input');
                const described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                return {
                    focused: document.activeElement === control,
                    invalid: control.getAttribute('aria-invalid') === 'true',
                    described,
                    visibleErrors: described.filter(id => {
                        const node = document.getElementById(id);
                        return node && node.getBoundingClientRect().height > 0 && node.textContent.trim() !== '';
                    }),
                };
            }
        JS);
        expect($invalid['focused'])->toBeTrue()
            ->and($invalid['invalid'])->toBeTrue()
            ->and($invalid['described'])->not->toBeEmpty()
            ->and($invalid['visibleErrors'])->toBe($invalid['described']);
        expect($fixture->root->fresh()->title)->toBe($before);

        $page->fill($selector, $recovered)
            ->click('[data-editor-save]')
            ->waitForText('All changes saved')
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'saved')
            ->assertValue($selector, $recovered);
        expect($fixture->root->fresh()->title)->toBe($recovered);
    } finally {
        $page->script("() => { const root = document.querySelector('[data-editor-root]'); if (!root) return; const state = Alpine.\$data(root); state.dirty = false; state.state = 'clean'; state.destroy(); }");
    }
});

test('permission loss locks every mutation control while the local value remains copyable', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'company course');
    $selector = '[data-editor-field][data-field-name="course.title"] input';
    $local = $fixture->token.' local copy after revocation';
    $before = $fixture->root->fresh()->title;

    $page->fill($selector, $local)->assertValue($selector, $local);
    $fixture->user->roles()->detach();

    try {
        $page->click('[data-editor-save]')
            ->wait(0.5)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'permission-lost')
            ->assertDataAttribute('[data-editor-root]', 'editor-actions-locked', 'true')
            ->assertValue($selector, $local)
            ->assertScript(<<<'JS'
                (() => {
                    const controls = [...document.querySelectorAll('[data-editor-structure-action], [data-editor-media-action], [data-editor-publish-action]')];
                    return controls.length > 0 && controls.every(control => control.disabled || control.getAttribute('aria-disabled') === 'true');
                })()
            JS);
        expect($fixture->root->fresh()->title)->toBe($before);
    } finally {
        $page->script("() => { const root = document.querySelector('[data-editor-root]'); if (!root) return; const state = Alpine.\$data(root); state.dirty = false; state.state = 'clean'; state.destroy(); }");
    }
});

test('course preview stays on the saved draft and is guarded in every unsafe editor state', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        grantPermissions($fixture->user, [Permission::CoursesGeneratePreviewLink], 'Browser preview editor');
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }
    $page = visit($fixture->url());
    $selector = '[data-editor-field][data-field-name="course.title"] input';
    $local = $fixture->token.' staged before preview';
    $before = $fixture->root->fresh()->title;

    $page->assertPresent('[data-course-preview-panel][data-preview-source="saved-draft"]')
        ->assertSee('Unsaved changes are not included.')
        ->fill($selector, $local)
        ->assertValue($selector, $local)
        ->wait(0.2)
        ->assertVisible('[data-editor-preview-guard]')
        ->assertScript("document.querySelector('[data-editor-preview-action=generate]').disabled");
    expect($fixture->root->fresh()->title)->toBe($before);

    $states = $page->script(<<<'JS'
        async () => {
            const root = document.querySelector('[data-editor-root]');
            const editor = Alpine.$data(root);
            const action = document.querySelector('[data-editor-preview-action="generate"]');
            const result = {};
            for (const state of ['dirty', 'saving', 'validation-error', 'conflict', 'network-error']) {
                editor.state = state;
                await Alpine.nextTick();
                result[state] = action.disabled;
            }
            editor.state = 'dirty';
            return result;
        }
    JS);
    expect($states)->toBe([
        'dirty' => true,
        'saving' => true,
        'validation-error' => true,
        'conflict' => true,
        'network-error' => true,
    ]);

    $page->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->assertValue($selector, $local)
        ->assertScript("!document.querySelector('[data-editor-preview-action=generate]').disabled");
    expect($fixture->root->fresh()->title)->toBe($local);
})->with(['company course', 'shared course']);

test('dirty composition stays enabled while publication remains disabled with state-specific guidance', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'shared course');
    $sourceRoot = Module::factory()->shared()->create(['status' => 'active']);
    $source = ModuleVersion::factory()->published()->create(['module_id' => $sourceRoot, 'title' => $fixture->token.' eligible attach']);
    $selector = '[data-editor-field][data-field-name="course.title"] input';

    $page->fill('[wire\\:model="moduleSearch"]', $source->title)
        ->click('[wire\\:click="searchAvailableModules"]')
        ->wait(0.4)
        ->check('input[name="editor-module-picker"]')
        ->wait(0.3)
        ->fill($selector, $fixture->token.' staged guidance')
        ->wait(0.2);
    $guidance = $page->script(<<<'JS'
        () => {
            const inspect = control => {
                const ids = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                const descriptions = ids.map(id => document.getElementById(id)).filter(Boolean);
                return {
                    disabled: control.disabled,
                    ids,
                    visible: descriptions.some(node => node.getBoundingClientRect().height > 0),
                    text: descriptions.map(node => node.textContent.trim()).join(' '),
                };
            };
            return {
                composition: inspect(document.querySelector('[data-editor-structure-action="add"][data-editor-action-detail="composition-attach"]')),
                publication: inspect(document.querySelector('[data-editor-publish-action]')),
            };
        }
    JS);
    expect($guidance['composition']['disabled'])->toBeFalse()
        ->and($guidance['composition']['visible'])->toBeFalse()
        ->and($guidance['publication']['disabled'])->toBeTrue()
        ->and($guidance['publication']['ids'])->not->toBeEmpty()
        ->and($guidance['publication']['visible'])->toBeTrue()
        ->and($guidance['publication']['text'])->toContain('Save');
});

test('dirty and clean navigation warnings follow the rendered editor state', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $fieldName = $fixture->context->name === 'shared-module' ? 'module.title' : 'course.title';
    $selector = "[data-editor-field][data-field-name=\"{$fieldName}\"] input";

    $page->assertScript(<<<'JS'
        (() => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            return !event.defaultPrevented;
        })()
    JS)->fill($selector, $fixture->token.' navigation warning')
        ->assertScript(<<<'JS'
            (() => {
                const event = new Event('beforeunload', { cancelable: true });
                window.dispatchEvent(event);
                return event.defaultPrevented;
            })()
        JS);

    $navigation = $page->script(<<<'JS'
        () => {
            window.__editorConfirmCalls = [];
            window.confirm = message => { window.__editorConfirmCalls.push(message); return false; };
            const stay = new CustomEvent('livewire:navigate', { cancelable: true });
            window.dispatchEvent(stay);
            window.confirm = message => { window.__editorConfirmCalls.push(message); return true; };
            const leave = new CustomEvent('livewire:navigate', { cancelable: true });
            window.dispatchEvent(leave);
            const state = Alpine.$data(document.querySelector('[data-editor-root]'));
            state.dirty = false;
            state.state = 'clean';
            const clean = new CustomEvent('livewire:navigate', { cancelable: true });
            window.dispatchEvent(clean);
            state.dirty = true;
            state.state = 'saving';
            const pending = new CustomEvent('livewire:navigate', { cancelable: true });
            window.dispatchEvent(pending);
            const result = {
                stayPrevented: stay.defaultPrevented,
                leavePrevented: leave.defaultPrevented,
                cleanPrevented: clean.defaultPrevented,
                pendingPrevented: pending.defaultPrevented,
                confirmCalls: window.__editorConfirmCalls.length,
            };
            state.dirty = false;
            state.state = 'clean';
            state.destroy();
            return result;
        }
    JS);
    expect($navigation)->toBe([
        'stayPrevented' => true,
        'leavePrevented' => false,
        'cleanPrevented' => false,
        'pendingPrevented' => false,
        'confirmCalls' => 3,
    ]);
})->with('course editor contexts');

test('cancelling a controlled structural confirmation performs no destructive operation', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    if ($fixture->context->name === 'company-course') {
        $remove = '[data-editor-record][data-record-key="lesson:'.$fixture->recordIds[0].'"] > div:first-child > [data-editor-structure-action="remove"]';
        $dialog = '[data-editor-destructive-confirmation][wire\\:submit="performConfirmedDestructive"]';
    } elseif ($fixture->context->name === 'shared-course') {
        $remove = '[data-editor-record][data-record-key="module-version:'.$fixture->recordIds[0].'"] > div:first-child [data-editor-structure-action="remove"]';
        $dialog = '[data-editor-destructive-confirmation][wire\\:submit="removeConfirmedModule"]';
    } else {
        $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->orderBy('position')->firstOrFail();
        $remove = '[wire\\:click="confirmQuestionDestruction('.$fixture->recordIds[0].', '.$question->id.')"]';
        $dialog = '[data-editor-destructive-confirmation][wire\\:submit="performConfirmedDestructive"]';
    }

    $page->assertPresent($remove)
        ->click($remove)
        ->wait(0.2)
        ->assertVisible($dialog)
        ->click($dialog.' button[type="button"]')
        ->wait(0.2)
        ->assertScript('document.querySelector('.json_encode($dialog, JSON_THROW_ON_ERROR).').getBoundingClientRect().height === 0')
        ->assertNotPresent('[data-editor-operation]');
})->with('course editor contexts');

test('a confirmed destructive dialog cannot be dismissed while its exact target is pending', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'company course');
    $lessonId = $fixture->recordIds[0];
    $remove = "[data-editor-record][data-record-key=\"lesson:{$lessonId}\"] > div:first-child > [data-editor-structure-action=\"remove\"]";

    $page->wait(0.5)
        ->click($remove)
        ->wait(0.2)
        ->assertVisible("[data-editor-destructive-modal][data-target-key=\"lesson:{$lessonId}\"] [data-editor-destructive-confirmation]");
    $evidence = $page->script(<<<'JS'
        async () => {
            const modal = [...document.querySelectorAll('[data-editor-destructive-modal]')]
                .find(candidate => candidate.querySelector('[data-editor-destructive-confirmation]')?.getBoundingClientRect().height > 0);
            const form = modal.querySelector('[data-editor-destructive-confirmation]');
            const cancel = form.querySelector('[data-editor-destructive-cancel]');
            const submit = form.querySelector('[data-editor-destructive-submit]');
            form.addEventListener('submit', event => event.preventDefault(), { capture: true, once: true });
            submit.click();
            await Alpine.nextTick();

            const visible = () => form.getBoundingClientRect().height > 0;
            const pending = form.querySelector('[data-editor-destructive-pending]');
            const initial = {
                state: modal.dataset.destructiveState,
                busy: form.getAttribute('aria-busy'),
                cancelDisabled: cancel.disabled,
                submitDisabled: submit.disabled,
                guidanceVisible: pending.getBoundingClientRect().height > 0,
                guidanceText: pending.textContent.trim(),
                target: pending.dataset.targetKey,
                closeControls: modal.querySelectorAll('[data-flux-modal-close], button[aria-label="Close"]').length,
            };

            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
            const afterEscape = visible();
            cancel.click();
            const afterCancel = visible();
            document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, cancelable: true }));
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            await Alpine.nextTick();

            return { initial, afterEscape, afterCancel, afterOutside: visible() };
        }
    JS);

    expect($evidence['initial']['state'])->toBe('pending')
        ->and($evidence['initial']['busy'])->toBe('true')
        ->and($evidence['initial']['cancelDisabled'])->toBeTrue()
        ->and($evidence['initial']['submitDisabled'])->toBeTrue()
        ->and($evidence['initial']['guidanceVisible'])->toBeTrue()
        ->and($evidence['initial']['guidanceText'])->toContain('in progress')
        ->and($evidence['initial']['target'])->toBe("lesson:{$lessonId}")
        ->and($evidence['initial']['closeControls'])->toBe(0)
        ->and($evidence['afterEscape'])->toBeTrue()
        ->and($evidence['afterCancel'])->toBeTrue()
        ->and($evidence['afterOutside'])->toBeTrue();
});

test('accepting an immediate structural confirmation crosses Livewire and persists exactly once', function (): void {
    [$fixture, $page] = openUnifiedEditor($this, 'company course');
    $before = count($fixture->recordIds);
    $removedId = $fixture->recordIds[0];
    $selector = "[data-editor-record][data-record-key=\"lesson:{$removedId}\"] > div:first-child > [data-editor-structure-action=\"remove\"]";

    $page->script(<<<'JS'
        () => {
            window.__operationStates = [];
            window.addEventListener('editor-operation-finished', event => window.__operationStates.push(event.detail.state));
        }
    JS);
    $page->click($selector)
        ->wait(0.2)
        ->assertVisible('[data-editor-destructive-confirmation][wire\\:submit="performConfirmedDestructive"]')
        ->click('[data-editor-destructive-confirmation][wire\\:submit="performConfirmedDestructive"] button[type="submit"]')
        ->wait(0.5)
        ->assertScript("window.__operationStates.includes('succeeded')")
        ->assertNotPresent("[data-editor-record][data-record-key=\"lesson:{$removedId}\"]");

    expect($fixture->root->versions()->where('status', 'draft')->sole()->lessons()->count())->toBe($before - 1);
});

test('reorder keeps stable question identity focus and authored values through Save and reload', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $questions = Question::query()->where('lesson_id', $fixture->recordIds[0])->orderBy('position')->with('options')->get();
    $moved = $questions[1];
    $questionKey = 'question:'.$moved->id;
    $optionKey = 'option:'.$moved->options->first()->id;
    $question = "[data-editor-record][data-record-key=\"{$questionKey}\"]";
    $prompt = "{$question} [data-editor-field][data-field-name=\"question.prompt\"] input";
    $answer = "[data-editor-record][data-record-key=\"{$optionKey}\"] [data-editor-field][data-field-name=\"option.text\"] input";
    $changedPrompt = $fixture->token.' Moved prompt';
    $changedAnswer = $fixture->token.' Moved answer';

    $page->click("{$question} > div:first-child > div:last-child [data-editor-structure-action=\"move-up\"]")
        ->assertScript("[...document.querySelectorAll('[data-record-type=question]')][0].dataset.recordKey === '{$questionKey}'")
        ->wait(0.2);

    expect($page->script("() => document.activeElement.closest('[data-record-key]')?.dataset.recordKey || document.activeElement.outerHTML"))
        ->toBe($questionKey);

    $page->fill($prompt, $changedPrompt)
        ->fill($answer, $changedAnswer)
        ->assertValue($prompt, $changedPrompt)
        ->assertValue($answer, $changedAnswer)
        ->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertScript("[...document.querySelectorAll('[data-record-type=question]')][0].dataset.recordKey === '{$questionKey}'")
        ->assertValue($prompt, $changedPrompt)
        ->assertValue($answer, $changedAnswer);

    expect($moved->fresh()->position)->toBe(1)
        ->and($moved->fresh()->prompt)->toBe($changedPrompt)
        ->and($moved->options()->orderBy('position')->firstOrFail()->text)->toBe($changedAnswer)
        ->and($questions[0]->fresh()->position)->toBe(2);
})->with('course editor contexts');

test('option reorder preserves DOM identity focus value correctness and persisted order through reload', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->orderBy('position')->firstOrFail();
    $moved = $question->options->sortBy('position')->values()[1];
    $optionKey = 'option:'.$moved->id;
    $option = "[data-editor-record][data-record-key=\"{$optionKey}\"]";
    $answer = "{$option} [data-editor-field][data-field-name=\"option.text\"] input";
    $changed = $fixture->token.' moved option answer';

    $page->click("{$option} [data-editor-structure-action=\"move-up\"]")
        ->wait(0.2)
        ->assertScript("[...document.querySelectorAll('[data-record-type=option]')][0].dataset.recordKey === '{$optionKey}'");

    expect($page->script("() => document.activeElement.closest('[data-record-key]')?.dataset.recordKey"))->toBe($optionKey);

    $page->fill($answer, $changed)
        ->assertValue($answer, $changed)
        ->click('[data-editor-save]')
        ->waitForText('All changes saved')
        ->refresh()
        ->assertScript("[...document.querySelectorAll('[data-record-type=option]')][0].dataset.recordKey === '{$optionKey}'")
        ->assertValue($answer, $changed);

    expect($moved->fresh()->position)->toBe(1)
        ->and($moved->fresh()->text)->toBe($changed)
        ->and($question->options()->orderBy('position')->pluck('id')->first())->toBe($moved->id);
})->with('course editor contexts');

test('top-level reorder preserves identity focus value order and morph state through a fresh page reload', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $recordSelector = 'section[aria-labelledby="editor-content-heading"] > article[data-editor-record]';
    $records = $page->script("() => [...document.querySelectorAll('{$recordSelector}')].map(node => node.dataset.recordKey)");
    $movedKey = $records[1];
    $record = "[data-editor-record][data-record-key=\"{$movedKey}\"]";
    $title = "{$record} [data-editor-field][data-field-name=\"module.title\"] input";
    $changed = $fixture->token.' moved top-level record';

    try {
        $page->click("{$record} > div:first-child > div[aria-label] [data-editor-structure-action=\"move-up\"]")
            ->wait(0.2);
        $page->assertScript("[...document.querySelectorAll('{$recordSelector}')][0].dataset.recordKey === '{$movedKey}'");
        expect($page->script("() => document.activeElement.closest('[data-record-key]')?.dataset.recordKey"))->toBe($movedKey);

        $page->click("{$record} [data-editor-expand]")->wait(0.2);

        $page->fill($title, $changed)
            ->assertValue($title, $changed)
            ->click('[data-editor-save]')
            ->wait(0.5)
            ->assertDataAttribute('[data-editor-status]', 'editor-state', 'saved');
        $page->assertScript("[...document.querySelectorAll('{$recordSelector}')][0].dataset.recordKey === '{$movedKey}'");

        $page->script("() => { const state = Alpine.\$data(document.querySelector('[data-editor-root]')); state.dirty = false; state.state = 'clean'; state.destroy(); }");
        $reloaded = visit($fixture->url());
        $reloaded->assertScript("[...document.querySelectorAll('{$recordSelector}')][0].dataset.recordKey === '{$movedKey}'")
            ->assertValue($title, $changed);
    } finally {
        $page->script("() => { const root = document.querySelector('[data-editor-root]'); if (!root) return; const state = Alpine.\$data(root); state.dirty = false; state.state = 'clean'; state.destroy(); }");
    }
})->with(['company course', 'shared course']);

test('mobile navigation opens and retains the authorized route set', function (string $contextName): void {
    [$fixture, $page] = openUnifiedEditor($this, $contextName);
    $page->resize(320, 800)->wait(0.2);

    if ($fixture->context->name === 'company-course') {
        $page->click('header label[for="mobile-navigation"]')
            ->wait(0.3)
            ->assertScript("document.querySelector('#mobile-navigation').checked")
            ->assertScript("document.querySelector('aside').getBoundingClientRect().left >= -1")
            ->assertScript("[...document.querySelectorAll('aside nav a')].some(a => new URL(a.href).pathname === ".json_encode(route('courses.index', [], false)).')');

        return;
    }

    $page->click('[data-platform-mobile-menu] summary')
        ->assertScript("document.querySelector('[data-platform-mobile-menu]').open")
        ->assertVisible('[data-platform-mobile-menu] div')
        ->assertScript("[...document.querySelectorAll('[data-platform-mobile-menu] a')].some(a => new URL(a.href).pathname === ".json_encode(route('platform.shared-courses.index', [], false)).')')
        ->assertScript("[...document.querySelectorAll('[data-platform-mobile-menu] a')].some(a => new URL(a.href).pathname === ".json_encode(route('platform.shared-modules.index', [], false)).')');
})->with('course editor contexts');
