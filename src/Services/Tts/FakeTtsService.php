<?php

namespace Platform\FoodAlchemist\Services\Tts;

/** Sandbox/Test-TTS — liefert feste Bytes (kein Netz, kein Key). */
class FakeTtsService implements TtsServiceContract
{
    public function synthesize(string $text, ?string $stimme = null): string
    {
        return 'FAKE-MP3:' . $text;
    }

    public function mimeType(): string
    {
        return 'audio/mpeg';
    }

    public function name(): string
    {
        return 'fake';
    }
}
