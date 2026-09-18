<?php

namespace Platform\FoodAlchemist\Services\Tts;

use Illuminate\Support\Facades\Http;

/**
 * Sprachausgabe über denselben OpenAI-Zugang, den STT und LLM-Calls schon nutzen
 * (`config('services.openai.api_key')`) — kein zweiter Schlüssel, kein zweiter Dienst.
 * D8-Entscheid deckt den direkten HTTP-Zugriff (siehe {@see \Platform\FoodAlchemist\Services\Stt\OpenAiSttService}).
 *
 * TEXT-LÄNGE: der Konversations-Modus liest EINE Antwort-Turn vor, keinen Artikel —
 * 350 Zeichen sind grosszügig für einen gesprochenen Satz oder zwei. Ein längerer Text
 * deutet auf einen Fehler im Aufrufer hin (z. B. ein ganzes Tool-Ergebnis statt der
 * kuratierten Kurzantwort) und wird hart gekappt statt stillschweigend voll vorgelesen —
 * eine 30-Sekunden-Audiodatei wäre für „Sprachbefehl" die falsche Erfahrung.
 */
class OpenAiTtsService implements TtsServiceContract
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    public const MAX_ZEICHEN = 350;

    public function synthesize(string $text, ?string $stimme = null): string
    {
        $key = (string) config('services.openai.api_key');
        if ($key === '') {
            throw new \RuntimeException(
                'Kein OpenAI-Zugang konfiguriert (services.openai.api_key) — Sprachausgabe braucht ihn für die Synthese.',
            );
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Kein Text zum Vorlesen übergeben.');
        }
        if (mb_strlen($text) > self::MAX_ZEICHEN) {
            $text = mb_substr($text, 0, self::MAX_ZEICHEN);
        }

        $antwort = Http::withToken($key)
            ->timeout((int) config('foodalchemist.tts.timeout_s', 30))
            ->post(self::ENDPOINT, [
                'model' => (string) config('foodalchemist.tts.model', 'gpt-4o-mini-tts'),
                'voice' => $stimme ?: (string) config('foodalchemist.tts.voice', 'alloy'),
                'input' => $text,
                'response_format' => 'mp3',
            ]);

        if (! $antwort->successful()) {
            $grund = (string) ($antwort->json('error.message') ?? $antwort->body());

            throw new \RuntimeException('Sprachausgabe fehlgeschlagen (HTTP ' . $antwort->status() . '): '
                . mb_strimwidth($grund, 0, 200, '…'), $antwort->status());
        }

        return $antwort->body();
    }

    public function mimeType(): string
    {
        return 'audio/mpeg';
    }

    public function name(): string
    {
        return 'openai';
    }
}
