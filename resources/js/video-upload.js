/**
 * Direct-to-provider video upload for the course editor.
 *
 * The file never transits the application server: Livewire opens a one-time upload slot at
 * the provider, the browser POSTs the file straight to that URL, and only then does the
 * component mark the asset as processing. XHR (not fetch) because we want real progress.
 */
const basicUploadLimit = 200 * 1024 * 1024

export function createLessonVideoUploadState(lessonId, messages = {}, recordKey = null) {
    return {
        uploading: false,
        progress: 0,
        error: null,
        retainedFile: null,
        awaitingRetryToken: null,

        async start(event) {
            if (this.uploading) return

            const file = event.target.files?.[0]

            if (! file) {
                return
            }

            if (file.size > basicUploadLimit) {
                this.error = messages.fileTooLarge ?? 'This video is larger than 200 MB. Select a smaller file.'
                event.target.value = ''
                return
            }

            const retryToken = this.awaitingRetryToken
            this.awaitingRetryToken = null
            this.retainedFile = file
            await this.transfer(file, retryToken)
            event.target.value = ''
        },

        async retryTransfer(detail = {}) {
            if (this.uploading || Number(detail.recordId) !== Number(lessonId) || ! detail.uploadToken) return

            this.$wire?.set?.('videoLibraryOpen', true, false)

            if (! this.retainedFile) {
                this.awaitingRetryToken = detail.uploadToken
                this.error = messages.reselectFile ?? 'Choose the video file again to retry this upload.'
                const chooseFile = () => this.$refs.file?.click()
                this.$nextTick ? this.$nextTick(chooseFile) : chooseFile()
                return
            }

            await this.transfer(this.retainedFile, detail.uploadToken)
        },

        async transfer(file, retryToken = null) {
            this.error = null
            this.uploading = true
            this.progress = 0

            let uploadToken = retryToken
            let transferStarted = false
            const operation = {
                key: `media:video-upload:${recordKey ?? lessonId}`,
                kind: 'media',
                action: 'upload',
                detail: retryToken === null ? 'video-upload' : 'video-upload-retry',
                target: recordKey ?? `record:${lessonId}`,
            }
            this.$dispatch?.('oceanix:client-operation-started', operation)

            try {
                const allocation = retryToken === null
                    ? await this.$wire.requestUpload(lessonId, file.name)
                    : await this.$wire.retryUploadTransfer(lessonId, retryToken)
                const uploadUrl = typeof allocation === 'string' ? allocation : allocation.url
                uploadToken = typeof allocation === 'string' ? null : allocation.token
                if (! uploadUrl || (retryToken !== null && uploadToken !== retryToken)) {
                    throw new Error(messages.restartFailed ?? 'The video upload could not be restarted. Try again.')
                }

                transferStarted = true
                await this.send(uploadUrl, file)
                transferStarted = false
                if (uploadToken === null) {
                    await this.$wire.uploadCompleted(lessonId)
                } else {
                    await this.$wire.uploadCompleted(lessonId, uploadToken)
                }
                this.retainedFile = null
                this.awaitingRetryToken = null
            } catch (error) {
                if (transferStarted && uploadToken !== null) {
                    try {
                        await this.$wire.uploadFailed(lessonId, uploadToken)
                    } catch {
                        // Keep the original provider error visible; Livewire will revalidate on the next action.
                    }
                }
                this.error = error?.message ?? 'Upload failed. Please try again.'
                this.$dispatch?.('oceanix:client-operation-finished', { ...operation, state: 'failed', message: this.error })
            } finally {
                this.uploading = false
                if (! this.error) this.$dispatch?.('oceanix:client-operation-finished', { ...operation, state: 'succeeded' })
            }
        },

        send(url, file) {
            return new Promise((resolve, reject) => {
                const request = new XMLHttpRequest()
                const body = new FormData()

                body.append('file', file)

                request.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        this.progress = Math.round((event.loaded / event.total) * 100)
                    }
                })

                request.addEventListener('load', () => {
                    request.status >= 200 && request.status < 300
                        ? resolve()
                        : reject(new Error(`Upload rejected by the video provider (${request.status}).`))
                })

                request.addEventListener('error', () => reject(new Error('Network error during upload.')))
                request.addEventListener('abort', () => reject(new Error('Upload cancelled.')))

                request.open('POST', url)
                request.send(body)
            })
        },
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('lessonVideoUpload', createLessonVideoUploadState)

        window.Alpine.data('videoLibraryPreview', (url, poster) => ({
            hls: null,

            init() {
                const video = this.$refs.video
                video.poster = poster ?? ''

                if (url?.includes('.m3u8') && Hls.isSupported()) {
                    this.hls = new Hls({ enableWorker: true })
                    this.hls.loadSource(url)
                    this.hls.attachMedia(video)

                    return
                }

                video.src = url ?? ''
            },

            destroy() {
                this.hls?.destroy()
            },
        }))
    })
}
import Hls from 'hls.js'
