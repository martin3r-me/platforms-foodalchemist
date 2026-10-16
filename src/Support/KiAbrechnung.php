<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Services\Ai\AiCostCalculator;

/**
 * Die abrechnungsrelevanten Spalten einer ai_call_log-Zeile (16.10.2026): Verursacher + eingefrorener Preis/Kurs.
 * EINE Stelle für alle Schreiber (Gateway, Sprachbefehl, Bildgenerierung), damit keiner davon an der Abrechnung vorbeiläuft.
 */
final class KiAbrechnung
{
    /**
     * @param  array<string, mixed>  $usage  OpenAI-Usage (input_tokens, output_tokens, input_tokens_details.cached_tokens)
     * @param  array<string, mixed>  $verursacherOptionen  verursacher_team_id | verursacher
     * @return array<string, mixed>
     */
    public static function fuerLogZeile(string $feature, ?string $model, array $usage, array $verursacherOptionen = []): array
    {
        $snapshot = app(AiCostCalculator::class)->snapshotFuerAufruf(
            $feature, $model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );
        if ($snapshot['kosten_usd'] === null && trim((string) $model) !== '') {
            Log::warning('KI-Abrechnung: Modell ohne Preis in foodalchemist.ai.modellkosten_pro_mio_usd — Aufruf wird ohne Kosten gespeichert.', ['model' => $model, 'feature' => $feature]);
        }

        return KiVerursacher::aufloesen($verursacherOptionen) + $snapshot;
    }
}
