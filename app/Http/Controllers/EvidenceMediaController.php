<?php

namespace App\Http\Controllers;

use App\Models\EvidencePhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EvidenceMediaController extends Controller
{
    public function show(EvidencePhoto $photo): BinaryFileResponse
    {
        $path = $photo->file_path;
        abort_unless(is_string($path) && str_starts_with($path, 'submissions/'), 404);
        $disk = Storage::disk('local');
        // Older evidence was stored on the public disk. Never destroy it during rollout.
        if (! $disk->exists($path)) {
            $disk = Storage::disk('public');
        }
        abort_unless($disk->exists($path), 404);
        $absolute = $disk->path($path);
        $mime = mime_content_type($absolute);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
