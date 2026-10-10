<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('account_company_uuid');
            $table->uuid('shared_person_id');
            $table->uuid('company_member_id');
            $table->json('provenance')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->unique(['account_company_uuid', 'company_member_id']);
        });
        Schema::create('person_product_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->boolean('confirmed_enabled');
            $table->unsignedBigInteger('version');
            $table->uuid('operation_id');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });
        Schema::create('product_operation_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('service_principal_id')->constrained();
            $table->uuid('operation_id');
            $table->string('subject_id');
            $table->unsignedBigInteger('resource_sequence');
            $table->string('payload_hash', 64);
            $table->json('request');
            $table->json('response');
            $table->timestamps();
            $table->unique(['service_principal_id', 'operation_id']);
        });
        Schema::create('account_request_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('kind');
            $table->string('actor_workos_user_id');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('state')->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->json('receipt')->nullable();
            $table->string('safe_error')->nullable();
            $table->timestamps();
        });
        Schema::table('service_principals', function (Blueprint $table) {
            $table->json('capabilities')->nullable();
            $table->json('company_uuids')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void {}
};
