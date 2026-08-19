<?php

namespace App\Actions\Lgpd;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Removes a person's identifying data while preserving the compliance record.
 *
 * LGPD requires erasure of data no longer needed, but a training obligation and its evidence
 * may have to be retained for legal or regulatory defence — so the two are separated:
 * identity is destroyed, the evidence keeps existing against an unidentifiable subject.
 * See docs/product-spec.md §18.
 *
 * This is deliberately not deletion. Deleting the user would cascade the assignments and
 * destroy the proof that a training obligation was ever met.
 */
class AnonymizePerson
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $reference = 'anonymized-'.Str::lower(Str::random(12));

            $user->tokens()->delete();

            $user->forceFill([
                'name' => __('ui.anonymized_person'),
                'email' => $reference.'@anonymized.invalid',
                'employee_id' => null,
                'avatar_url' => null,
                'provider' => null,
                'provider_id' => null,
                'workos_user_id' => null,
                'password' => null,
                'remember_token' => null,
                'status' => UserStatus::Terminated,
                'terminated_at' => $user->terminated_at ?? now(),
            ])->save();

            // Free-form client data in the trail can carry identity; the structural evidence
            // (what happened, when, against which version) does not and is preserved.
            $user->complianceEvents()->update(['ip_address' => null, 'user_agent' => null]);

            $this->audit->log('person.anonymized', $user, after: ['reference' => $reference]);

            return $user->refresh();
        });
    }
}
