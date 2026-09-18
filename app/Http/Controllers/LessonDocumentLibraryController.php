<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Documents\LessonDocumentLibraryAccess;
use App\Services\Platform\PlatformAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonDocumentLibraryController extends Controller
{
    public function company(Company $company, string $document, LessonDocumentLibraryAccess $access): StreamedResponse
    {
        abort_unless((int) auth()->user()->company_id === (int) $company->id, 403);

        return $access->open(auth()->user(), $document);
    }

    public function platform(string $document, LessonDocumentLibraryAccess $access, PlatformAccess $platform): StreamedResponse
    {
        return $access->open($platform->authorize(), $document);
    }
}
