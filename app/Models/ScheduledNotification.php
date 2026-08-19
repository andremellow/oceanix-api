<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A compliance reminder scheduled for a person. Named `ScheduledNotification` to avoid
 * colliding with Illuminate\Notifications\Notification; the table stays `notifications`
 * as specified in docs/product-spec.md §19.
 */
#[Table('notifications')]
#[Fillable(['user_id', 'assignment_id', 'type', 'scheduled_for', 'sent_at', 'payload'])]
class ScheduledNotification extends Model
{
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserTrainingAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserTrainingAssignment::class, 'assignment_id');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
