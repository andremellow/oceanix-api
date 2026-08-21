<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_invitation_id')->nullable()->index()->after('workos_user_id');
            $table->timestamp('invitation_sent_at')->nullable()->after('workos_invitation_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['workos_invitation_id', 'invitation_sent_at']);
        });
    }
};
