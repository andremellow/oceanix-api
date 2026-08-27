<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Product-language alias for the legacy `lessons` persistence model. */
class Module extends Lesson
{
    protected $table = 'lessons';

    public function versions(): HasMany
    {
        return $this->hasMany(ModuleVersion::class, 'lineage_uuid', 'lineage_uuid')->orderByDesc('version_number');
    }

    public function currentPublishedVersion(): HasOne
    {
        return $this->hasOne(ModuleVersion::class, 'lineage_uuid', 'lineage_uuid')->where('status', 'published')->latestOfMany('version_number');
    }
}
