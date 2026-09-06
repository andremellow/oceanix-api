<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_preview_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestampTz('generated_at');
            $table->timestampTz('expires_at');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generated_by_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
            $table->index(['course_version_id', 'expires_at', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('course_preview_links')->exists()) {
            throw new LogicException('Retain populated preview history. Disable the feature routes to roll back.');
        }
        Schema::dropIfExists('course_preview_links');
    }
};
