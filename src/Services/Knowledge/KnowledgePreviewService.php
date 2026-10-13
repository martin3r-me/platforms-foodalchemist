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
            'gang', 'komponentenrolle', 'portionskontext', 'warengruppe', 'ausgabeform', 'bestand', 'diaet_hart', 'allergen_nogo', 'aroma', 'aroma_kueche', 'occasion',
            'serviceform', 'kompositions_stil', 'saison', 'ziel_we_pct', 'rezept_typ'];
        if (array_diff(array_keys($parameters), $allowed) !== []) {
            throw new \InvalidArgumentException('Die Vorschau akzeptiert ausschließlich fachliche Leitplanken.');
        }
        $parameters['rezept_typ'] ??= str_starts_with($promptKey, 'vk.') ? 'gericht' : 'basisrezept';
        $canonKey = $promptKey;
        if ($promptKey === 'conformance.check') {
            // Die Vorschau muss den Rollout MITMACHEN, sonst zeigt sie etwas anderes als der
            // echte Prüf-Call. Dieselbe Quelle wie dort: `ai.conformance_kanon` über den
            // stabilen Artefakt-Schlüssel. `rezept_typ` ist die Vorschau-Sprache dafür.
            $artefakt = ['basisrezept' => 'basisrezept', 'gericht' => 'vk'][$parameters['rezept_typ']] ?? null;
            $canonKey = ConformanceKnowledge::kanonKeyFuer($artefakt);
            if ($canonKey === null) {
                throw new \InvalidArgumentException('Für diesen Artefakt-Typ ist der zentrale Prüfkontext nicht eingerichtet '
                    . '(config foodalchemist.ai.conformance_kanon).');
            }
            ConformanceKnowledge::assertAvailable($team, $canonKey);
        }
        $parameters['_kanon_prompt_key'] = $canonKey;
        $retrieval = app(KnowledgeContextService::class)->contextFor($team, $promptKey, $query,
            $parameters['kompositions_stil'] ?? null, [], $parameters);
        $canon = app(AiGatewayService::class)->knowledgePreview($team, $promptKey, $retrieval, $canonKey);

        // Spec 82 §13 H: Wer Master-Wissen nicht lesen darf, sieht in der Vorschau nur die Mengen („Plattform-Wissen"), nicht Slugs/Datenwerte.
        $maskiert = WissensSichtbarkeit::aktiv() && ! WissensSichtbarkeit::darfGlobalLesen($team);
        if ($maskiert) {
            $lesbar = app(KnowledgeContextService::class)->sichtbareDokumente($team)->pluck('slug')->flip()->all();
            $nurLesbar = static fn (array $dateien) => array_values(array_filter($dateien,
                static fn ($f) => isset($lesbar[explode('@', (string) $f)[0]])));
            $verdeckt = count($retrieval['files_used']) - count($nurLesbar($retrieval['files_used']))
                + count($canon['kanon_files']) - count($nurLesbar($canon['kanon_files']));
            $retrieval['files_used'] = $nurLesbar($retrieval['files_used']);
            $canon['kanon_files'] = $nurLesbar($canon['kanon_files']);
            $retrieval['herkunft'] = array_filter((array) $retrieval['herkunft'], static fn ($k) => isset($lesbar[explode('@', (string) $k)[0]]), ARRAY_FILTER_USE_KEY);
            $retrieval['datenwerk'] = null;
        }

        return [
            'plattform_wissen' => $maskiert ? ['verdeckt' => $verdeckt, 'hinweis' => 'Plattform-Wissen wirkt im Prompt, ist für dieses Team aber nicht lesbar.'] : null,
            'prompt_key' => $promptKey,
            'canon_prompt_key' => $canonKey,
            'rezept_typ' => $parameters['rezept_typ'],
            'budget_total' => $retrieval['knowledge_budget'],
            'required_chars' => $retrieval['required_chars'],
            'datenwerk' => $retrieval['datenwerk'] ?? null,
            'retrieval' => $retrieval['files_used'], 'kanon' => $canon['kanon_files'],
            'dropped' => array_values(array_unique([...$retrieval['files_dropped'], ...$canon['kanon_dropped']])),
            'dropped_chars' => $retrieval['dropped_chars'] + $canon['dropped_chars'],
            'total_chars' => $retrieval['total_chars'] + $canon['kanon_chars'],
            'herkunft' => $retrieval['herkunft'],
        ];
    }
}
