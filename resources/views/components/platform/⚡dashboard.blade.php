<?php

use App\Services\Platform\PlatformAccess;
use App\Services\Platform\PlatformOverview;
use Oceanix\Platform\Livewire\PlatformDashboard;

new class extends PlatformDashboard
{
    public function with(): array
    {
        return parent::with() + [
            'recentCompanies' => app(PlatformOverview::class)
                ->companies(app(PlatformAccess::class)->authorize()->id)->take(8),
        ];
    }
};
?>

<div></div>
