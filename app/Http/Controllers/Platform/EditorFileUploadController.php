<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformAccess;
use Livewire\Features\SupportFileUploads\FileUploadController;

class EditorFileUploadController extends FileUploadController
{
    public function handle()
    {
        $actor = app(PlatformAccess::class)->authorize();
        abort_unless((string) $actor->getKey() === request()->route('platformAccount'), 403);

        return parent::handle();
    }
}
