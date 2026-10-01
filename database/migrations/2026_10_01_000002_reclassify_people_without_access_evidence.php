<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('users')->whereNotNull('company_id')->where('status', 'active')->whereNull('first_access_at')->select('company_id')->distinct()->orderBy('company_id')->pluck('company_id')->each(function ($company) {
                $count = DB::table('users')->where('company_id', $company)->where('status', 'active')->whereNull('first_access_at')->update(['status' => 'invited']);
                DB::table('audit_logs')->insert(['company_id' => $company, 'action' => 'people.legacy_access_reclassified', 'metadata' => json_encode(['count' => $count, 'migration' => '2026_10_01_000002']), 'created_at' => now()]);
            });
        });
    }

    public function down(): void
    { /* Never blindly promote invited people or destroy evidence. */
    }
};
