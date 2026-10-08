<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('compliance_access_enabled')->default(true);
            $table->unsignedBigInteger('compliance_access_version')->default(0);
        });
        Schema::create('company_access_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->uuid('operation_id')->unique();
            $table->unsignedBigInteger('sequence');
            $table->foreignId('service_principal_id')->constrained();
            $table->string('payload_hash', 64);
            $table->json('request');
            $table->json('response');
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Compliance access evidence cannot be rolled back.');
    }
};
