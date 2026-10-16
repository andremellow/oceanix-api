<?php

namespace App\Models;

use App\Services\Account\AccountUserClient;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountRequestOperation extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'user_id', 'actor_id', 'kind', 'actor_workos_user_id', 'payload', 'payload_hash', 'state', 'dispatched_at', 'receipt', 'safe_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'receipt' => 'array', 'dispatched_at' => 'datetime'];
    }

    private bool $linkingConfirmedPerson = false;

    public function linkConfirmedPerson(int $userId, array $receipt): void
    {
        if ($this->user_id !== null || ($this->payload['payload']['registration_mode'] ?? null) !== 'confirmed-first') {
            throw new LogicException('Registration linkage is immutable.');
        }
        app(AccountUserClient::class)->validateRegistrationReceipt($this->payload, $receipt);
        $this->linkingConfirmedPerson = true;
        try {
            $this->update(['user_id' => $userId, 'state' => 'mapping_pending', 'receipt' => ['registration' => $receipt]]);
        } finally {
            $this->linkingConfirmedPerson = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if (($record->isDirty('user_id') && ! ($record->linkingConfirmedPerson && $record->getOriginal('user_id') === null)) || $record->isDirty(['id', 'company_id', 'actor_id', 'kind', 'actor_workos_user_id', 'payload', 'payload_hash'])) {
                throw new LogicException('Account linkage and operation evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Operational evidence cannot be deleted.'));
    }
}
