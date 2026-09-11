<?php

use App\Enums\Permission;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use Tests\Support\CourseEditor\BrowserEnvironment;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

beforeEach(fn () => BrowserEnvironment::assertReady());

test('question and answer fields use the accepted width at desktop and mobile', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);

    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }

    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url());
    $page->script(<<<'JS'
        () => document.querySelectorAll('[data-editor-expand][aria-expanded="false"]')
            .forEach(control => control.click())
    JS);

    foreach ([[1440, 900, 0.70], [320, 700, 0.90]] as [$width, $height, $minimumRatio]) {
        $page->resize($width, $height)->wait(0.2);
        $measurements = $page->script(<<<'JS'
            () => [...document.querySelectorAll(
                '[data-editor-field][data-field-name="question.prompt"], [data-editor-field][data-field-name="option.text"]'
            )].map(wrapper => {
                const row = wrapper.parentElement;
                const control = wrapper.querySelector('input, textarea');
                const wrapperWidth = wrapper.getBoundingClientRect().width;
                return {
                    wrapperRatio: wrapperWidth / row.getBoundingClientRect().width,
                    controlRatio: control.getBoundingClientRect().width / wrapperWidth,
                };
            })
        JS);

        expect($measurements)->not->toBeEmpty();
        foreach ($measurements as $measurement) {
            expect($measurement['wrapperRatio'])->toBeGreaterThanOrEqual($minimumRatio)
                ->and($measurement['controlRatio'])->toBeGreaterThanOrEqual(0.98);
        }

        $overflow = $page->script(<<<'JS'
            () => [...document.querySelectorAll('body *')]
                .filter(element => element.getBoundingClientRect().right > window.innerWidth + 1)
                .slice(0, 8)
                .map(element => `${element.tagName.toLowerCase()}#${element.id}.${element.className}`)
        JS);
        if ($overflow !== []) {
            throw new RuntimeException("{$contextName} {$width}px overflow: ".json_encode($overflow, JSON_THROW_ON_ERROR));
        }
        expect($overflow, 'Overflowing elements: '.json_encode($overflow))->toBeEmpty();
    }
})->with('course editor contexts');

test('numeric training fields use direct Flux labels with hints and associated validation', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }
    $page = visit($fixture->url());

    $fields = $page->script(<<<'JS'
        () => [...document.querySelectorAll([
            '[data-editor-field][data-field-name="module.watch-threshold"]',
            '[data-editor-field][data-field-name="module.passing-score"]',
            '[data-editor-field][data-field-name="question.attempts"]',
        ].join(','))].map(wrapper => {
            const field = wrapper.matches('[data-flux-field]')
                ? wrapper
                : wrapper.querySelector(':scope > [data-flux-field]');
            const label = field?.querySelector(':scope > [data-flux-label]');
            const input = field?.querySelector('input[type="number"]');
            const ids = (input?.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            return {
                name: wrapper.dataset.fieldName,
                hasField: Boolean(field),
                hasDirectLabel: Boolean(label),
                labelText: label?.textContent.trim() || '',
                hint: label?.querySelector('button[type="button"][aria-label]')?.getAttribute('aria-label') || '',
                inputType: input?.getAttribute('type') || '',
                describedBy: ids,
                descriptionsExist: ids.length > 0 && ids.every(id => document.getElementById(id)),
            };
        })
    JS);

    expect($fields)->not->toBeEmpty();
    foreach ($fields as $field) {
        expect($field['hasField'], $field['name'])->toBeTrue()
            ->and($field['hasDirectLabel'], $field['name'])->toBeTrue()
            ->and($field['labelText'], $field['name'])->not->toBeEmpty()
            ->and($field['hint'], $field['name'])->not->toBeEmpty()
            ->and($field['inputType'], $field['name'])->toBe('number')
            ->and($field['describedBy'], $field['name'])->not->toBeEmpty()
            ->and($field['descriptionsExist'], $field['name'])->toBeTrue();
    }
})->with('course editor contexts');

test('mobile editor keeps the accepted shell and control widths with accessible field names', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url())->resize(320, 800)->wait(0.2);
    $page->script(<<<'JS'
        () => document.querySelectorAll('[data-editor-expand][aria-expanded="false"]')
            .forEach(control => control.click())
    JS);

    $metrics = $page->script(<<<'JS'
        () => {
            const root = document.querySelector('[data-editor-root]');
            const fields = [...document.querySelectorAll(
                '[data-editor-field][data-field-name="question.prompt"] input, [data-editor-field][data-field-name="option.text"] input'
            )];
            const unnamed = [...document.querySelectorAll('[data-editor-field] input, [data-editor-field] textarea, [data-editor-field] select')]
                .filter(control => {
                    const labels = control.labels ? [...control.labels] : [];
                    return labels.length === 0 && !control.getAttribute('aria-label') && !control.getAttribute('aria-labelledby');
                })
                .map(control => control.outerHTML.slice(0, 180));
            const duplicateIds = [...document.querySelectorAll('[data-editor-root] [id]')]
                .map(element => element.id)
                .filter((id, index, ids) => ids.indexOf(id) !== index);
            return {
                rootWidth: root.getBoundingClientRect().width,
                fieldWidths: fields.map(field => field.getBoundingClientRect().width),
                wrapperWidths: fields.map(field => field.closest('[data-editor-field]').getBoundingClientRect().width),
                unnamed: unnamed.filter(markup => !markup.includes('data-editor=')),
                duplicateIds,
            };
        }
    JS);

    expect($metrics['rootWidth'])->toBeIn([280, 288])
        ->and($metrics['fieldWidths'])->not->toBeEmpty()
        ->and(min($metrics['wrapperWidths']))->toBeGreaterThanOrEqual(200)
        ->and(min($metrics['fieldWidths']))->toBeGreaterThanOrEqual(196)
        ->and($metrics['unnamed'])->toBe([])
        ->and($metrics['duplicateIds'])->toBe([]);

    $page->keys('input[name="records.0.questions.0.prompt"]', 'Tab');
    expect($page->script('() => document.activeElement !== document.body && document.activeElement !== null'))->toBeTrue();
})->with('course editor contexts');

test('mobile actions stack without overlap and focus remains visibly discoverable on the accepted editor hero', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url())->resize(320, 800)->wait(0.2);
    $prompt = 'input[name="records.0.questions.0.prompt"]';
    $page->click($prompt);

    $metrics = $page->script(<<<'JS'
        () => {
            const prompt = document.querySelector('input[name="records.0.questions.0.prompt"]');
            const style = getComputedStyle(prompt);
            const saveRegion = document.querySelector('[aria-label="Draft save actions"]');
            const saveButtons = [...saveRegion.querySelectorAll('button')].map(button => button.getBoundingClientRect());
            const question = document.querySelector('[data-record-type="question"]');
            const questionActions = [...question.querySelectorAll(':scope > div:first-child [data-editor-structure-action]')]
                .map(button => button.getBoundingClientRect());
            const separated = rectangles => rectangles.every((rect, index) => rectangles.slice(index + 1).every(other =>
                rect.right <= other.left || other.right <= rect.left || rect.bottom <= other.top || other.bottom <= rect.top
            ));
            return {
                focusVisible: style.outlineStyle !== 'none' || style.boxShadow !== 'none',
                saveSeparated: separated(saveButtons),
                saveVerticalStack: saveButtons.length === 2
                    && saveButtons[1].top >= saveButtons[0].bottom - 1
                    && Math.abs(saveButtons[0].left - saveButtons[1].left) <= 1
                    && Math.abs(saveButtons[0].width - saveButtons[1].width) <= 1,
                questionActionsSeparated: separated(questionActions),
                saveWidths: saveButtons.map(rect => rect.width),
                acceptedHero: Boolean(document.querySelector('[data-editor-root] > .admin-hero')),
                legacyWideHero: Boolean(document.querySelector('[data-editor-root] > [data-course-hero]')),
            };
        }
    JS);

    expect($metrics['focusVisible'])->toBeTrue()
        ->and($metrics['saveSeparated'])->toBeTrue()
        ->and($metrics['saveVerticalStack'])->toBeTrue()
        ->and($metrics['questionActionsSeparated'])->toBeTrue()
        ->and(min($metrics['saveWidths']))->toBeGreaterThanOrEqual(280)
        ->and($metrics['acceptedHero'])->toBeTrue()
        ->and($metrics['legacyWideHero'])->toBeFalse();
})->with('course editor contexts');

test('the fixed save bar follows each shell canvas and gutter at desktop and mobile', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url());
    foreach ([[1440, 900], [320, 800]] as [$width, $height]) {
        $page->resize($width, $height)->wait(0.2);
        $alignment = $page->script(<<<'JS'
            () => {
                const canvas = document.querySelector('[data-editor-root]').getBoundingClientRect();
                const saveElement = document.querySelector('[aria-label="Draft save actions"] > div');
                const saveContent = saveElement.getBoundingClientRect();
                const saveStyle = getComputedStyle(saveElement);
                return {
                    leftDelta: Math.abs(canvas.left - (saveContent.left + parseFloat(saveStyle.paddingLeft))),
                    rightDelta: Math.abs(canvas.right - (saveContent.right - parseFloat(saveStyle.paddingRight))),
                };
            }
        JS);
        expect($alignment['leftDelta'], "{$contextName} {$width}px left gutter")
            ->toBeLessThanOrEqual(1.5)
            ->and($alignment['rightDelta'], "{$contextName} {$width}px right gutter")
            ->toBeLessThanOrEqual(1.5);
    }
})->with('course editor contexts');

test('every visible preview control uses the design-system focus ring', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        grantPermissions($fixture->user, [Permission::CoursesGeneratePreviewLink], 'Preview focus ring');
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url());
    $rings = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-editor-preview-action]')]
            .filter(control => control.getBoundingClientRect().height > 0)
            .map(control => {
                control.focus();
                const style = getComputedStyle(control);
                return {
                    action: control.dataset.editorPreviewAction,
                    outline: style.outlineColor,
                    outlineStyle: style.outlineStyle,
                    shadow: style.boxShadow,
                };
            })
    JS);
    expect($rings)->not->toBeEmpty();
    foreach ($rings as $ring) {
        expect(
            $ring['shadow'] !== 'none' && str_contains($ring['shadow'], 'rgb(62, 139, 163)')
                || $ring['outlineStyle'] !== 'none' && $ring['outline'] === 'rgb(62, 139, 163)',
            $ring['action'],
        )->toBeTrue();
    }
})->with([
    'company course' => 'company course',
    'shared course' => 'shared course',
]);

test('every visible native editor action and authored control uses the design-system focus token', function (string $contextName): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $page = visit($fixture->url());
    $evidence = $page->script(<<<'JS'
        () => {
            const root = document.querySelector('[data-editor-root]');
            const expected = 'rgb(62, 139, 163)';
            const controls = [...root.querySelectorAll(`
                [data-editor-structure-action],
                [data-editor-media-action],
                [data-editor-preview-action],
                [data-editor-save],
                [data-editor-field] input,
                [data-editor-field] textarea,
                [data-editor-field] select,
                [data-editor-field] [contenteditable="true"]
            `)].filter(control => !control.disabled && control.getBoundingClientRect().height > 0);
            const missing = [];
            for (const control of controls) {
                control.focus();
                const style = getComputedStyle(control);
                const ring = style.outlineColor === expected && style.outlineStyle !== 'none'
                    || style.boxShadow.includes(expected);
                if (!ring) missing.push(control.outerHTML.slice(0, 220));
            }
            return {
                token: getComputedStyle(root).getPropertyValue('--ds-focus-ring').trim(),
                count: controls.length,
                missing,
            };
        }
    JS);

    expect($evidence['token'])->toBe('#3e8ba3')
        ->and($evidence['count'])->toBeGreaterThan(10)
        ->and($evidence['missing'])->toBe([]);
})->with('course editor contexts');

test('inline module-removal validation is visibly associated with its control', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::sharedCourse());
    $this->withSession($fixture->session);
    $page = visit($fixture->url());
    $recordId = $fixture->recordIds[0];
    $page->click("[data-editor-record][data-record-key=\"module-version:{$recordId}\"] > div:first-child [data-editor-structure-action=\"remove\"]")
        ->wait(0.2)
        ->script(<<<'JS'
            () => {
                const form = document.querySelector('form[wire\\:submit="removeConfirmedModule"]');
                form.noValidate = true;
                form.requestSubmit();
            }
        JS);
    $page->wait(0.4);

    $association = $page->script(<<<'JS'
        () => {
            const form = document.querySelector('form[wire\\:submit="removeConfirmedModule"]');
            const control = form.querySelector('textarea');
            const described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            return {
                invalid: control.getAttribute('aria-invalid') === 'true',
                described,
                visibleErrors: described.filter(id => {
                    const node = document.getElementById(id);
                    return node && node.getBoundingClientRect().height > 0 && node.textContent.trim() !== '';
                }),
            };
        }
    JS);
    expect($association['invalid'])->toBeTrue()
        ->and($association['described'])->not->toBeEmpty()
        ->and($association['visibleErrors'])->toBe($association['described']);
});

test('every destructive editor action uses the controlled accessible mobile dialog contract', function (string $kind): void {
    $fixture = EditorFixture::create($kind === 'reusable module' ? EditorContextCase::sharedCourse() : EditorContextCase::companyCourse());
    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    } else {
        $this->withSession($fixture->session);
    }

    if ($kind === 'reusable module') {
        $record = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
        $record->update(['title' => $fixture->token.' '.str_repeat('Long reusable module identity ', 6)]);
        $selector = '[data-editor-record][data-record-key="module-version:'.$record->id.'"] > div:first-child [wire\\:click^="confirmModuleRemoval"]';
        $target = $record->fresh()->title;
        $consequence = 'authored content remain available';
    } else {
        $lesson = Lesson::query()->findOrFail($fixture->recordIds[0]);
        $question = Question::query()->where('lesson_id', $lesson->id)->with('options')->orderBy('position')->firstOrFail();
        $option = $question->options->first();
        $lesson->update(['title' => $fixture->token.' '.str_repeat('Long lesson identity ', 7)]);
        $question->update(['prompt' => $fixture->token.' '.str_repeat('Long question identity ', 7)]);
        $option->update(['text' => $fixture->token.' '.str_repeat('Long answer identity ', 7)]);
        [$selector, $target, $consequence] = match ($kind) {
            'lesson' => ['[wire\\:click="confirmLessonRemoval('.$lesson->id.')"]', $lesson->fresh()->title, 'Provider media will not be deleted'],
            'question' => ['[wire\\:click="confirmQuestionDestruction('.$lesson->id.', '.$question->id.')"]', $question->fresh()->prompt, 'all of its answers'],
            'answer' => ['[wire\\:click="confirmAnswerDestruction('.$lesson->id.', '.$question->id.', '.$option->id.')"]', $option->fresh()->text, 'remaining answers are preserved'],
        };
    }

    $target = trim($target);
    $page = visit($fixture->url())->resize(320, 800);
    $page->click($selector)->wait(0.2);
    $metrics = $page->script(<<<'JS'
        () => {
            const form = [...document.querySelectorAll('[data-editor-destructive-confirmation][data-destructive-action="remove"]')]
                .find(candidate => candidate.getBoundingClientRect().height > 0);
            const title = form.querySelector('.break-words');
            const actions = [...form.querySelectorAll('button')];
            const region = actions[0].parentElement.getBoundingClientRect();
            const rectangles = actions.map(action => action.getBoundingClientRect());
            return {
                text: form.textContent,
                labels: actions.map(action => action.textContent.trim()),
                titleWraps: title.scrollWidth <= title.clientWidth + 1,
                fullWidth: rectangles.every(rect => rect.width >= region.width * 0.95),
                stacked: rectangles.length === 2 && rectangles[1].top >= rectangles[0].bottom - 1,
            };
        }
    JS);
    expect($metrics['text'])->toContain($target, $consequence)
        ->and($metrics['labels'][0])->toBe(__('Cancel'))
        ->and($metrics['titleWraps'])->toBeTrue()
        ->and($metrics['fullWidth'])->toBeTrue()
        ->and($metrics['stacked'])->toBeTrue();
})->with(['lesson', 'question', 'answer', 'reusable module']);

test('an actual unrelated platform page keeps the default hero contract', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::sharedCourse());
    $this->withSession($fixture->session);
    $page = visit(route('platform.dashboard', [], false))
        ->assertPresent('.admin-hero')
        ->assertNotPresent('.admin-hero[data-course-hero]')
        ->assertNotPresent('[data-editor-root]');

    $metrics = $page->script(<<<'JS'
        () => {
            const hero = document.querySelector('.admin-hero');
            const heading = hero.querySelector('h1');
            const description = hero.querySelector('p');
            return {
                heroWidth: hero.getBoundingClientRect().width,
                headingWidth: heading.getBoundingClientRect().width,
                descriptionMaxWidth: getComputedStyle(description).maxWidth,
            };
        }
    JS);
    expect($metrics['heroWidth'])->toBeGreaterThan(0)
        ->and($metrics['headingWidth'])->toBeLessThanOrEqual($metrics['heroWidth'])
        ->and($metrics['descriptionMaxWidth'])->not->toBe('none');
});
