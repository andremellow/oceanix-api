<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public verification resolves by `verification_code`, never by the internal id, and the
 * public page exposes only validity, holder name, course, issue and expiry.
 */
#[RouteKey('verification_code')]
#[Fillable([
    'certificate_number', 'verification_code', 'user_id', 'assignment_id', 'course_id',
    'course_version_id', 'issued_at', 'expires_at', 'score', 'file_path',
    'revoked_at', 'revocation_reason',
])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserTrainingAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserTrainingAssignment::class, 'assignment_id');
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
