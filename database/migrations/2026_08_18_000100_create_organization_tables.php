<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments and job functions are N:N with each other and with users, so a function can
 * exist in several departments and a person can hold several simultaneous links.
 * Effective dates let us reconstruct why someone was in a requirement's audience on a
 * given date. See docs/product-spec.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('job_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('department_job_function', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_function_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'job_function_id']);
        });

        Schema::create('user_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'department_id', 'starts_at']);
            $table->index(['department_id', 'ends_at']);
        });

        Schema::create('user_job_function', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_function_id')->constrained()->cascadeOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_function_id', 'starts_at']);
            $table->index(['job_function_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_job_function');
        Schema::dropIfExists('user_department');
        Schema::dropIfExists('department_job_function');
        Schema::dropIfExists('job_functions');
        Schema::dropIfExists('departments');
    }
};
