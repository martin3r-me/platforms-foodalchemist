<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/** Read-only-Vorschau für UI und MCP; benutzt die echte Auswahl, keine zweite Simulation. */
class KnowledgePreviewService
{
    public function preview(Team $team, string $promptKey, string $query, array $parameters = []): array
    {
        if (! array_key_exists($promptKey, (array) config('foodalchemist.prompts', []))) {
            throw new \InvalidArgumentException('Bitte einen verfügbaren Arbeitsschritt wählen.');
        }
        if (trim($query) === '') {
            throw new \InvalidArgumentException('Bitte einen Auftrag für die Vorschau eingeben.');
        }
        $allowed = ['niveau', 'level', 'sektor', 'convenience', 'frische', 'bio', 'bio_pref',
            'bestand', 'diaet_hart', 'allergen_nogo', 'aroma', 'aroma_kueche', 'occasion',
            'serviceform', 'kompositions_stil', 'saison', 'ziel_we_pct', 'rezept_typ'];
        if (array_diff(array_keys($parameters), $allowed) !== []) {
            throw new \InvalidArgumentException('Die Vorschau akzeptiert ausschließlich fachliche Leitplanken.');
        }
        $parameters['rezept_typ'] ??= str_starts_with($promptKey, 'vk.') ? 'gericht' : 'basisrezept';
        $parameters['_kanon_prompt_key'] = $promptKey;
        $retrieval = app(KnowledgeContextService::class)->contextFor($team, $promptKey, $query,
            $parameters['kompositions_stil'] ?? null, [], $parameters);
        $canon = app(AiGatewayService::class)->knowledgePreview($team, $promptKey, $retrieval);

        return [
            'prompt_key' => $promptKey,
            'retrieval' => $retrieval['files_used'], 'kanon' => $canon['kanon_files'],
            'dropped' => array_values(array_unique([...$retrieval['files_dropped'], ...$canon['kanon_dropped']])),
            'dropped_chars' => $retrieval['dropped_chars'] + $canon['dropped_chars'],
            'total_chars' => $retrieval['total_chars'] + $canon['kanon_chars'],
            'herkunft' => $retrieval['herkunft'],
        ];
    }
}
