<?php

namespace Platform\FoodAlchemist\Services\Tts;

/**
 * Spec 53 / Paket F (3): Sprachausgabe für den Konversations-Modus — eigener sync
 * Kurz-Text-TTS-Pfad, gleiches Muster wie {@see \Platform\FoodAlchemist\Services\Stt\SttServiceContract}
 * (D8: direkter HTTP-Zugriff auf den Fremddienst ist gedeckt, der Core hat keinen
 * TTS-Contract). Hinter Interface, damit ein späterer Core-Contract per Binding-Tausch
 * übernehmen kann.
 */
interface TtsServiceContract
{
    /**
     * Synthetisiert KURZ-Text (eine Antwort-Turn, kein ganzer Artikel) synchron zu Audio.
     *
     * @param  string  $text  Der vorzulesende Text (de)
     * @param  string|null  $stimme  Stimmen-ID des Providers; null = Provider-Default
     * @return string Roh-Audio-Bytes (MP3)
     */
    public function synthesize(string $text, ?string $stimme = null): string;

    /** Mime-Typ des von {@see synthesize()} gelieferten Audios. */
    public function mimeType(): string;

    /**
     * Provider-Transparenz analog zu `SttServiceContract::name()`:
     * `openai`|`fake`|`none`.
     */
    public function name(): string;
}
