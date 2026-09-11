import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Execute the exact production Alpine state machine, replacing only Blade's initial data.
const blade = readFileSync(new URL('../../resources/views/components/courses/⚡editor.blade.php', import.meta.url), 'utf8');
const expression = blade.match(/x-data="(\{ state: [^\n]+)"/)[1]
    .replace(/@js\(\$saveState\)/g, "'clean'")
    .replace(/@js\(\$clientRevision\)/g, '0')
    .replace(/@js\(\$clientFieldRevisions\)/g, '[]')
    .replace(/@js\(\$client(?:HasNetworkError|ModuleNetworkError)\)/g, 'false');

function editor() {
    const hooks = {}, interceptors = [];
    const wire = { saveState: 'clean', clientHasUnsavedChanges: false,
        $get(property) { return property.endsWith('is_required') ? false : 'Before'; },
        $set(key, value) { this[key] = value; },
        $hook(name, callback) { hooks[name] = callback; },
        $interceptAction(callback) { interceptors.push(callback); return () => {}; },
    };
    const state = new Function('$wire', `return (${expression})`)(wire);
    state.init();
    const request = (name) => {
        for (const intercept of interceptors) intercept({ action: { name } });
        const callbacks = {};
        hooks.request({ succeed: callback => callbacks.succeed = callback, fail: callback => callbacks.fail = callback });
        return callbacks;
    };
    const event = (name, detail = {}) => {
        const handler = blade.match(new RegExp(`x-on:${name}\\.window="([^"\\n]+)"`))[1];
        new Function('state', '$wire', '$event', `with (state) { ${handler} }`)(state, wire, { detail });
    };
    return { state, wire, request, event };
}
function control(property, value, type = 'text') {
    return { value, type, checked: false, closest() { return this; },
        getAttributeNames: () => ['wire:model.live'], getAttribute: () => property };
}

test('a saved live value does not become dirty again on unchanged change/blur', () => {
    const { state, wire, event } = editor();
    const input = control('courseForm.title', 'First edit');
    state.markDirty({ target: input });
    assert.equal(state.revision, 1);
    wire.saveState = 'saved'; wire.clientHasUnsavedChanges = false;
    event('editor-field-saved', { property: 'courseForm.title', revision: 1 });
    assert.equal(state.dirty, false);
    state.markDirty({ target: input });
    assert.equal(state.revision, 1);
    assert.equal(state.state, 'saved');
    input.value = 'Second edit'; state.markDirty({ target: input });
    assert.equal(state.revision, 2);
    assert.equal(state.dirty, true);
});

test('checkbox input/change pair counts once but a new checked value counts again', () => {
    const { state } = editor();
    const input = control('lessons.0.is_required', 'on', 'checkbox');
    input.checked = true;
    state.markDirty({ target: input }); state.markDirty({ target: input });
    assert.equal(state.revision, 1);
    input.checked = false; state.markDirty({ target: input });
    assert.equal(state.revision, 2);
});

test('saved values remain deduplicated when Livewire replaces the control node', () => {
    const { state } = editor();
    state.markDirty({ target: control('courseForm.title', 'Saved value') });
    state.markDirty({ target: control('courseForm.title', 'Saved value') });
    assert.equal(state.revision, 1);
    state.markDirty({ target: control('courseForm.title', 'Changed again') });
    assert.equal(state.revision, 2);
});

test('after state remount an unchanged blur uses the current Livewire value as baseline', () => {
    const { state, wire } = editor();
    wire.$get = () => 'Saved value';
    state.state = 'saved';
    state.markDirty({ target: control('courseForm.title', 'Saved value') });
    assert.equal(state.revision, 0); assert.equal(state.state, 'saved');
    state.markDirty({ target: control('courseForm.title', 'Actually changed') });
    assert.equal(state.revision, 1); assert.equal(state.dirty, true);
});

test('record identities distinguish fields after reorder while the moved value remains deduplicated', () => {
    const { state } = editor();
    function answer(id, index, value) {
        const input = control(`lessons.${index}.questions.0.options.0.text`, value);
        input.closest = selector => selector === '[data-editor-authored]' ? input : { dataset: { lessonDropId: id, editorQuestionId: id, editorOptionId: id } };
        return input;
    }
    state.markDirty({ target: answer('alpha', 0, 'Alpha') });
    state.markDirty({ target: answer('bravo', 1, 'Bravo') });
    state.markDirty({ target: answer('bravo', 0, 'Bravo') });
    assert.equal(state.revision, 2);
    state.markDirty({ target: answer('bravo', 0, 'Alpha') });
    assert.equal(state.revision, 3);
});

test('module action enters pending before request and outage is visible from clean state', () => {
    const { state, wire, request } = editor();
    const callbacks = request('addModule');
    assert.equal(state.structuralPending, true);
    assert.equal(state.state, 'saving');
    callbacks.fail();
    assert.equal(state.state, 'network-error');
    assert.equal(wire.clientModuleNetworkError, true);
});

test('Flux checkbox revisions follow checked state rather than its fixed generated value', () => {
    const { state } = editor();
    const input = control('lessons.0.is_required', 'generated-value', undefined);
    input.type = undefined; input.localName = 'ui-checkbox'; input.checked = true;
    state.markDirty({ target: input }); state.markDirty({ target: input });
    assert.equal(state.revision, 1);
    input.checked = false; state.markDirty({ target: input });
    assert.equal(state.revision, 2);
});

test('search without an authored action stays clean', () => {
    const { state, request } = editor();
    request('moduleSearch');
    assert.equal(state.structuralPending, false);
    assert.equal(state.state, 'clean');
});

test('an unresolved module outage remains visible through search and unrelated structural acknowledgement', async () => {
    const { state, wire, request, event } = editor();
    request('addModule').fail();
    assert.equal(wire.clientHasUnsavedChanges, true);
    request('moduleSearch').succeed();
    await new Promise(resolve => setTimeout(resolve, 5));
    assert.equal(state.state, 'network-error');
    request('addLesson');
    event('editor-saved', { revision: 0 });
    assert.equal(state.dirty, true);
    assert.equal(state.state, 'network-error');
    assert.equal(wire.clientModuleNetworkError, true);
});

test('refusal then successful module retry settles while unrelated fields remain dirty', () => {
    const { state, wire, request, event } = editor();
    request('addModule').fail();
    request('addModule');
    wire.saveState = 'validation-error'; event('editor-validation-error');
    assert.equal(state.state, 'validation-error');
    assert.equal(state.structuralPending, false);
    request('addModule');
    wire.clientModuleNetworkError = false; wire.saveState = 'saved';
    event('editor-saved', { revision: 0 });
    assert.equal(state.state, 'saved'); assert.equal(state.dirty, false);

    state.markDirty({ target: control('courseForm.title', 'Pending edit') });
    request('removeModule'); wire.saveState = 'dirty';
    event('editor-structure-saved');
    assert.equal(state.state, 'dirty'); assert.equal(state.dirty, true);
    assert.deepEqual(state.fieldRevisions, [{ property: 'courseForm.title', revision: 1 }]);
});

test('validation recovery and stale acknowledgement cannot clear a newer field revision', () => {
    const { state, wire, event } = editor();
    const input = control('courseForm.title', '');
    state.markDirty({ target: input }); event('editor-validation-error');
    state.markDirty({ target: input });
    assert.equal(state.revision, 1); assert.equal(state.state, 'validation-error');
    input.value = 'Recovered title'; state.markDirty({ target: input });
    event('editor-field-saved', { property: 'courseForm.title', revision: 1 });
    assert.equal(state.dirty, true);
    wire.saveState = 'saved'; wire.clientHasUnsavedChanges = false;
    event('editor-field-saved', { property: 'courseForm.title', revision: 2 });
    state.markDirty({ target: input });
    assert.equal(state.state, 'saved'); assert.equal(state.revision, 2);
});

test('the lesson title binding sends its changed value on blur through installed Livewire', async () => {
    const runtime = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8');
    const modelDirective = runtime.split('// js/directives/wire-model.js')[1].split('// js/directives/wire-init.js')[0];
    const modifiers = blade.match(/wire:model([.\w]+)="lessons\.\{\{ \$lessonIndex \}\}\.title"/)[1].slice(1).split('.');
    async function edit(bindingModifiers) {
        let directive, bindings, sends = 0;
        const wire = { lessons: [{ title: 'Before' }], $commit: () => sends++ };
        const component = { canonical: { lessons: [{ title: 'Before' }] }, $wire: wire };
        const get = (object, path) => path.split('.').reduce((value, key) => value[key], object);
        const set = (object, path, value) => { const keys = path.split('.'); get(object, keys.slice(0, -1).join('.'))[keys.at(-1)] = value; };
        new Function('directive2', 'findComponentByEl', 'module_default', 'dataGet', 'dataSet', 'checkDirty', 'setNextActionOrigin', 'setNextActionMetadata', modelDirective)(
            (_, callback) => directive = callback, () => component, { bind: (_, value) => bindings = value }, get, set,
            () => wire.lessons[0].title !== 'Before', () => {}, () => {},
        );
        directive({ el: { type: 'text', tagName: 'INPUT' }, directive: { expression: 'lessons.0.title', modifiers: bindingModifiers }, component, cleanup: () => {} });
        bindings[Object.keys(bindings).find(key => key.startsWith('x-model'))]().set('Persist on blur');
        bindings['@blur']?.(); await new Promise(resolve => queueMicrotask(resolve));
        return { sends, value: wire.lessons[0].title };
    }
    assert.deepEqual(await edit(['blur']), { sends: 0, value: 'Persist on blur' });
    assert.deepEqual(await edit(modifiers), { sends: 1, value: 'Persist on blur' });
});
