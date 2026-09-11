import test from 'node:test';
import assert from 'node:assert/strict';

import { createCourseEditorState } from '../../resources/js/course-editor.js';
import { createLessonVideoUploadState } from '../../resources/js/video-upload.js';

if (typeof globalThis.MutationObserver === 'undefined') {
    globalThis.MutationObserver = class {
        observe() {}
        disconnect() {}
    };
}

function editor(initial = {}) {
    const wireCalls = [];
    const hooks = {};
    const state = createCourseEditorState(initial);
    state.$wire = {
        saveState: initial.state || 'clean',
        set(...args) { wireCalls.push(['set', ...args]); },
        saveDraft(...args) { wireCalls.push(['saveDraft', ...args]); },
        cancelDestructiveConfirmation(...args) { wireCalls.push(['cancelDestructiveConfirmation', ...args]); },
        $hook(name, callback) { hooks[name] = callback; },
    };
    state.$root = {
        style: { setProperty() {} },
        querySelectorAll() { return []; },
    };
    state.$nextTick = callback => callback();

    return { state, wireCalls, hooks };
}

function control({ fieldName = 'course.title', recordKey = 'course:1', value = 'Before', checked = false, type = 'text' } = {}) {
    const record = { dataset: { recordKey } };
    const field = {
        dataset: { fieldName },
        closest(selector) { return selector === '[data-editor-record]' ? record : null; },
    };

    return {
        value,
        checked,
        type,
        localName: 'input',
        closest(selector) { return selector === '[data-editor-field]' ? field : null; },
    };
}

function operationalControl({ kind = 'structure', family = null, action = 'add', detail = 'add-question', target = 'lesson:7', targetLabel = null, label = 'Add question', wireClick = 'addQuestion(7)' } = {}) {
    const actionAttribute = kind === 'structure' ? 'data-editor-structure-action' : 'data-editor-media-action';
    const attributes = new Map([
        [actionAttribute, action],
        ['data-editor-action-detail', detail],
        ['data-editor-target-key', target],
        ['aria-label', label],
        ['wire:click', wireClick],
    ]);
    if (family) attributes.set('data-editor-media-family', family);
    if (targetLabel) attributes.set('data-editor-target-label', targetLabel);
    const record = { dataset: { recordKey: target } };
    const node = {
        disabled: false,
        type: 'submit',
        innerText: label,
        textContent: label,
        dataset: {
            [kind === 'structure' ? 'editorStructureAction' : 'editorMediaAction']: action,
            editorActionDetail: detail,
            editorTargetKey: target,
            ...(family ? { editorMediaFamily: family } : {}),
            ...(targetLabel ? { editorTargetLabel: targetLabel } : {}),
        },
        hasAttribute(name) { return attributes.has(name); },
        getAttribute(name) { return attributes.get(name) ?? null; },
        setAttribute(name, value) {
            attributes.set(name, String(value));
            if (name === 'data-editor-operation-state') this.dataset.editorOperationState = String(value);
        },
        removeAttribute(name) {
            attributes.delete(name);
            if (name === 'data-editor-operation-state') delete this.dataset.editorOperationState;
        },
        closest(selector) {
            if (selector.includes('[data-editor-structure-action]') || selector.includes('[data-editor-media-action]')) return node;
            if (selector === '[data-editor-record], [data-record-key]') return record;
            return null;
        },
    };

    return node;
}

test('an unchanged blur starts from the rendered value and does not create false dirty state', () => {
    const identity = 'course:1/course.title';
    const { state, wireCalls } = editor({ observedValues: { [identity]: JSON.stringify('Before') } });

    state.markDirty({ target: control() });

    assert.equal(state.localGeneration, 0);
    assert.equal(state.dirty, false);
    assert.equal(state.state, 'clean');
    assert.deepEqual(wireCalls, []);
});

test('a changed authored value becomes dirty once and writes no persistence request', () => {
    const identity = 'course:1/course.title';
    const { state, wireCalls } = editor({ observedValues: { [identity]: JSON.stringify('Before') } });
    const input = control({ value: 'After' });

    state.markDirty({ target: input });
    state.markDirty({ target: input });

    assert.equal(state.localGeneration, 1);
    assert.equal(state.dirty, true);
    assert.equal(state.state, 'dirty');
    assert.deepEqual(wireCalls, [
        ['set', 'localGeneration', 1, false],
        ['set', 'editorDirty', true, false],
    ]);
});

test('checkbox input and change events count one value transition', () => {
    const identity = 'lesson:7/record.is_required';
    const { state } = editor({ observedValues: { [identity]: 'false' } });
    const input = control({ fieldName: 'record.is_required', recordKey: 'lesson:7', type: 'checkbox', checked: true });

    state.markDirty({ target: input });
    state.markDirty({ target: input });
    assert.equal(state.localGeneration, 1);

    input.checked = false;
    state.markDirty({ target: input });
    assert.equal(state.localGeneration, 2);
});

test('stable record identities keep distinct values after reorder and DOM replacement', () => {
    const { state } = editor({ observedValues: {
        'question:11/question.prompt': JSON.stringify('Alpha'),
        'question:22/question.prompt': JSON.stringify('Bravo'),
    } });

    state.markDirty({ target: control({ fieldName: 'question.prompt', recordKey: 'question:22', value: 'Bravo' }) });
    state.markDirty({ target: control({ fieldName: 'question.prompt', recordKey: 'question:11', value: 'Alpha edited' }) });
    state.markDirty({ target: control({ fieldName: 'question.prompt', recordKey: 'question:22', value: 'Bravo edited' }) });

    assert.equal(state.localGeneration, 2);
    assert.equal(state.observedValues['question:11/question.prompt'], JSON.stringify('Alpha edited'));
    assert.equal(state.observedValues['question:22/question.prompt'], JSON.stringify('Bravo edited'));
});

test('save acknowledgement clears only the generation it actually saved', () => {
    const { state } = editor({ dirty: true, state: 'dirty', localGeneration: 4 });

    assert.equal(state.beginSave(), true);
    assert.equal(state.savingGeneration, 4);
    assert.equal(state.state, 'saving');

    state.localGeneration = 5;
    state.finishSave({ state: 'saved', generation: 4 });
    assert.equal(state.state, 'dirty');
    assert.equal(state.dirty, true);

    state.finishSave({ state: 'saved', generation: 5 });
    assert.equal(state.state, 'saved');
    assert.equal(state.dirty, false);
});

test('failed save retains dirty state and its precise server provenance', () => {
    const { state } = editor({ dirty: true, state: 'saving', localGeneration: 2 });

    state.finishSave({ state: 'network-error', generation: 2 });

    assert.equal(state.state, 'network-error');
    assert.equal(state.dirty, true);
    assert.equal(state.shouldWarn(), true);
});

test('unrelated successful operation cannot clear an earlier operation error', () => {
    const { state } = editor();

    state.beginOperation('structure:remove-record:7');
    state.finishOperation({ operation: 'structure:remove-record:7', state: 'failed', message: 'Remove failed' });
    state.beginOperation('search:modules');
    state.finishOperation({ operation: 'search:modules', state: 'succeeded' });

    assert.equal(state.operationErrors['structure:remove-record:7'], 'Remove failed');
    assert.equal(state.operationErrors['search:modules'], undefined);
});

test('retrying the same operation clears only that operation error', () => {
    const { state } = editor({ operationErrors: {
        'structure:remove-record:7': 'Remove failed',
        'media:attach:8': 'Attach failed',
    } });

    state.beginOperation('structure:remove-record:7');
    state.finishOperation({ operation: 'structure:remove-record:7', state: 'succeeded' });

    assert.equal(state.operationErrors['structure:remove-record:7'], undefined);
    assert.equal(state.operationErrors['media:attach:8'], 'Attach failed');
});

test('lost immediate-operation responses use danger guidance while ordinary guidance remains status', () => {
    const { state } = editor();
    const key = 'structure:add-question:lesson:7';
    state.activeOperationMeta[key] = {
        external: true,
        kind: 'structure',
        action: 'add',
        detail: 'add-question',
        target: 'lesson:7',
    };
    state.activeOperations.add(key);

    state.finishExternalOperation({ key, state: 'failed' });

    assert.equal(state.operationalGuidanceSeverity, 'danger');
    assert.match(state.operationalGuidance, /response for add-question on lesson:7 was lost/i);
    assert.match(state.operationalGuidance, /Automatic retry is unavailable/i);
    assert.match(state.operationalGuidance, /outcome is unknown/i);

    state.showOperationalGuidance(operationalControl(), 'Wait for the current editor operation.');
    assert.equal(state.operationalGuidanceSeverity, 'status');
});

test('unsafe authored states warn while clean and saved states do not', () => {
    for (const value of ['dirty', 'saving', 'validation-error', 'conflict', 'network-error', 'unknown-outcome']) {
        const { state } = editor({ state: value, dirty: value === 'dirty' });
        assert.equal(state.shouldWarn(), true, value);
    }
    for (const value of ['clean', 'saved']) {
        const { state } = editor({ state: value });
        assert.equal(state.shouldWarn(), false, value);
    }
});

test('keyboard save dispatches once only when dirty and no dialog is open', () => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    const listeners = {};
    globalThis.document = { querySelector: () => null };
    globalThis.window = {
        addEventListener(name, callback) { listeners[name] = callback; },
        removeEventListener() {},
    };
    try {
        const { state, wireCalls } = editor({ dirty: true, state: 'dirty', localGeneration: 1 });
        state.init();
        let prevented = 0;
        listeners.keydown({ metaKey: true, ctrlKey: false, key: 's', preventDefault() { prevented++; } });
        listeners.keydown({ metaKey: true, ctrlKey: false, key: 's', preventDefault() { prevented++; } });
        assert.equal(prevented, 2);
        assert.deepEqual(wireCalls.filter(call => call[0] === 'saveDraft'), [['saveDraft', false]]);
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});

test('beforeunload is prevented only for unsafe state', () => {
    const originalWindow = globalThis.window;
    const listeners = {};
    globalThis.window = {
        addEventListener(name, callback) { listeners[name] = callback; },
        removeEventListener() {},
    };
    try {
        const clean = editor().state;
        clean.init();
        listeners.beforeunload({ preventDefault() { throw new Error('clean state warned'); } });

        const dirty = editor({ dirty: true, state: 'dirty' }).state;
        dirty.init();
        let prevented = 0;
        const event = { preventDefault() { prevented++; }, returnValue: undefined };
        listeners.beforeunload(event);
        assert.equal(prevented, 1);
        assert.equal(event.returnValue, '');
    } finally {
        globalThis.window = originalWindow;
    }
});

test('declined destructive confirmation invokes no operation', () => {
    const calls = [];
    const invokeAfterConfirmation = (accepted, operation) => {
        if (accepted) operation();
    };

    invokeAfterConfirmation(false, () => calls.push('remove'));
    assert.deepEqual(calls, []);

    invokeAfterConfirmation(true, () => calls.push('remove'));
    assert.deepEqual(calls, ['remove']);
});

test('destroy removes the exact navigation and keyboard listeners before a replacement editor initializes', () => {
    const originalWindow = globalThis.window;
    const added = [];
    const removed = [];
    globalThis.window = {
        addEventListener(name, callback) { added.push([name, callback]); },
        removeEventListener(name, callback) { removed.push([name, callback]); },
    };
    try {
        const first = editor().state;
        first.init();
        first.destroy();
        const second = editor().state;
        second.init();

        assert.deepEqual(removed, added.slice(0, 2));
        assert.equal(added.filter(([name]) => name === 'beforeunload').length, 2);
        assert.equal(added.filter(([name]) => name === 'keydown').length, 2);
        assert.notEqual(first.beforeUnloadHandler, second.beforeUnloadHandler);
        assert.notEqual(first.keyHandler, second.keyHandler);
    } finally {
        globalThis.window = originalWindow;
    }
});

test('a failed save request changes only save provenance and preserves unrelated operation failures', () => {
    const { state } = editor({ dirty: true, state: 'saving', localGeneration: 3, operationErrors: {
        'media:attach:17': 'Provider rejected the replacement',
    } });

    state.failSave();

    assert.equal(state.state, 'network-error');
    assert.equal(state.dirty, true);
    assert.equal(state.operationErrors['media:attach:17'], 'Provider rejected the replacement');
});

test('a failed video transfer retries the retained file through a genuine second provider request', async () => {
    const originalFormData = globalThis.FormData;
    const originalXmlHttpRequest = globalThis.XMLHttpRequest;
    const requests = [];
    const statuses = [503, 201];

    class FakeFormData {
        constructor() { this.values = []; }
        append(name, value) { this.values.push([name, value]); }
    }

    class FakeXmlHttpRequest {
        constructor() {
            this.listeners = {};
            this.upload = { addEventListener: () => {} };
        }

        addEventListener(name, listener) { this.listeners[name] = listener; }
        open(method, url) { this.method = method; this.url = url; }
        send(body) {
            this.status = statuses.shift();
            requests.push({ method: this.method, url: this.url, body });
            queueMicrotask(() => this.listeners.load());
        }
    }

    globalThis.FormData = FakeFormData;
    globalThis.XMLHttpRequest = FakeXmlHttpRequest;

    try {
        const calls = [];
        const file = { name: 'training.mp4', size: 1024 };
        const state = createLessonVideoUploadState(42);
        state.$wire = {
            async requestUpload(recordId) {
                calls.push(['requestUpload', recordId]);
                return { url: 'https://upload.example/first', token: 'logical-token' };
            },
            async uploadFailed(recordId, token) { calls.push(['uploadFailed', recordId, token]); },
            async retryUploadTransfer(recordId, token) {
                calls.push(['retryUploadTransfer', recordId, token]);
                return { url: 'https://upload.example/second', token };
            },
            async uploadCompleted(recordId, token) { calls.push(['uploadCompleted', recordId, token]); },
            set() {},
        };

        await state.start({ target: { files: [file], value: 'training.mp4' } });

        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, 'https://upload.example/first');
        assert.equal(requests[0].body.values[0][1], file);
        assert.equal(state.retainedFile, file);
        assert.match(state.error, /503/);

        await state.retryTransfer({ recordId: 42, uploadToken: 'logical-token' });

        assert.equal(requests.length, 2);
        assert.equal(requests[1].url, 'https://upload.example/second');
        assert.equal(requests[1].body.values[0][1], file);
        assert.equal(state.retainedFile, null);
        assert.equal(state.error, null);
        assert.deepEqual(calls, [
            ['requestUpload', 42],
            ['uploadFailed', 42, 'logical-token'],
            ['retryUploadTransfer', 42, 'logical-token'],
            ['uploadCompleted', 42, 'logical-token'],
        ]);
    } finally {
        globalThis.FormData = originalFormData;
        globalThis.XMLHttpRequest = originalXmlHttpRequest;
    }
});

test('validation focuses the mapped first invalid control without discarding local values', () => {
    let focused = false;
    const invalid = {
        dataset: { validationFor: 'courseForm.title' },
        getAttribute(name) { return name === 'wire:model' ? 'courseForm.title' : null; },
        focus() { focused = true; },
    };
    const { state } = editor({ dirty: true, state: 'saving', localGeneration: 3 });
    state.$root.querySelectorAll = selector => selector.startsWith('input') ? [invalid] : [];

    state.finishSave({ state: 'validation-error', generation: 3, invalidField: 'courseForm.title' });

    assert.equal(focused, true);
    assert.equal(state.state, 'validation-error');
    assert.equal(state.dirty, true);
    assert.equal(state.observedValues['course:1/course.title'], undefined);
});

test('permission loss disables every operational control while keeping local authored state copyable', () => {
    const controls = Array.from({ length: 3 }, () => ({
        disabled: false,
        dataset: {},
        attributes: {},
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    }));
    const { state } = editor({
        dirty: true,
        state: 'saving',
        localGeneration: 4,
        observedValues: { 'course:1/course.title': JSON.stringify('Local value to copy') },
    });
    state.$root.querySelectorAll = selector => selector.includes('data-editor-structure-action') ? controls : [];

    state.finishSave({ state: 'permission-lost', generation: 4 });

    assert.equal(state.state, 'permission-lost');
    assert.equal(state.dirty, true);
    assert.equal(state.observedValues['course:1/course.title'], JSON.stringify('Local value to copy'));
    assert.equal(controls.every(control => control.disabled && control.attributes['aria-disabled'] === 'true'), true);
});

test('preview is blocked for every unsafe editor state and restored only after a clean reload', () => {
    for (const editorState of ['dirty', 'saving', 'validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost']) {
        const { state } = editor({ state: editorState, dirty: editorState === 'dirty' });
        assert.equal(state.previewBlocked(), true, editorState);
    }

    const { state } = editor({ state: 'conflict', dirty: true });
    state.reloadDraft();
    assert.equal(state.previewBlocked(), false);
    assert.equal(state.state, 'clean');
    assert.equal(state.dirty, false);
});

test('every structural and media action is guarded before dispatch with target-specific accessible recovery guidance', () => {
    const cases = [
        { name: 'loading', initial: {}, ready: false, expected: 'Loading the editor' },
        { name: 'permission', initial: { state: 'permission-lost' }, ready: true, expected: 'Permission was removed' },
        { name: 'saving', initial: { state: 'saving' }, ready: true, expected: 'Wait for Save' },
        { name: 'conflict', initial: { state: 'conflict', dirty: true }, ready: true, expected: 'Save or recover' },
        { name: 'unknown-outcome', initial: { state: 'unknown-outcome', dirty: true }, ready: true, expected: 'Save or recover' },
        { name: 'upload', initial: { uploadInProgress: true }, ready: true, expected: 'conflicting upload' },
        { name: 'operation', initial: {}, ready: true, expected: 'current editor operation', active: true },
    ];

    for (const kind of ['structure', 'media']) {
        for (const scenario of cases) {
            const operation = scenario.name === 'upload'
                ? operationalControl(kind === 'structure'
                    ? { kind, action: 'remove', detail: 'remove-record' }
                    : { kind, action: 'attach', detail: 'attach-video' })
                : operationalControl({ kind });
            const { state } = editor(scenario.initial);
            state.ready = scenario.ready;
            state.$root.contains = () => true;
            state.$root.querySelectorAll = selector => selector.includes('[data-editor-upload]') && scenario.name === 'upload'
                ? [{ dataset: { recordKey: 'lesson:7' } }]
                : selector.includes('data-editor-structure-action') ? [operation] : [];
            state.$root.querySelector = () => null;
            if (scenario.active) state.activeOperations.add('structure:add:lesson:99');
            state.synchronizeOperationalControls();
            const calls = [];
            const event = {
                target: operation,
                preventDefault() { calls.push('prevent'); },
                stopImmediatePropagation() { calls.push('immediate'); },
                stopPropagation() { calls.push('propagation'); },
            };

            assert.equal(state.handleOperationalClick(event), false, `${kind}:${scenario.name}`);
            assert.deepEqual(calls, ['prevent', 'immediate', 'propagation'], `${kind}:${scenario.name}`);
            assert.match(state.operationalGuidance, new RegExp(scenario.expected, 'i'), `${kind}:${scenario.name}`);
            assert.equal(state.operationalGuidanceAction, scenario.name === 'upload'
                ? (kind === 'structure' ? 'remove-record' : 'attach-video')
                : 'add-question');
            assert.equal(state.operationalGuidanceTarget, 'lesson:7');
            assert.match(operation.getAttribute('aria-describedby'), /editor-operational-guidance/);
            assert.equal(operation.getAttribute('aria-disabled'), 'true');
            assert.equal(state.activeOperations.size, scenario.active ? 1 : 0);
        }
    }
});

test('dirty validation-error and known Save failure states do not block a clean immediate operation', () => {
    for (const editorState of ['dirty', 'validation-error', 'network-error']) {
        const operation = operationalControl();
        const { state } = editor({ state: editorState, dirty: true });
        state.ready = true;
        state.$root.contains = () => true;
        state.$root.querySelectorAll = selector => selector.includes('data-editor-structure-action') ? [operation] : [];
        state.$root.querySelector = () => null;

        assert.equal(state.operationBlockReason(operation), '', editorState);
        assert.equal(state.handleOperationalClick({ target: operation }), true, editorState);
    }
});

test('confirmation requests preserve authored save state for retryable read-only failures and isolate permission loss', () => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    globalThis.document = { body: null, querySelector: () => null };
    globalThis.window = { addEventListener() {}, removeEventListener() {} };

    try {
        for (const scenario of [
            {
                status: 403,
                expectedState: 'permission-lost',
                expectedKind: 'permission',
                expectedMessage: 'Permission was removed. Remove video on Safety lesson was not applied. Your local values remain available to copy.',
            },
            {
                status: 503,
                expectedState: 'dirty',
                expectedKind: null,
                expectedMessage: 'Confirmation for Remove video on Safety lesson could not be opened. No change was applied; try the original action again.',
            },
            {
                status: 503,
                expectedState: 'dirty',
                expectedKind: null,
                expectedMessage: 'Não foi possível abrir a confirmação de Remover vídeo em Módulo de segurança. Nenhuma alteração foi aplicada; tente a ação original novamente.',
                label: 'Remover vídeo',
                targetLabel: 'Módulo de segurança',
                messages: {
                    confirmationFailed: 'Não foi possível abrir a confirmação de :action em :target. Nenhuma alteração foi aplicada; tente a ação original novamente.',
                },
            },
        ]) {
            const operation = operationalControl({
                kind: 'media',
                action: 'remove',
                detail: 'confirm-video-removal',
                target: 'lesson:7',
                targetLabel: scenario.targetLabel || 'Safety lesson',
                label: scenario.label || 'Remove video',
                wireClick: 'confirmVideoDestruction(7, 9)',
            });
            const { state, wireCalls, hooks } = editor({
                dirty: true,
                state: 'dirty',
                observedValues: { 'course:1/course.title': JSON.stringify('Local title') },
                messages: scenario.messages,
            });
            state.ready = true;
            state.$root.contains = () => true;
            state.$root.querySelector = () => null;
            state.$root.querySelectorAll = selector => selector.includes('data-editor-media-action') ? [operation] : [];
            state.init();

            assert.equal(state.handleOperationalClick({ target: operation }), true);
            assert.equal(state.activeOperations.size, 1);
            assert.equal(Object.values(state.activeOperationMeta)[0].readOnly, true);

            let failRequest;
            hooks.request({
                succeed() {},
                fail(callback) { failRequest = callback; },
            });
            failRequest({ status: scenario.status });

            assert.equal(state.state, scenario.expectedState);
            assert.equal(state.dirty, true);
            assert.equal(state.observedValues['course:1/course.title'], JSON.stringify('Local title'));
            assert.equal(state.operationalGuidance, scenario.expectedMessage);
            assert.doesNotMatch(state.operationalGuidance, /confirm-video-removal|lesson:7/);
            assert.doesNotMatch(state.operationalGuidance, /outcome is unknown/i);
            const failureWireCalls = wireCalls.filter(call => call[0] === 'set' && ['saveState', 'errorKind'].includes(call[1]));
            assert.deepEqual(failureWireCalls, scenario.expectedKind ? [
                ['set', 'saveState', scenario.expectedState, false],
                ['set', 'errorKind', scenario.expectedKind, false],
            ] : []);
            assert.equal(state.confirmationFailureActive, scenario.status !== 403);
            assert.equal(state.activeOperations.size, 0);
        }
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});

test('a retryable confirmation failure clears after retry success without changing clean or dirty save provenance', () => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    globalThis.document = { body: null, querySelector: () => null };
    globalThis.window = { addEventListener() {}, removeEventListener() {} };

    try {
        for (const initial of [
            { state: 'clean', dirty: false, observedValues: { 'course:1/course.title': JSON.stringify('Saved title') } },
            { state: 'dirty', dirty: true, observedValues: { 'course:1/course.title': JSON.stringify('Local title') } },
        ]) {
            const operation = operationalControl({
                kind: 'media',
                action: 'remove',
                detail: 'confirm-video-removal',
                target: 'lesson:7',
                targetLabel: 'Safety lesson',
                label: 'Remove video',
                wireClick: 'confirmVideoDestruction(7, 9)',
            });
            const { state, wireCalls, hooks } = editor(initial);
            state.ready = true;
            state.$root.contains = () => true;
            state.$root.querySelector = () => null;
            state.$root.querySelectorAll = selector => selector.includes('data-editor-media-action') ? [operation] : [];
            state.init();

            assert.equal(state.handleOperationalClick({ target: operation }), true);
            assert.equal(state.activeOperations.size, 1);
            let failRequest;
            hooks.request({ succeed() {}, fail(callback) { failRequest = callback; } });
            failRequest({ status: 503 });

            assert.equal(state.state, initial.state);
            assert.equal(state.dirty, initial.dirty);
            assert.deepEqual(state.observedValues, initial.observedValues);
            assert.equal(state.confirmationFailureActive, true);
            assert.match(state.operationalGuidance, /No change was applied/);

            assert.equal(state.handleOperationalClick({ target: operation }), true);
            assert.equal(state.activeOperations.size, 1);
            let succeedRequest;
            hooks.request({ succeed(callback) { succeedRequest = callback; }, fail() {} });
            succeedRequest();

            assert.equal(state.state, initial.state);
            assert.equal(state.dirty, initial.dirty);
            assert.deepEqual(state.observedValues, initial.observedValues);
            assert.equal(state.confirmationFailureActive, false);
            assert.equal(state.operationalGuidance, '');
            assert.equal(state.activeOperations.size, 0);
            assert.equal(wireCalls.some(call => call[0] === 'set' && ['saveState', 'errorKind'].includes(call[1])), false);

            state.$wire.cancelDestructiveConfirmation('confirmingDestructive');
            let cancelRequestSucceeded;
            hooks.request({ succeed(callback) { cancelRequestSucceeded = callback; }, fail() {} });
            cancelRequestSucceeded();

            assert.equal(state.state, initial.state);
            assert.equal(state.dirty, initial.dirty);
            assert.deepEqual(state.observedValues, initial.observedValues);
            assert.equal(state.confirmationFailureActive, false);
            assert.equal(state.operationalGuidance, '');
            assert.equal(state.activeOperations.size, 0);
            assert.deepEqual(wireCalls.filter(call => call[0] === 'cancelDestructiveConfirmation'), [
                ['cancelDestructiveConfirmation', 'confirmingDestructive'],
            ]);
            assert.equal(wireCalls.some(call => ['saveDraft', 'performConfirmedDestructive'].includes(call[0])), false);
        }
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
});

test('an active upload blocks only operations that invalidate the same media target', () => {
    const addQuestion = operationalControl({ action: 'add', detail: 'add-question', target: 'lesson:7' });
    const removeLesson = operationalControl({ action: 'remove', detail: 'remove-record', target: 'lesson:7' });
    const attachElsewhere = operationalControl({ kind: 'media', action: 'attach', detail: 'attach-video', target: 'lesson:8' });
    const row = { dataset: { recordKey: 'lesson:7' } };
    const { state } = editor({ uploadInProgress: true });
    state.ready = true;
    state.$root.querySelectorAll = selector => selector.includes('[data-editor-upload]') ? [row] : [];
    state.$root.querySelector = () => null;

    assert.equal(state.operationBlockReason(addQuestion), '');
    assert.match(state.operationBlockReason(removeLesson), /conflicting upload/i);
    assert.equal(state.operationBlockReason(attachElsewhere), '');
});

test('an active video upload does not block image library or insertion operations on the same record', () => {
    const openImage = operationalControl({ kind: 'media', family: 'image', action: 'open-library', detail: 'open-image-library', target: 'lesson:7' });
    const selectImage = operationalControl({ kind: 'media', family: 'image', action: 'attach', detail: 'image-select', target: 'lesson:7' });
    const replaceVideo = operationalControl({ kind: 'media', family: 'video', action: 'upload', detail: 'video-upload', target: 'lesson:7' });
    const { state } = editor({ uploadInProgress: true });
    state.ready = true;
    state.activeOperationMeta['media:video:upload:lesson:7'] = { external: true, kind: 'media', family: 'video', action: 'upload', target: 'lesson:7' };
    state.activeOperations.add('media:video:upload:lesson:7');
    state.$root.querySelectorAll = selector => selector.includes('[data-editor-upload]') ? [{ dataset: { recordKey: 'lesson:7' } }] : [];
    state.$root.querySelector = () => null;

    assert.equal(state.operationBlockReason(openImage), '');
    assert.equal(state.operationBlockReason(selectImage), '');
    assert.match(state.operationBlockReason(replaceVideo), /current editor operation|conflicting upload/i);
    assert.notEqual(state.operationIdentity(openImage).key, state.operationIdentity(replaceVideo).key);
});

test('single-choice radio changes stage the complete stable group in one generation', () => {
    const makeRadio = (recordKey, model, checked) => {
        const record = { dataset: { recordKey }, parentElement: null };
        const field = {
            dataset: { fieldName: 'option.correctness' },
            closest(selector) { return selector === '[data-editor-record]' ? record : null; },
        };
        const radio = {
            type: 'radio',
            name: 'correct-question-9',
            checked,
            dataset: { editorModel: model },
            localName: 'input',
            closest(selector) { return selector === '[data-editor-field]' ? field : null; },
        };
        field.querySelector = () => radio;

        return { radio, field };
    };
    const first = makeRadio('option:31', 'records.0.questions.0.options.0.is_correct', true);
    const second = makeRadio('option:32', 'records.0.questions.0.options.1.is_correct', false);
    const { state, wireCalls } = editor({ observedValues: {
        'option:31/option.correctness': 'true',
        'option:32/option.correctness': 'false',
    } });
    state.$root.querySelectorAll = selector => selector.includes('input[type="radio"]') ? [first.radio, second.radio] : [];
    first.radio.checked = false;
    second.radio.checked = true;

    state.markDirty({ target: second.radio });

    assert.equal(state.localGeneration, 1);
    assert.equal(state.fieldGenerations['option:31/option.correctness'], 1);
    assert.equal(state.fieldGenerations['option:32/option.correctness'], 1);
    assert.deepEqual(wireCalls.filter(call => call[1]?.endsWith?.('is_correct')), [
        ['set', 'records.0.questions.0.options.0.is_correct', false, false],
        ['set', 'records.0.questions.0.options.1.is_correct', true, false],
    ]);
});

test('a lost immediate response enters unknown-outcome and hard-locks Save and canonical actions without replay', () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    globalThis.document = { querySelector: () => null, body: null };
    try {
        const operation = operationalControl();
        const save = { disabled: false, dataset: {}, getAttribute: () => null, setAttribute() {}, removeAttribute() {} };
        const { state, wireCalls, hooks } = editor({ dirty: true, state: 'dirty', localGeneration: 2 });
        state.$dispatch = () => {};
        state.$root.contains = () => true;
        state.$root.querySelector = () => null;
        state.$root.querySelectorAll = selector => selector.includes('data-editor-structure-action')
            ? [operation]
            : selector.includes('[data-editor-save]') ? [save] : [];
        state.init();
        state.ready = true;
        state.handleOperationalClick({ target: operation });
        let failRequest;
        hooks.request({ succeed() {}, fail(callback) { failRequest = callback; } });

        failRequest({ status: 0 });

        assert.equal(state.state, 'unknown-outcome');
        assert.equal(state.dirty, true);
        assert.equal(operation.disabled, true);
        assert.equal(save.disabled, true);
        assert.equal(state.operationalGuidanceSeverity, 'danger');
        assert.match(state.operationalGuidance, /outcome is unknown/i);
        assert.equal(wireCalls.some(call => call[0] === 'saveDraft'), false);
        assert.deepEqual(wireCalls.filter(call => call[0] === 'set' && call[1] === 'saveState'), [
            ['set', 'saveState', 'unknown-outcome', false],
        ]);
    } finally {
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
    }
});

test('a newer authored edit is overlaid after an immediate request without resurrecting removed identities', () => {
    const retained = control({ fieldName: 'option.text', recordKey: 'option:31', value: 'Before' });
    const removed = control({ fieldName: 'option.text', recordKey: 'option:32', value: 'Removed before' });
    const retainedField = retained.closest('[data-editor-field]');
    const removedField = removed.closest('[data-editor-field]');
    retainedField.querySelector = () => retained;
    removedField.querySelector = () => removed;
    retained.getAttribute = name => name === 'wire:model.defer' ? 'records.0.questions.0.options.0.text' : null;
    removed.getAttribute = name => name === 'wire:model.defer' ? 'records.0.questions.0.options.1.text' : null;
    const { state, wireCalls } = editor({ dirty: true, state: 'dirty', localGeneration: 3 });
    state.$root.querySelectorAll = selector => selector === '[data-editor-field]' ? [retainedField, removedField] : [];

    const request = state.captureOperationRequest();
    retained.value = 'Typed during request';
    state.markDirty({ target: retained });
    removed.value = 'Must not return';
    state.markDirty({ target: removed });
    state.reapplyOperationOverlay(request, ['option:32']);

    assert.equal(retained.value, 'Typed during request');
    assert.equal(removed.value, 'Must not return');
    assert.deepEqual(wireCalls.filter(call => call[0] === 'set' && call[1].endsWith('.text')), [
        ['set', 'records.0.questions.0.options.0.text', 'Typed during request', false],
    ]);
    assert.equal(state.localGeneration, 5);
    assert.equal(state.dirty, true);
});

test('successful post-morph focus restoration stops retrying before another authored control receives input', () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const originalAnimationFrame = globalThis.requestAnimationFrame;
    const originalCss = globalThis.CSS;
    const callbacks = [];
    const otherAuthoredControl = { marker: 'other authored control' };
    let focusCount = 0;
    const target = {
        focus() {
            focusCount++;
            globalThis.document.activeElement = target;
        },
    };
    globalThis.window = { setTimeout(callback) { callbacks.push(callback); } };
    globalThis.document = {
        activeElement: null,
        querySelector() { return target; },
    };
    globalThis.requestAnimationFrame = callback => callback();
    globalThis.CSS = { escape: value => String(value) };

    try {
        const { state } = editor();
        state.restoreFocus('option:31', 'first-authored-field', 1);
        state.restoreFocus('option:31', 'first-authored-field', 1);
        globalThis.document.activeElement = otherAuthoredControl;
        callbacks.forEach(callback => callback());

        assert.equal(focusCount, 1);
        assert.equal(globalThis.document.activeElement, otherAuthoredControl);
    } finally {
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
        globalThis.requestAnimationFrame = originalAnimationFrame;
        globalThis.CSS = originalCss;
    }
});

test('an immediate action enters local pending state before dispatch and blocks a duplicate until completion', () => {
    const operation = operationalControl();
    const { state } = editor();
    state.ready = true;
    state.$root.contains = () => true;
    state.$root.querySelectorAll = selector => selector.includes('data-editor-structure-action') || selector.includes('data-editor-operation-state') ? [operation] : [];
    state.$root.querySelector = () => null;
    const event = {
        target: operation,
        prevented: 0,
        preventDefault() { this.prevented++; },
        stopImmediatePropagation() {},
        stopPropagation() {},
    };

    assert.equal(state.handleOperationalClick(event), true);
    assert.deepEqual([...state.activeOperations], ['structure:add-question:lesson:7']);
    assert.equal(operation.dataset.editorOperationState, 'pending');
    assert.equal(operation.getAttribute('aria-busy'), 'true');
    assert.equal(operation.disabled, true);

    assert.equal(state.handleOperationalClick(event), false);
    assert.equal(event.prevented, 1);
    assert.match(state.operationalGuidance, /current editor operation/i);
    assert.deepEqual([...state.activeOperations], ['structure:add-question:lesson:7']);

    state.clearLocalOperations();
    assert.equal(state.activeOperations.size, 0);
    assert.equal(operation.dataset.editorOperationState, undefined);
    assert.equal(operation.getAttribute('aria-busy'), null);
    assert.equal(operation.disabled, false);
});
