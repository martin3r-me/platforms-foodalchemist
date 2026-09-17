<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Platform\Core\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 53 / Paket F (3): liefert die per {@see \Platform\FoodAlchemist\Services\Tts\TtsServiceContract}
 * synthetisierte Antwort als Audio aus. Der Browser kann kein Binary aus einer Livewire-
 * Response abspielen — die Bytes liegen darum kurz in Cache (Team+Token-Schlüssel, TTL aus
 * `foodalchemist.tts.audio_ttl_minuten`) und werden über diese `signed`-Route EINMAL abgeholt
 * (`Cache::pull` statt `get` — danach ist der Token verbraucht, kein zweiter Abruf möglich).
 *
 * Die Route liegt in `routes/web.php` (Modul-Auth automatisch) UND trägt `signed` — ohne
 * gültige Signatur (oder nach Ablauf/zweitem Abruf) gibt es NICHTS zurück, kein Rätselraten
 * über eine ID.
 */
class VoiceAudioController extends Controller
{
    public function stream(Request $request, string $token): Response
    {
        $eintrag = Cache::pull(self::cacheKey($token));
        abort_if(! is_array($eintrag) || ! isset($eintrag['bytes'], $eintrag['mime']), 404);

        return response((string) $eintrag['bytes'], 200, [
            'Content-Type' => (string) $eintrag['mime'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function cacheKey(string $token): string
    {
        return "voice_tts_audio:{$token}";
    }
}
