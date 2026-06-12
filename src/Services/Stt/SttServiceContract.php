<?php

namespace Platform\FoodAlchemist\Services\Stt;

/**
 * M7-10 / D8 (Dominique, 2026-06-11): eigener sync Kurz-Audio-STT-Pfad —
 * KEIN Fremdmodul-Require, kein Core-Eingriff. Hinter Interface, damit ein
 * späterer Core-STT-Contract per Binding-Tausch übernehmen kann (D3-Fassade).
 */
interface SttServiceContract
{
    /**
     * Transkribiert KURZ-Audio (wenige Sekunden, Befehls-Profil) synchron.
     *
     * @param  string  $audioBinary  Roh-Bytes (Opus/WebM aus MediaRecorder)
     * @return string Transkript (de)
     */
    public function transcribe(string $audioBinary, string $mimeType = 'audio/webm'): string;
}
