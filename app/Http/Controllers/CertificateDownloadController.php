<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Company;
use App\Services\Certificates\CertificateRenderer;
use Symfony\Component\HttpFoundation\Response;

class CertificateDownloadController extends Controller
{
    public function __invoke(Company $company, Certificate $certificate, CertificateRenderer $renderer): Response
    {
        $this->authorize('download', $certificate);

        return response($renderer->contents($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $certificate->certificate_number),
        ]);
    }
}
