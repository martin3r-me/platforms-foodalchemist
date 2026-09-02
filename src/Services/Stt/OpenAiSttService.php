<?php

namespace Platform\FoodAlchemist\Services\Stt;

use Illuminate\Support\Facades\Http;

/**
 * Kurz-Audio-Transkription über den OpenAI-Zugang, den die Plattform SCHON hat
 * (`config('services.openai.api_key')` — dieselbe Quelle, aus der der Core seine
 * LLM-/Embedding-Calls speist). Damit braucht der Sprachbefehl KEINEN zweiten
 * Dienst und keinen zweiten Schlüssel.
 *
 * Warum direkter HTTP und nicht über den Core-Contract: der Core hat keine
 * Transkription — sein LLM-Contract ist `chat()`. Eine hinzuzufügen wäre ein
 * Fremdmodul-Eingriff. Der D8-Entscheid deckt genau diesen Fall ab (Docblock in
 * {@see AssemblyAiSttService}: „Direkter HTTP ist per D8-Entscheid gedeckt — die
 * D3-Regel betrifft NUR den LLM-Transport"). Der Schlüssel kommt trotzdem aus der
 * Plattform-Config, nicht aus einer modul-eigenen — sonst liefe es lokal und auf
 * der Plattform nicht.
 *
 * DER VOKABULAR-PROMPT ist hier kein Beiwerk. Die Transkriptions-API nimmt einen
 * `prompt` als Kontext-Hinweis, und deutsche Küchen-/FA-Begriffe („Basisrezept",
 * „Grundprodukt", „Aufschlagsklasse", „Concepter") sind genau die Wörter, an denen
 * ein allgemeines Modell scheitert. Ohne diesen Hinweis wird aus „Grundprodukt"
 * schnell „Grund Produkt" und der nachgelagerte Tool-Loop sucht ins Leere.
 */
class OpenAiSttService implements SttServiceContract
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    /** OpenAI leitet das Format aus dem Dateinamen ab — Mime → Endung. */
    private const ENDUNGEN = [
        'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
        'audio/mp4' => 'mp4', 'audio/m4a' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac',
    ];

    /** Hartes Limit der API (25 MB) — vorher abfangen, damit die Meldung verständlich bleibt. */
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function transcribe(string $audioBinary, string $mimeType = 'audio/webm'): string
    {
        $key = (string) config('services.openai.api_key');
        if ($key === '') {
            throw new \RuntimeException(
                'Kein OpenAI-Zugang konfiguriert (services.openai.api_key) — Sprachbefehl braucht ihn für die Transkription.',
            );
        }
        if ($audioBinary === '') {
            throw new \RuntimeException('Aufnahme war leer — nichts zu transkribieren.');
        }
        if (strlen($audioBinary) > self::MAX_BYTES) {
            throw new \RuntimeException('Aufnahme zu lang (über 25 MB) — der Sprachbefehl ist für kurze Befehle gedacht.');
        }

        // Basis-Mime: Browser liefern „audio/webm;codecs=opus".
        $basis = strtolower(trim(explode(';', $mimeType)[0]));
        $endung = self::ENDUNGEN[$basis] ?? 'webm';

        $felder = [
            'model' => (string) config('foodalchemist.stt.model', 'gpt-4o-mini-transcribe'),
            'language' => (string) config('foodalchemist.stt.language', 'de'),
            'response_format' => 'json',
        ];
        $vokabular = trim((string) config('foodalchemist.stt.vokabular_prompt', ''));
        if ($vokabular !== '') {
            $felder['prompt'] = $vokabular;
        }

        $antwort = Http::withToken($key)
            ->timeout((int) config('foodalchemist.stt.timeout_s', 30))
            ->attach('file', $audioBinary, 'befehl.' . $endung, ['Content-Type' => $basis])
            ->asMultipart()
            ->post(self::ENDPOINT, $felder);

        if (! $antwort->successful()) {
            // Die API-Meldung mitgeben, aber gekappt — sie landet im UI-Fehlerfeld.
            $grund = (string) ($antwort->json('error.message') ?? $antwort->body());

            throw new \RuntimeException('Transkription fehlgeschlagen (HTTP ' . $antwort->status() . '): '
                . mb_strimwidth($grund, 0, 200, '…'));
        }

        $text = trim((string) ($antwort->json('text') ?? ''));

        return $vokabular === '' ? $text : self::ohneVokabularEcho($text, $vokabular);
    }

    /**
     * PROMPT-ECHO abfangen. Enthält die Aufnahme keine (oder kaum) Sprache, geben
     * Whisper-artige Modelle den Kontext-Hinweis als Transkript zurück — live gegen
     * demo mit 1 s Stille beobachtet: die Antwort war wörtlich die Vokabelliste.
     * Ungefiltert bekäme der Tool-Loop diese Liste als Befehl.
     *
     * Kein Unit-Test hätte das gefunden: der Fake-HTTP-Test liefert genau den Text,
     * den man erwartet. Nur der echte Durchlauf zeigt es.
     *
     * Drei Fälle, absichtlich in dieser Reihenfolge:
     *   1. Das Transkript IST der Hinweis  → leer.
     *   2. Es BEGINNT mit dem Hinweis      → Präfix abschneiden, Rest ist echte Sprache.
     *   3. Es ist ein längerer ANFANG des Hinweises (abgeschnittenes Echo) → leer.
     * Ein echter Befehl ist nie ein Anfangsstück der Vokabelliste, darum ist (3) sicher.
     */
    private static function ohneVokabularEcho(string $text, string $vokabular): string
    {
        $norm = static fn (string $v): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower($v)) ?? '');
        $t = $norm($text);
        $v = $norm($vokabular);
        if ($t === '' || $v === '') {
            return $text;
        }
        if ($t === $v) {
            return '';
        }
        if (str_starts_with($t, $v)) {
            // Rest im ORIGINAL abschneiden (Länge stimmt bis auf Whitespace-Normalisierung
            // nicht exakt) — darum über die Wortzahl des Hinweises gehen.
            $worte = preg_split('/\s+/u', trim($text)) ?: [];
            $rest = implode(' ', array_slice($worte, count(preg_split('/\s+/u', $v) ?: [])));

            return trim($rest);
        }
        if (mb_strlen($t) >= 40 && str_starts_with($v, $t)) {
            return '';
        }

        return $text;
    }
}
