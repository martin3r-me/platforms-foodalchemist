<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M7-10: Sprachbefehl → agentischer Tool-Loop (Tier D) über die M8-01-Tools.
 * Schreibaktionen laufen ausschließlich über die Proposal-Tools (GL-07:
 * sprechen → Proposal → bestätigen). Liefert Antwort-Text + UI-Aktionen
 * (open) + Proposal-Hinweise fürs Frontend.
 */
class VoiceCommandService
{
    /** Tools, die der Voice-Loop nutzen darf (M8-01 + UI-Aktion). */
    public const TOOLS = [
        'foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET',
        'foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET',
        'foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.artikel.SEARCH',
        'foodalchemist.recipe_klasse.POST',
        'foodalchemist.ui.OPEN',
    ];

    public function __construct(private AiGatewayService $ki)
    {
    }

    /**
     * @return array{text: ?string, runden: int, elapsed_ms: int,
     *               aktionen: list<array>, proposals: list<array>, tool_laeufe: list<array>}
     */
    public function verarbeite(string $transcript): array
    {
        $resultat = $this->ki->callWithTools(
            "Sprachbefehl des Users (Deutsch, Kurz-Audio-Transkript): \"{$transcript}\"",
            self::TOOLS,
        );

        $aktionen = [];
        $proposals = [];
        foreach ($resultat['tool_laeufe'] as $lauf) {
            if ($lauf['name'] === 'foodalchemist.ui.OPEN' && $lauf['success']) {
                $aktionen[] = $lauf['data']['open'];
            }
            if ($lauf['name'] === 'foodalchemist.recipe_klasse.POST' && $lauf['success'] && ! ($lauf['data']['accepted'] ?? false)) {
                $proposals[] = ['type' => 'speisen_klasse', 'recipe_id' => $lauf['arguments']['recipe_id'] ?? null] + $lauf['data'];
            }
        }

        return $resultat + ['aktionen' => $aktionen, 'proposals' => $proposals];
    }
}
