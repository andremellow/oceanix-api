<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['is_shared', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_images');
    }
};
