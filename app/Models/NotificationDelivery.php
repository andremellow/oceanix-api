<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['notification_id', 'channel', 'status', 'attempted_at', 'failure_reason'])]
class NotificationDelivery extends Model
{
    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ScheduledNotification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(ScheduledNotification::class, 'notification_id');
    }
}
