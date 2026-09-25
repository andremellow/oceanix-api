<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_principals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product');
            $table->string('environment');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('account_company_bindings', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_company_uuid')->unique();
            $table->foreignId('compliance_company_id')->unique()->constrained('companies');
            $table->string('workos_organization_id')->unique();
            $table->timestamps();
        });
        Schema::create('provisioning_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_principal_id')->constrained();
            $table->uuid('operation_id');
            $table->string('payload_hash', 64);
            $table->json('response');
            $table->unsignedSmallInteger('response_status');
            $table->timestamps();
            $table->unique(['service_principal_id', 'operation_id']);
        });
    }

    public function down(): void {}
};
