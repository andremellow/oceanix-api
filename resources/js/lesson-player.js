import Hls from 'hls.js'

/**
 * Lesson player.
 *
 * Two responsibilities beyond playing a file: it reports what happened as compliance events,
 * and it refuses to let the timeline be dragged past what has actually been watched. The
 * client-side block is a usability measure — the server credits progress only for playback
 * that could have happened in real time, so a bypassed player still cannot satisfy the
 * watch threshold.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lessonPlayer', ({ playbackUrl, poster, eventsUrl, playbackAuthUrl, sessionId, unlocked }) => ({
        hls: null,
        queue: [],
        sequence: 0,
        // High-water mark of playback actually reached, in seconds.
        watched: 0,
        percentage: 0,
        unlocked,
        blockedSeek: false,
        error: null,
        flushTimer: null,
        progressTimer: null,

        init() {
            this.attach(playbackUrl, poster)

            this.$refs.video.addEventListener('play', () => this.record('video.played'))
            this.$refs.video.addEventListener('pause', () => this.record('video.paused'))
            this.$refs.video.addEventListener('ended', () => {
                this.record('video.ended')
                this.flush()
            })

            this.$refs.video.addEventListener('timeupdate', () => {
                const current = this.$refs.video.currentTime

                if (current > this.watched) {
                    this.watched = current
                }
            })

            // Forward scrubbing is clamped back to what has been watched. Rewinding is free.
            this.$refs.video.addEventListener('seeking', () => {
                const current = this.$refs.video.currentTime

                if (! this.unlocked && current > this.watched + 1.5) {
                    this.$refs.video.currentTime = this.watched
                    this.blockedSeek = true
                    setTimeout(() => { this.blockedSeek = false }, 2500)

                    return
                }

                this.record('video.seeked')
            })

            this.progressTimer = setInterval(() => {
                if (! this.$refs.video.paused) {
                    this.record('video.progressed')
                }
            }, 15000)

            this.flushTimer = setInterval(() => this.flush(), 10000)

            // Leaving mid-segment must not discard the seconds since the last checkpoint,
            // so stamp the current position before sending what is queued.
            document.addEventListener('visibilitychange', () => document.hidden && this.checkpoint())
            window.addEventListener('beforeunload', () => this.checkpoint())
        },

        checkpoint() {
            if (this.$refs.video.currentTime > 0) {
                this.record('video.progressed')
            }

            this.flush()
        },

        destroy() {
            clearInterval(this.progressTimer)
            clearInterval(this.flushTimer)
            this.hls?.destroy()
        },

        attach(url, posterUrl) {
            const video = this.$refs.video

            if (posterUrl) {
                video.poster = posterUrl
            }

            if (url.includes('.m3u8')) {
                // Chrome reports "maybe" for the HLS MIME type and then fails to demux it,
                // so hls.js wins wherever it works; native playback is for Safari and iOS.
                if (! Hls.isSupported()) {
                    video.src = url

                    return
                }

                this.hls?.destroy()
                this.hls = new Hls({ enableWorker: true })
                this.hls.loadSource(url)
                this.hls.attachMedia(video)
                this.hls.on(Hls.Events.ERROR, (_event, data) => {
                    // An expired token surfaces as a fatal network error: ask for a new one.
                    if (data.fatal) {
                        this.renew()
                    }
                })

                return
            }

            video.src = url
        },

        async renew() {
            try {
                const response = await fetch(playbackAuthUrl, {
                    method: 'POST',
                    headers: this.headers(),
                })

                if (! response.ok) {
                    throw new Error('playback authorization failed')
                }

                const authorization = await response.json()
                const position = this.$refs.video.currentTime

                this.attach(authorization.url, authorization.poster)
                this.$refs.video.currentTime = position
            } catch {
                this.error = 'A sessão de vídeo expirou. Recarregue a página.'
            }
        },

        record(type) {
            this.queue.push({
                uuid: crypto.randomUUID(),
                event_type: type,
                occurred_at: new Date().toISOString(),
                position_seconds: Math.floor(this.$refs.video.currentTime),
                client_sequence: this.sequence++,
                session_id: sessionId,
            })

            if (this.queue.length >= 20) {
                this.flush()
            }
        },

        async flush() {
            if (this.queue.length === 0) {
                return
            }

            const batch = this.queue
            this.queue = []

            try {
                const response = await fetch(eventsUrl, {
                    method: 'POST',
                    headers: this.headers(),
                    body: JSON.stringify({ events: batch }),
                    keepalive: true,
                })

                if (! response.ok) {
                    throw new Error('ingest failed')
                }

                const result = await response.json()
                this.percentage = result.percentage_watched

                if (result.assessment_unlocked && ! this.unlocked) {
                    this.unlocked = true
                    this.$wire.$refresh()
                }
            } catch {
                // Losing a batch must not lose the evidence: the same UUIDs are retried, and
                // ingestion is idempotent, so a replay inserts nothing twice.
                this.queue = [...batch, ...this.queue]
            }
        },

        headers() {
            return {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            }
        },
    }))
})
