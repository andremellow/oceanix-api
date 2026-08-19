<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Services\Certificates\CertificateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rendering happens off the request: finishing a course must not wait on a PDF, and a
 * failed render must not roll back a completion that genuinely happened.
 */
class GenerateCertificateDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $certificateId) {}

    public function handle(CertificateRenderer $renderer): void
    {
        $certificate = Certificate::query()->find($this->certificateId);

        if ($certificate === null || $renderer->exists($certificate)) {
            return;
        }

        $renderer->render($certificate);
    }
}
