<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Permission::withPrerequisites([Permission::CoursesGeneratePreviewLink]) as $key) {
            $permission = Permission::from($key);
            DB::table('permissions')->insertOrIgnore([
                'key' => $key,
                'label' => $permission->label(),
                'group' => $permission->group(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Catalog rows may now carry operational grants; preserve them on rollback.
    }
};
