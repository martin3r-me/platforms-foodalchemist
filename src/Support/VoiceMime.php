<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Http\UploadedFile;

/**
 * Spec 53 / Paket D: welchen Mime-Typ bekommt die Transkription? `getClientMimeType()` ist das,
 * was der Browser selbst als `Blob`/`File`-Typ gesetzt hat (bei unserem Recorder-Baustein:
 * `recorder.mimeType`, siehe resources/js/voice-recorder) — zuverlässiger als `getMimeType()`
 * (Server-Sniffing per Fileinfo), das bei sehr kurzen Audio-Containern öfter auf einen
 * generischen Typ zurückfällt und dann die falsche Datei-Endung erzeugt.
 */
class VoiceMime
{
    public static function aufgeloest(UploadedFile $file): string
    {
        $client = strtolower(trim((string) $file->getClientMimeType()));
        if (str_starts_with($client, 'audio/') || str_starts_with($client, 'video/')) {
            return $client;
        }

        return strtolower(trim((string) ($file->getMimeType() ?: 'audio/webm')));
    }
}
