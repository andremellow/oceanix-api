<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
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
            ->with(['user:id,name', 'course:id,title'])
            ->where('verification_code', $code)
            ->first();

        return view('certificates.verify', [
            'certificate' => $certificate,
        ]);
    }
}
