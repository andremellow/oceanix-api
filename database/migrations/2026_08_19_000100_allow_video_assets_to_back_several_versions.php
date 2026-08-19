<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider asset legitimately backs more than one row.
 *
 * Cloning a published version into a new draft reuses the same Cloudflare asset instead of
 * re-uploading and re-encoding it, so `(provider, provider_asset_id)` is not unique — it is
 * only a lookup key. The original unique index made version cloning impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_asset_id']);
            $table->index(['provider', 'provider_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_asset_id']);
            $table->unique(['provider', 'provider_asset_id']);
        });
    }
};
