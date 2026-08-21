<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

/**
 * Public certificate verification.
 *
 * Exposes only what a third party needs to trust the document: validity, holder name,
 * course, issue and expiry — never employee id, email, score breakdown, answers or training
 * history. See docs/product-spec.md §17.
 *
 * Resolution is by verification code only, and an unknown code renders the same 200 "not
 * found" page as a malformed one, so the endpoint cannot be used to enumerate certificates.
 */
class CertificateVerificationController extends Controller
{
    public function __invoke(?string $code = null): View
    {
        $certificate = $code === null ? null : Certificate::query()
            ->withoutGlobalScope('company')
            ->where('verification_code', $code)
            ->first();

        if ($certificate !== null) {
            $company = Company::query()->find($certificate->company_id);

            if ($company !== null) {
                app(TenantContext::class)->set($company);
                $certificate->load(['user:id,name', 'course:id,title']);
            } else {
                $certificate = null;
            }
        }

        return view('certificates.verify', [
            'certificate' => $certificate,
        ]);
    }
}
