/**
 * Direct-to-provider video upload for the course editor.
 *
 * The file never transits the application server: Livewire opens a one-time upload slot at
 * the provider, the browser PUTs the file straight to that URL, and only then does the
 * component mark the asset as processing. XHR (not fetch) because we want real progress.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lessonVideoUpload', (lessonIndex) => ({
        uploading: false,
        progress: 0,
        error: null,

        async start(event) {
            const file = event.target.files?.[0]

            if (! file) {
                return
            }

            this.error = null
            this.uploading = true
            this.progress = 0

            try {
                const uploadUrl = await this.$wire.requestUpload(lessonIndex)

                await this.send(uploadUrl, file)
                await this.$wire.uploadCompleted(lessonIndex)
            } catch (error) {
                this.error = error?.message ?? 'Upload failed. Please try again.'
            } finally {
                this.uploading = false
                event.target.value = ''
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
    }))
})
