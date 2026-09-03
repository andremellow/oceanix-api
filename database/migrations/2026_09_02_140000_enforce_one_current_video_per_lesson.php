<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE videos SET is_current = false WHERE id NOT IN (SELECT MAX(id) FROM videos GROUP BY lesson_id)');
        DB::statement('CREATE UNIQUE INDEX videos_one_current_per_lesson ON videos (lesson_id) WHERE is_current = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS videos_one_current_per_lesson');
    }
};
