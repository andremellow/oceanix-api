<?php

namespace App\Actions\Certificates;

use App\Enums\ComplianceEventType;
use App\Models\Certificate;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the certificate for a completed assignment.
 *
 * One certificate per assignment, so re-running completion never mints a second document.
 * Validity mirrors the requirement's recurrence: a training that renews every six months
 * produces a certificate that expires in six months. The PDF is generated separately.
 * See docs/product-spec.md §17.
 */
class IssueCertificate
{
    public function __construct(private readonly ComplianceEventRecorder $events) {}

    public function handle(UserTrainingAssignment $assignment, ?int $score = null): Certificate
    {
        $existing = $assignment->certificate;

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($assignment, $score): Certificate {
            $issuedAt = $assignment->completed_at ?? now();

            $certificate = Certificate::query()->create([
                'certificate_number' => $this->nextNumber(),
                // Unguessable public key: the verification page must not be enumerable.
                'verification_code' => Str::lower(Str::random(32)),
                'user_id' => $assignment->user_id,
                'assignment_id' => $assignment->id,
                'course_id' => $assignment->course_id,
                'course_version_id' => $assignment->course_version_id,
                'issued_at' => $issuedAt,
                'expires_at' => $this->expiryFor($assignment, $issuedAt),
                'score' => $score,
            ]);

            $this->events->record(ComplianceEventType::CertificateIssued, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'metadata' => ['certificate_number' => $certificate->certificate_number],
            ]);

            return $certificate;
        });
    }

    private function expiryFor(UserTrainingAssignment $assignment, Carbon $issuedAt): ?Carbon
    {
        $requirement = $assignment->trainingRequirement;

        if ($requirement === null || ! $requirement->frequency_type->isRecurring()) {
            return $assignment->expires_at;
        }

        return $requirement->frequency_type->advance($issuedAt, $requirement->frequency_value);
    }

    private function nextNumber(): string
    {
        $prefix = (string) config('oceanix.certificates.number_prefix', 'OCX');

        do {
            $number = sprintf('%s-%s-%s', $prefix, now()->format('Y'), Str::upper(Str::random(6)));
        } while (Certificate::query()->where('certificate_number', $number)->exists());

        return $number;
    }
}
