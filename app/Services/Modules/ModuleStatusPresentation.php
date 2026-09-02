<?php

namespace App\Services\Modules;

use App\Enums\ModuleStatus;
use BackedEnum;

class ModuleStatusPresentation
{
    /** @return array{value: string, label: string, modifier: string, is_archived: bool} */
    public function for(mixed $status): array
    {
        $value = $status instanceof BackedEnum ? $status->value : (string) $status;
        $moduleStatus = ModuleStatus::tryFrom($value);

        if ($moduleStatus !== null) {
            return [
                'value' => $value,
                'label' => $moduleStatus->label(),
                'modifier' => $moduleStatus->pillModifier(),
                'is_archived' => $moduleStatus === ModuleStatus::Archived,
            ];
        }

        return [
            'value' => $value,
            'label' => __(str($value)->replace('_', ' ')->title()->toString()),
            'modifier' => match ($value) {
                'published' => 'status-pill--positive',
                'retired' => 'status-pill--warning',
                default => 'status-pill--neutral',
            },
            'is_archived' => false,
        ];
    }
}
