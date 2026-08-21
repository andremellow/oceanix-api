<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Company;
use App\Services\Certificates\CertificateRenderer;
use App\Tenancy\TenantContext;
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

    public readonly int $companyId;

    public function __construct(public readonly int $certificateId, ?int $companyId = null)
    {
        $this->companyId = $companyId ?? app(TenantContext::class)->id();
    }

    public function handle(CertificateRenderer $renderer): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(TenantContext::class)->set($company);

        $certificate = Certificate::query()->find($this->certificateId);

        if ($certificate === null || $renderer->exists($certificate)) {
            return;
        }

        $renderer->render($certificate);
    }
}
