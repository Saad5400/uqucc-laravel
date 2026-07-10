<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StorePageUploadRequest;
use App\Support\Disk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PageUploadController extends Controller
{
    /**
     * Store an uploaded file where the original admin panel stored it, so
     * old and new uploads live side by side:
     *
     * - `editor` (rich-editor image attachments): media disk root with a
     *   random hashed name, like the previous rich editor's default.
     * - `quick_response` (Telegram quick-response attachments): media
     *   disk, `quick-responses/` directory, original filename preserved
     *   (as before), deduplicated with a numeric suffix instead of
     *   overwriting.
     *
     * The media disk is env-resolved (local `/storage` in dev, S3 in prod);
     * the returned `url` is always the stable public URL for the file.
     */
    public function store(StorePageUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $path = $request->validated('type') === 'quick_response'
            ? $file->storeAs('quick-responses', $this->uniqueFilename($file), ['disk' => Disk::MEDIA])
            : $file->store('', ['disk' => Disk::MEDIA]);

        return response()->json([
            'url' => Storage::disk(Disk::MEDIA)->url($path),
            'path' => $path,
        ]);
    }

    /**
     * The client's original filename (sanitized), with a `-1`, `-2`, …
     * suffix appended before the extension when the name is already taken
     * in `quick-responses/`.
     */
    protected function uniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $basename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $basename = trim(preg_replace('/[^\pL\pN\-_ ]+/u', '', $basename)) ?: 'file';

        $disk = Storage::disk(Disk::MEDIA);
        $filename = $extension === '' ? $basename : "{$basename}.{$extension}";
        $counter = 1;

        while ($disk->exists("quick-responses/{$filename}")) {
            $filename = $extension === '' ? "{$basename}-{$counter}" : "{$basename}-{$counter}.{$extension}";
            $counter++;
        }

        return $filename;
    }
}
