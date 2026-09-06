import test from 'node:test';
import assert from 'node:assert/strict';
import { coursePreviewShare, createPreviewPlayer, mountPreviewPlayers } from '../../resources/js/course-preview.js';
const tick = () => new Promise(resolve => setImmediate(resolve));
const reply = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const grantData = n => ({ playback_url: `https://media.test/${n}.mp4`, expires_at: new Date(60000).toISOString() });
class Control extends EventTarget {
    currentTime = 0; paused = true; playCalls = 0; loadCalls = 0; hidden = false;
    play() { this.playCalls++; this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
    pause() { this.paused = true; this.dispatchEvent(new Event('pause')); }
    load() { this.loadCalls++; }
    removeAttribute(name) { delete this[name]; }
    metadata() { this.dispatchEvent(new Event('loadedmetadata')); }
}
function player(responses, hlsClient = { isSupported: () => false }) {
    const video = new Control(), button = new Control(), status = {}, guidance = { hidden: true, textContent: 'contact sender' };
    const calls = [], timers = new Map(); let counter = 0;
    const root = { dataset: { endpoint: '/preview/courses/test/items/composition/4/playback', loading: 'loading', ready: 'ready', failed: 'failed', ended: 'ended' }, querySelector: selector => ({ video, '[data-play]': button, '[data-status]': status, '[data-ended-guidance]': guidance }[selector]) };
    const instance = createPreviewPlayer(root, {
        fetch: async (...args) => { calls.push(args); return typeof responses[0] === 'function' ? responses.shift()() : responses.shift(); },
        Hls: hlsClient, csrf: () => 'csrf', now: () => 0,
        setTimeout: (fn, delay) => { timers.set(++counter, { fn, delay }); return counter; }, clearTimeout: id => timers.delete(id),
    });
    return { instance, video, button, status, guidance, calls, timers, renew: () => [...timers.values()][0].fn() };
}

test('generate/reuse/copy/manual fallback/revocation use only the operator endpoint', async () => {
    globalThis.document = { documentElement: { lang: 'en' }, querySelector: () => ({ content: 'csrf' }) };
    const active = { state: 'active', url: 'https://preview.test/bearer', expires_at: new Date(60000).toISOString() };
    const responses = [reply(201, active), reply(200, active), reply(200, active), reply(403, {})]; const calls = [], copied = [];
    globalThis.fetch = async (...args) => { calls.push(args); return responses.shift(); };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: async text => copied.push(text) } } });
    const share = coursePreviewShare('/operator/preview-link', { state: 'absent' }, { copied: 'copied', manual: 'manual', failed: 'failed' });
    let focused = false, selected = false;
    share.$refs = { link: { focus: () => focused = true, select: () => selected = true } };
    const pending = share.generate(); assert.equal(share.busy, true); await pending;
    assert.equal(share.url, active.url); assert.equal(share.busy, false);
    await share.copy(); assert.deepEqual(copied, [active.url]); assert.equal(share.expires_at, active.expires_at);
    navigator.clipboard.writeText = async () => { throw new Error('denied'); };
    await share.copy(); assert.equal(share.message, 'manual'); assert.ok(focused && selected); assert.equal(share.url, active.url);
    await share.copy(); assert.equal(share.url, null); assert.equal(share.state, 'denied');
    assert.deepEqual(calls.map(x => x[0]), Array(4).fill('/operator/preview-link'));
    assert.equal(calls[0][1].method, 'POST'); assert.ok(calls.slice(1).every(x => !x[1].method));
    delete globalThis.document;
});

test('playing renewal preserves position and play; paused renewal never resumes', async () => {
    const p = player([reply(200, grantData(1)), reply(200, grantData(2)), reply(200, grantData(3))]);
    p.button.dispatchEvent(new Event('click')); await tick(); p.video.metadata(); assert.equal(p.video.playCalls, 1);
    p.video.currentTime = 17; await p.renew(); p.video.metadata();
    assert.equal(p.video.currentTime, 17); assert.equal(p.video.playCalls, 2);
    p.video.pause(); await p.renew(); p.video.metadata();
    assert.equal(p.video.playCalls, 2); assert.equal(p.video.paused, true);
    assert.ok(p.calls.every(([url, options]) => url.includes('/preview/courses/') && options.method === 'POST'));
    p.instance.destroy(); assert.equal(p.timers.size, 0);
});

test('pause during in-flight renewal wins over earlier play intent', async () => {
    let resolve;
    const p = player([reply(200, grantData(1)), () => new Promise(done => resolve = done)]);
    await p.instance.start(); p.video.metadata();
    const pending = p.renew(); await tick(); p.video.pause(); p.video.currentTime = 29;
    resolve(reply(200, grantData(2))); await pending; p.video.metadata();
    assert.equal(p.video.playCalls, 1); assert.equal(p.video.paused, true); assert.equal(p.video.currentTime, 29);
});

test('retryable failures allow explicit retry; ended previews stop all later grants', async () => {
    const p = player([reply(503, { message: 'retry' }), reply(200, grantData(1)), reply(410, { message: 'ended' })]);
    await p.instance.start(); assert.equal(p.status.textContent, 'retry'); assert.equal(p.button.disabled, false);
    await p.instance.start(); p.video.metadata(); assert.equal(p.video.playCalls, 1);
    await p.renew(); assert.equal(p.status.textContent, 'ended'); assert.equal(p.video.src, undefined); assert.equal(p.button.disabled, true); assert.equal(p.timers.size, 0);
    p.video.dispatchEvent(new Event('error')); assert.equal(p.status.textContent, 'ended');
    await p.instance.start(); assert.equal(p.calls.length, 3);
});

test('page exit prevents an outstanding grant from restarting playback', async () => {
    let resolve;
    const p = player([() => new Promise(done => resolve = done)]);
    const pending = p.instance.start(); await tick(); p.instance.destroy();
    resolve(reply(200, grantData(1))); await pending; p.video.metadata();
    assert.equal(p.video.playCalls, 0); assert.equal(p.video.src, undefined); assert.equal(p.timers.size, 0);
});


test('HLS loading and fatal failure use the same retryable preview controls', async () => {
    const instances = [];
    class HlsFake {
        static isSupported() { return true; }
        static Events = { ERROR: 'error' };
        constructor() { instances.push(this); }
        loadSource(url) { this.url = url; }
        attachMedia(media) { this.media = media; }
        on(event, handler) { this.handler = handler; }
        destroy() { this.destroyed = true; }
    }
    const p = player([reply(200, { ...grantData(1), playback_url: 'https://media.test/manifest.m3u8' })], HlsFake);
    await p.instance.start(); p.video.metadata();
    assert.equal(instances[0].url, 'https://media.test/manifest.m3u8'); assert.equal(instances[0].media, p.video);
    instances[0].handler('error', { fatal: true });
    assert.equal(p.status.textContent, 'failed'); assert.equal(p.video.paused, true); assert.equal(p.timers.size, 0); assert.equal(p.button.disabled, false);
});

test('generation errors clear stale credentials and do not copy or contact training routes', async () => {
    globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
    let copies = 0; const calls = [];
    globalThis.fetch = async (...args) => { calls.push(args); return reply(403, {}); };
    navigator.clipboard.writeText = async () => copies++;
    const share = coursePreviewShare('/operator/preview-link', { state: 'expired', url: 'old' }, { failed: 'failed' });
    await share.generate();
    assert.equal(share.busy, false); assert.equal(share.url, null); assert.equal(share.message, 'failed'); assert.equal(share.state, 'denied'); assert.equal(copies, 0);
    assert.deepEqual(calls.map(([url]) => url), ['/operator/preview-link']);
    delete globalThis.document;
});

test('followed platform login redirects and 401/403 hide credentials and controls before parsing HTML', async () => {
    globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
    let parses = 0, copies = 0;
    navigator.clipboard.writeText = async () => copies++;
    const outcomes = [
        { ok: true, status: 200, redirected: true, url: 'https://app.test/platform/login' },
        { ok: true, status: 200, redirected: false, url: 'https://app.test/login' },
        { ok: false, status: 401 }, { ok: false, status: 403 },
    ];
    for (const outcome of outcomes) {
        globalThis.fetch = async () => ({ ...outcome, json: async () => { parses++; throw new Error('HTML login response'); } });
        for (const action of ['generate', 'copy']) {
            const share = coursePreviewShare('/platform/shared-courses/1/versions/1/preview-link', { state: 'active', url: 'previously-authorized-link' }, { failed: 'denied' });
            await share[action]();
            assert.equal(share.state, 'denied'); assert.equal(share.url, null); assert.equal(share.busy, false);
            assert.equal(share.state === 'active' || share.state === 'absent' || share.state === 'expired', false);
        }
    }
    assert.equal(parses, 0); assert.equal(copies, 0);
    delete globalThis.document;
});

test('native missing-media errors show localized retry feedback and stop renewal until explicit retry', async () => {
    const p = player([reply(200, grantData(1)), reply(200, grantData(2))]);
    await p.instance.start(); p.video.metadata();
    p.video.dispatchEvent(new Event('error'));
    assert.equal(p.status.textContent, 'failed'); assert.equal(p.button.disabled, false);
    assert.equal(p.timers.size, 0); assert.equal(p.video.src, undefined); assert.equal(p.video.paused, true);
    p.video.metadata(); assert.equal(p.video.playCalls, 1);
    p.button.dispatchEvent(new Event('click')); await tick(); p.video.metadata();
    assert.equal(p.status.textContent, 'ready'); assert.equal(p.video.playCalls, 2); assert.equal(p.calls.length, 2);
    p.instance.destroy();
    p.status.textContent = 'disposed'; p.video.dispatchEvent(new Event('error')); assert.equal(p.status.textContent, 'disposed');
});

test('native error invalidates outstanding renewal and cannot overwrite a later retry', async () => {
    let resolveOld;
    const p = player([reply(200, grantData(1)), () => new Promise(done => resolveOld = done), reply(200, grantData(3))]);
    await p.instance.start(); p.video.metadata();
    const pending = p.renew(); await tick(); p.video.dispatchEvent(new Event('error'));
    assert.equal(p.status.textContent, 'failed'); assert.equal(p.timers.size, 0);
    await p.instance.start(); p.video.metadata(); assert.equal(p.video.src, grantData(3).playback_url);
    resolveOld(reply(200, grantData(2))); await pending;
    assert.equal(p.video.src, grantData(3).playback_url); assert.equal(p.video.playCalls, 2); assert.equal(p.status.textContent, 'ready');
});

test('transient copy refresh failures retain the original active link and expiry for manual selection', async () => {
    const active = { state: 'active', url: 'original-capability', expires_at: '2030-01-01T00:00:00Z' };
    const failures = [async () => { throw new Error('offline'); }, async () => reply(503, {}), async () => reply(502, {})];
    let clipboardCalls = 0;
    navigator.clipboard.writeText = async () => clipboardCalls++;
    for (const failure of failures) {
        globalThis.fetch = failure;
        let focused = false, selected = false;
        const share = coursePreviewShare('/operator/preview-link', active, { failed: 'failed', manual: 'manual' });
        share.$refs = { link: { focus: () => focused = true, select: () => selected = true } };
        await share.copy();
        assert.equal(share.state, active.state); assert.equal(share.url, active.url); assert.equal(share.expires_at, active.expires_at);
        assert.equal(share.message, 'manual'); assert.ok(focused && selected);
    }
    assert.equal(clipboardCalls, 0);
});

test('transient generation failures preserve previously displayed information and allow retry', async () => {
    globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
    const active = { state: 'active', url: 'original-capability', expires_at: '2030-01-01T00:00:00Z' };
    for (const failure of [async () => { throw new Error('offline'); }, async () => reply(503, {})]) {
        globalThis.fetch = failure;
        const share = coursePreviewShare('/operator/preview-link', active, { failed: 'failed' });
        await share.generate();
        assert.equal(share.state, active.state); assert.equal(share.url, active.url); assert.equal(share.expires_at, active.expires_at); assert.equal(share.busy, false);
        globalThis.fetch = async () => reply(200, active); await share.generate(); assert.equal(share.url, active.url); assert.equal(share.message, '');
    }
    delete globalThis.document;
});

test('confirmed expiry and denied access remove the credential instead of using the transient fallback', async () => {
    const active = { state: 'active', url: 'original-capability', expires_at: '2030-01-01T00:00:00Z' };
    const outcomes = [reply(200, { state: 'expired', url: null, expires_at: null }), reply(403, {}), reply(410, {})];
    let clipboardCalls = 0, selectionCalls = 0;
    navigator.clipboard.writeText = async () => clipboardCalls++;
    for (const response of outcomes) {
        globalThis.fetch = async () => response;
        const share = coursePreviewShare('/operator/preview-link', active, { failed: 'failed', manual: 'manual' });
        share.$refs = { link: { focus: () => selectionCalls++, select: () => selectionCalls++ } };
        await share.copy();
        assert.equal(share.url, null); assert.equal(share.expires_at, null); assert.notEqual(share.state, 'active');
    }
    assert.equal(clipboardCalls, 0); assert.equal(selectionCalls, 0);
});

for (const action of ['generate', 'copy']) {
    for (const status of [404, 409, 410]) {
        test(`${action} clears active credentials on confirmed invalidation ${status}`, async () => {
            globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
            globalThis.fetch = async () => reply(status, {});
            let clipboardCalls = 0, selectionCalls = 0;
            navigator.clipboard.writeText = async () => clipboardCalls++;
            const share = coursePreviewShare('/operator/preview-link', {
                state: 'active', url: 'original-capability', expires_at: '2030-01-01T00:00:00Z',
            }, { failed: 'failed', manual: 'manual' });
            share.$refs = { link: { focus: () => selectionCalls++, select: () => selectionCalls++ } };
            try {
                await share[action]();
                assert.equal(share.url, null); assert.equal(share.expires_at, null);
                assert.equal(share.state, 'unavailable'); assert.equal(share.busy, false);
                assert.equal(clipboardCalls, 0); assert.equal(selectionCalls, 0);
            } finally {
                delete globalThis.document;
            }
        });
    }
}

test('the dedicated Play button loads and plays native media before any metadata event', async () => {
    const p = player([reply(200, grantData(1))]);
    p.video.preload = 'none';
    p.button.dispatchEvent(new Event('click')); await tick();
    assert.equal(p.video.loadCalls, 1); assert.equal(p.video.preload, 'auto');
    assert.equal(p.video.src, grantData(1).playback_url);
    assert.equal(p.video.playCalls, 1); assert.equal(p.video.paused, false);
    p.video.metadata(); assert.equal(p.video.playCalls, 1);
    p.instance.destroy();
});

for (const code of [404, 410]) {
    test(`terminal ${code} replaces native controls with persistent ended guidance`, async () => {
        const p = player([reply(200, grantData(1)), reply(code, { message: 'provider response' })]);
        await p.instance.start(); await p.renew();
        assert.equal(p.video.hidden, true); assert.equal(p.button.hidden, true);
        assert.equal(p.guidance.hidden, false); assert.equal(p.guidance.textContent, 'contact sender');
        assert.equal(p.status.textContent, 'ended'); assert.equal(p.video.src, undefined); assert.equal(p.timers.size, 0);
        p.button.dispatchEvent(new Event('click')); p.video.metadata(); p.video.dispatchEvent(new Event('error')); await tick();
        assert.equal(p.calls.length, 2); assert.equal(p.video.playCalls, 1);
        assert.equal(p.video.hidden, true); assert.equal(p.button.hidden, true); assert.equal(p.guidance.hidden, false);
        assert.equal(p.status.textContent, 'ended');
    });
}

function navigationFixture(request) {
    const video = new Control(), button = new Control(), status = {}, guidance = { hidden: true };
    const root = { dataset: { endpoint: '/platform/preview/playback' }, querySelector: selector => ({ video, '[data-play]': button, '[data-status]': status, '[data-ended-guidance]': guidance }[selector]) };
    const doc = new EventTarget(), win = new EventTarget(), timers = new Map();
    doc.querySelectorAll = () => [root];
    let id = 0;
    const dependencies = { fetch: request, Hls: { isSupported: () => false }, csrf: () => 'csrf', now: () => 0,
        setTimeout: fn => { timers.set(++id, fn); return id; }, clearTimeout: id => timers.delete(id) };
    return { doc, win, root, video, button, timers, mount: () => mountPreviewPlayers(doc, win, dependencies) };
}

for (const event of ['livewire:navigating', 'pagehide']) {
    test(`${event} stops renewals and permits a clean remount of a cached preview`, async () => {
        let calls = 0;
        const p = navigationFixture(async () => { calls++; return reply(200, grantData(calls)); });
        p.mount(); p.mount();
        p.button.dispatchEvent(new Event('click')); await tick();
        assert.equal(calls, 1); assert.equal(p.timers.size, 1);
        (event === 'pagehide' ? p.win : p.doc).dispatchEvent(new Event(event));
        assert.equal(p.timers.size, 0); assert.equal(p.video.paused, true);
        assert.equal(p.video.src, undefined); assert.equal(p.root.dataset.initialized, undefined);
        p.button.dispatchEvent(new Event('click')); await tick(); assert.equal(calls, 1);
        p.mount(); p.button.dispatchEvent(new Event('click')); await tick();
        assert.equal(calls, 2); assert.equal(p.timers.size, 1);
        p.doc.dispatchEvent(new Event('livewire:navigating'));
        p.win.dispatchEvent(new Event('pagehide'));
        assert.equal(p.timers.size, 0); assert.equal(p.video.src, undefined);
    });
}

test('navigation invalidates a pending grant so a detached player cannot resume or renew', async () => {
    let resolve, calls = 0;
    const p = navigationFixture(() => { calls++; return new Promise(done => { resolve = done; }); });
    p.mount(); p.button.dispatchEvent(new Event('click'));
    p.doc.dispatchEvent(new Event('livewire:navigating'));
    resolve(reply(200, grantData(1))); await tick();
    assert.equal(p.video.src, undefined); assert.equal(p.video.playCalls, 0);
    assert.equal(p.timers.size, 0);
    p.button.dispatchEvent(new Event('click')); await tick(); assert.equal(calls, 1);
});
