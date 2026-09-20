<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $id = DB::getDriverName() === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        DB::statement("CREATE TABLE lesson_document_archives (
            id {$id},
            lesson_document_id BIGINT NOT NULL UNIQUE REFERENCES lesson_documents(id) ON DELETE RESTRICT,
            archived_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE RESTRICT,
            archived_by_account_id BIGINT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
            archived_at TIMESTAMP NOT NULL,
            CONSTRAINT lesson_document_archive_one_actor CHECK (
                (archived_by_user_id IS NOT NULL AND archived_by_account_id IS NULL) OR
                (archived_by_user_id IS NULL AND archived_by_account_id IS NOT NULL)
            )
        )");
    }

    public function down(): void
    {
        if (DB::table('lesson_document_archives')->exists()) {
            throw new LogicException('Document archive evidence must be retained.');
        }
        Schema::dropIfExists('lesson_document_archives');
    }
};
