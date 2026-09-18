<?php

namespace Platform\FoodAlchemist\Services\Tts;

/**
 * Spiegel von {@see \Platform\FoodAlchemist\Services\Stt\UnkonfiguriertSttService}: ohne
 * OpenAI-Zugang darf eine demo-/Produktions-Instanz nicht stillschweigend auf Fake-Audio
 * zurückfallen — sie sagt stattdessen klar, dass Vorlesen nicht konfiguriert ist.
 */
class UnkonfiguriertTtsService implements TtsServiceContract
{
    public function synthesize(string $text, ?string $stimme = null): string
    {
        throw new \RuntimeException('Sprachausgabe ist nicht konfiguriert — kein OpenAI-Zugang hinterlegt.');
    }

    public function mimeType(): string
    {
        return 'audio/mpeg';
    }

    public function name(): string
    {
        return 'none';
    }
}
