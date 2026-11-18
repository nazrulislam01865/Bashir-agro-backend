<?php

namespace App\Support;

use App\Models\EvidencePhoto;
use Illuminate\Support\Facades\URL;

class EvidenceUrl
{
    public static function forPhoto(EvidencePhoto $photo): string
    {
        return URL::temporarySignedRoute('evidence.show', now()->addMinutes(15), ['photo' => $photo->id]);
    }
}
