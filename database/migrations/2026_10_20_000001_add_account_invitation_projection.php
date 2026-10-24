<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_request_operations', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('invitation_observation_sequence')->default(0);
            $table->string('invitation_observation_hash', 64)->nullable();
            $table->string('invitation_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Invitation ownership evidence requires forward repair.');
    }
};
