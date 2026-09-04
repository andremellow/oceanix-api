<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('course_versions')
            ->select('course_id')->where('status', 'draft')->where('publication_kind', 'manual')
            ->groupBy('course_id')->havingRaw('COUNT(*) > 1')->pluck('course_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot enforce one manual course draft: duplicate drafts exist for course IDs '.$duplicates->join(', ').'. Resolve them explicitly before retrying.');
        }

        DB::statement("CREATE UNIQUE INDEX course_versions_one_manual_draft_unique ON course_versions (course_id) WHERE status = 'draft' AND publication_kind = 'manual'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS course_versions_one_manual_draft_unique');
    }
};
