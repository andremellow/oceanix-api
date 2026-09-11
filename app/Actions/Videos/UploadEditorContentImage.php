<?php

namespace App\Actions\Videos;

use App\Models\Account;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Throwable;

final class UploadEditorContentImage
{
    public function handle(UploadedFile $upload, ?Course $course = null, ?User $actor = null, ?Account $platformActor = null): ContentImage
    {
        Validator::make(['image' => $upload], ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']])->validate();
        if ($platformActor !== null) {
            $authorized = Account::query()->whereKey($platformActor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can upload shared content images.');
            }
            $companyId = null;
            $shared = true;
        } else {
            abort_unless($course !== null && $actor !== null, 403);
            $course = Course::query()->whereKey($course->id)->whereNotNull('company_id')->where('is_shared', false)->firstOrFail();
            Gate::forUser($actor)->authorize('update', $course);
            $companyId = $course->company_id;
            $shared = false;
        }

        $disk = (string) config('filesystems.content_images_disk', 'public');
        $path = $upload->store((string) config('filesystems.content_images_path', 'content-images'), $disk);
        abort_if($path === false, 500);

        try {
            return DB::transaction(fn (): ContentImage => ContentImage::query()->create([
                'company_id' => $companyId,
                'is_shared' => $shared,
                'name' => $upload->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $upload->getSize(),
            ]));
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
