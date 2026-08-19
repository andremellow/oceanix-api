<?php

namespace App\Actions\Certificates;

use App\Enums\ComplianceEventType;
use App\Models\Certificate;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CertificateRenderer;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a certificate without deleting it.
 *
 * The document stays retrievable and the verification page keeps answering — it just answers
 * "revoked". Destroying it would erase the fact that it was once issued, which is exactly
 * what an audit needs to see.
 */
class RevokeCertificate
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly CertificateRenderer $renderer,
    ) {}

    public function handle(Certificate $certificate, string $reason): Certificate
    {
        return DB::transaction(function () use ($certificate, $reason): Certificate {
            $certificate->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

            // Re-render so a downloaded copy also shows the revocation.
            $this->renderer->render($certificate->refresh());

            $this->events->record(ComplianceEventType::CertificateRevoked, $certificate->user_id, [
                'assignment_id' => $certificate->assignment_id,
                'course_version_id' => $certificate->course_version_id,
                'metadata' => ['certificate_number' => $certificate->certificate_number, 'reason' => $reason],
            ]);

            $this->audit->log('certificate.revoked', $certificate, after: ['reason' => $reason]);

            return $certificate->refresh();
        });
    }
}
