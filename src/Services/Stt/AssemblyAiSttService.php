<?php

namespace Platform\FoodAlchemist\Services\Stt;

use Illuminate\Support\Facades\Http;

/**
 * M7-10 / D8: synchroner Kurz-Audio-Pfad gegen AssemblyAI — Muster aus
 * platforms-whisper (upload → submit → poll), aber Befehls-Profil: kurzes
 * Poll-Intervall, ohne Diarization, language de. Direkter HTTP ist per
 * D8-Entscheid gedeckt (die D3-Regel betrifft NUR den LLM-Transport).
 * Deploy braucht ASSEMBLYAI_API_KEY (Martin) — Sandbox nutzt FakeStt.
 */
class AssemblyAiSttService implements SttServiceContract
{
    private const BASE = 'https://api.assemblyai.com/v2';

    public function transcribe(string $audioBinary, string $mimeType = 'audio/webm'): string
    {
        $key = (string) config('foodalchemist.stt.key');
        if ($key === '') {
            throw new \RuntimeException('ASSEMBLYAI_API_KEY fehlt (foodalchemist.stt.key) — Deploy-Rest bei Martin (D8).');
        }

        $upload = Http::withHeaders(['authorization' => $key])
            ->withBody($audioBinary, $mimeType)
            ->post(self::BASE . '/upload')->throw()->json();

        $transcript = Http::withHeaders(['authorization' => $key])
            ->post(self::BASE . '/transcript', [
                'audio_url' => $upload['upload_url'],
                'language_code' => 'de',
                'speaker_labels' => false,                            // Befehls-Profil: keine Diarization
            ])->throw()->json();

        // Kurz-Audio: enges Poll-Intervall (≪ 3 s der Meeting-Pipeline), hartes Zeit-Limit
        $deadline = microtime(true) + (int) config('foodalchemist.stt.timeout_s', 30);
        while (microtime(true) < $deadline) {
            $status = Http::withHeaders(['authorization' => $key])
                ->get(self::BASE . "/transcript/{$transcript['id']}")->throw()->json();
            if ($status['status'] === 'completed') {
                return (string) $status['text'];
            }
            if ($status['status'] === 'error') {
                throw new \RuntimeException('STT-Fehler: ' . ($status['error'] ?? 'unbekannt'));
            }
            usleep(400_000);
        }

        throw new \RuntimeException('STT-Timeout (Kurz-Audio-Profil) — Audio zu lang?');
    }
}
