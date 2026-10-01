<?php

namespace App\Models;

use App\Enums\WorkosOperationStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkosInvitationAttempt extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['status' => WorkosOperationStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'send_started_at' => 'datetime'];
    }
}
