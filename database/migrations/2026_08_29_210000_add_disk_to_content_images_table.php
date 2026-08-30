<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->string('disk')->default('legacy-public')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
