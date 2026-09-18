<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([Permission::LessonDocumentsView, Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive] as $permission) {
            DB::table('permissions')->insertOrIgnore(['key' => $permission->value, 'label' => $permission->label(), 'group' => $permission->group(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Retain catalog rows and any operational grants.
    }
};
