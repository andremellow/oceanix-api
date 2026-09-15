<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_shared');
            $table->string('name');
            $table->string('disk');
            $table->string('path')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
        Schema::create('lesson_document', function (Blueprint $table): void {
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_document_id')->constrained('lesson_documents')->restrictOnDelete();
            $table->primary(['lesson_id', 'lesson_document_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('lesson_documents')->exists()) {
            throw new LogicException('Retained lesson documents cannot be removed by rollback.');
        }
        Schema::dropIfExists('lesson_document');
        Schema::dropIfExists('lesson_documents');
    }
};
