import Hls from 'hls.js';

function shareAccessDenied(response) {
    return response.redirected || [401, 403].includes(response.status)
        || (response.url && /\/login(?:\/|$)/.test(new URL(response.url, 'http://localhost').pathname));
}

export const coursePreviewShare = (endpoint, initial, labels) => ({
    ...initial, busy: false, message: '',
    formattedExpiry() {
        return this.expires_at ? new Intl.DateTimeFormat(document.documentElement.lang, { dateStyle: 'long', timeStyle: 'long' }).format(new Date(this.expires_at)) : '';
    },
    async generate() {
        this.busy = true; this.message = '';
        try {
            const response = await fetch(endpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            if (shareAccessDenied(response)) { this.url = null; this.expires_at = null; this.state = 'denied'; this.message = labels.failed; return; }
            if ([404, 409, 410].includes(response.status)) { this.url = null; this.expires_at = null; this.state = 'unavailable'; this.message = labels.failed; return; }
            if (!response.ok) throw new Error();
            Object.assign(this, await response.json());
        } catch { this.message = labels.failed; }
        finally { this.busy = false; }
    },
    async copy() {
        try {
            const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
            if (shareAccessDenied(response)) { this.url = null; this.expires_at = null; this.state = 'denied'; this.message = labels.failed; return; }
            if ([404, 409, 410].includes(response.status)) { this.url = null; this.expires_at = null; this.state = 'unavailable'; this.message = labels.failed; return; }
            if (!response.ok) throw new Error();
            Object.assign(this, await response.json());
            if (this.state !== 'active') return;
        } catch {
            // Failure to refresh is not evidence that the previously issued link changed.
            // Keep its original expiry and selectable value; do not claim it was renewed.
            this.message = labels.failed;
            if (this.state === 'active' && this.url) {
                this.$refs.link.focus(); this.$refs.link.select(); this.message = labels.manual;
            }
            return;
        }
        try { await navigator.clipboard.writeText(this.url); this.message = labels.copied; }
        catch { this.$refs.link.focus(); this.$refs.link.select(); this.message = labels.manual; }
    },
});

export function createPreviewPlayer(root, dependencies = {}) {
    const request = dependencies.fetch ?? globalThis.fetch;
    const HlsClient = dependencies.Hls ?? Hls;
    const schedule = dependencies.setTimeout ?? globalThis.setTimeout;
    const cancel = dependencies.clearTimeout ?? globalThis.clearTimeout;
    const clock = dependencies.now ?? Date.now;
    const csrf = dependencies.csrf ?? (() => document.querySelector('meta[name="csrf-token"]').content);
    const video = root.querySelector('video');
    const button = root.querySelector('[data-play]');
    const status = root.querySelector('[data-status]');
    let hls, timer, metadata, busy = false, ended = false, wantsToPlay = false, requestGeneration = 0;
    const pause = () => { wantsToPlay = false; };
    const play = () => { wantsToPlay = true; };
    video.addEventListener('pause', pause);
    video.addEventListener('play', play);
    const stop = () => {
        cancel(timer); wantsToPlay = false;
        requestGeneration++; busy = false; button.disabled = ended;
        if (metadata) video.removeEventListener('loadedmetadata', metadata);
        video.pause(); hls?.destroy(); video.removeAttribute('src'); video.load();
    };
    async function grant() {
        if (busy || ended) return;
        const generation = ++requestGeneration;
        busy = true; button.disabled = true; status.textContent = root.dataset.loading;
        try {
            const response = await request(root.dataset.endpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
            const data = await response.json();
            if (ended || generation !== requestGeneration) return;
            if (!response.ok) {
                ended = [404, 410].includes(response.status);
                stop(); status.textContent = data.message || root.dataset.failed;
                return;
            }
            // Read position and play intent after the request: the visitor may have paused
            // or sought while authorization was in flight. Renewal never creates play intent.
            const position = video.currentTime || 0;
            if (metadata) video.removeEventListener('loadedmetadata', metadata);
            metadata = () => {
                video.currentTime = position;
                if (wantsToPlay && !ended) video.play().catch(() => {});
            };
            video.addEventListener('loadedmetadata', metadata, { once: true });
            hls?.destroy();
            if (data.playback_url.includes('.m3u8') && HlsClient.isSupported()) {
                hls = new HlsClient({ enableWorker: true });
                hls.loadSource(data.playback_url); hls.attachMedia(video);
                hls.on(HlsClient.Events.ERROR, (_event, info) => { if (info.fatal) { stop(); status.textContent = root.dataset.failed; } });
            } else video.src = data.playback_url;
            if (data.poster_url) video.poster = data.poster_url;
            status.textContent = root.dataset.ready;
            cancel(timer);
            timer = schedule(grant, Math.max(1000, new Date(data.expires_at).getTime() - clock() - 10000));
        } catch {
            if (generation === requestGeneration) { stop(); status.textContent = root.dataset.failed; }
        } finally {
            if (generation === requestGeneration) { busy = false; button.disabled = ended; }
        }
    }
    const mediaError = () => { if (!ended) { stop(); status.textContent = root.dataset.failed; } };
    video.addEventListener('error', mediaError);
    const start = () => { if (!ended) wantsToPlay = true; return grant(); };
    button.addEventListener('click', start);
    return { start, destroy() { ended = true; stop(); button.removeEventListener('click', start); video.removeEventListener('pause', pause); video.removeEventListener('play', play); video.removeEventListener('error', mediaError); } };
}

function initPreviewPlayers() {
    document.querySelectorAll('[data-course-preview-player]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const player = createPreviewPlayer(root);
        window.addEventListener('pagehide', () => player.destroy(), { once: true });
    });
}
if (typeof window !== 'undefined') window.coursePreviewShare = coursePreviewShare;
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPreviewPlayers);
    else initPreviewPlayers();
    document.addEventListener('livewire:navigated', initPreviewPlayers);
}
