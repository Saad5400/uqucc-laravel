<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StorePageUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PageUploadController extends Controller
{
    /**
     * Store an uploaded file where the original admin panel stored it, so
     * old and new uploads live side by side:
     *
     * - `editor` (rich-editor image attachments): `public` disk root with a
     *   random hashed name, like the previous rich editor's default.
     * - `quick_response` (Telegram quick-response attachments): `public`
     *   disk, `quick-responses/` directory, original filename preserved
     *   (as before), deduplicated with a numeric suffix instead of
     *   overwriting.
     */
    public function store(StorePageUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $path = $request->validated('type') === 'quick_response'
            ? $file->storeAs('quick-responses', $this->uniqueFilename($file), ['disk' => 'public'])
            : $file->store('', ['disk' => 'public']);

        return response()->json([
            'url' => Storage::disk('public')->url($path),
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

        $disk = Storage::disk('public');
        $filename = $extension === '' ? $basename : "{$basename}.{$extension}";
        $counter = 1;

        while ($disk->exists("quick-responses/{$filename}")) {
            $filename = $extension === '' ? "{$basename}-{$counter}" : "{$basename}-{$counter}.{$extension}";
            $counter++;
        }

        return $filename;
    }
}
