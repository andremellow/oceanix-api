<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['first_access_at', 'last_access_at', 'workos_last_sign_in_at', 'invitation_accepted_at', 'invitation_expires_at', 'invitation_revoked_at', 'invitation_verified_at', 'invitation_accepted_history_at'] as $c) {
                $t->timestampTz($c)->nullable();
            }
            $t->string('invitation_state')->nullable();
            $t->string('invitation_check_error')->nullable();
            $t->boolean('workos_active_membership')->default(false);
            $t->unsignedBigInteger('invitation_generation')->default(0);
            $t->index(['company_id', 'invitation_state']);
            $t->index(['company_id', 'first_access_at']);
        });
        foreach (['workos_sync_runs', 'workos_invitation_attempts'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $t->string('status')->default('queued');
                $t->string('reason')->nullable();
                $t->timestampTz('started_at')->nullable();
                $t->timestampTz('finished_at')->nullable();
                $t->timestampsTz();
                $t->index(['company_id', 'status']);
                if ($table === 'workos_sync_runs') {
                    foreach (['total', 'processed', 'succeeded', 'skipped', 'failed'] as $c) {
                        $t->unsignedInteger($c)->default(0);
                    }
                } else {
                    $t->foreignId('person_id')->constrained('users')->restrictOnDelete();
                    $t->string('mode');
                    $t->unsignedBigInteger('generation')->default(0);
                    $t->timestampTz('send_started_at')->nullable();
                    $t->string('resulting_invitation_id')->nullable();
                    $t->index(['company_id', 'person_id', 'status']);
                }
            });
        }
    }

    public function down(): void
    { /* Evidence is intentionally retained; operational rollback reverts application code. */
    }
};
