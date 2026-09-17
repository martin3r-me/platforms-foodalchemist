<?php

namespace Platform\FoodAlchemist\Services\Stt;

/**
 * Spec 53 / Paket D: Ersatz für den stummen Fake-Default. Vorher fiel die Auto-Erkennung ohne
 * jeden Zugang (kein OpenAI-Key, kein AssemblyAI-Key) UND ausserhalb von testing/local auf
 * {@see FakeSttService} zurück — auf demo ohne Schlüssel hätte das JEDEN Sprachbefehl durch den
 * Fixtext ersetzt, technisch fehlerfrei und trotzdem falsch. Dieser Dienst sagt es stattdessen.
 */
class UnkonfiguriertSttService implements SttServiceContract
{
    public function transcribe(string $audioBinary, string $mimeType = 'audio/webm'): string
    {
        throw new \RuntimeException('Spracherkennung ist nicht konfiguriert — kein OpenAI- oder AssemblyAI-Zugang hinterlegt.');
    }

    public function name(): string
    {
        return 'none';
    }
}
