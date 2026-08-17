<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/** Baut alle Fakten vor dem eigentlichen KI-Call und liefert einen kleinen Audit-Snapshot. */
class RecipeGenerationContextService
{
    /**
     * Prompt-Whitelist: NUR semantisch bedeutsame Leitplanken landen im KI-Kontext. Steuer-/Ableitungs-
     * Keys (ki_bilder, use_favorites_list, favorites_convenience_only, bio_praeferenz, cascade_step_id,
     * auto_dependencies, _*) sind Rauschen im Prompt und werden weggelassen. Bewusst additiv gehalten —
     * spätere Achsen (pax, ziel_portion_g, saison …) hier eintragen.
     */
    private const PROMPT_KEYS = [
        'convenience', 'frische', 'bio', 'bio_pref', 'bestand', 'level', 'sektor',
        'diaet_hart', 'aroma', 'occasion', 'serviceform', 'kompositions_stil', 'ziel_vk_eur',
    ];

    /**
     * Reduziert das rohe Parameter-Bündel auf die Prompt-Whitelist und gleicht die Key-Sprache an den
     * Prompt-Text an (der Prompt nennt »niveau«/»anlass«, die kanonischen Keys heißen level/occasion).
     * So muss das Modell die Brücke nicht selbst raten.
     *
     * @param  array<string,mixed>  $parameter
     * @return array<string,mixed>
     */
    private function promptParameter(array $parameter): array
    {
        $p = array_intersect_key($parameter, array_flip(self::PROMPT_KEYS));
        if (array_key_exists('level', $p)) {
            $p['niveau'] = $p['level'];
            unset($p['level']);
        }
        if (array_key_exists('occasion', $p)) {
            $p['anlass'] = $p['occasion'];
            unset($p['occasion']);
        }

        return $p;
    }

    public function __construct(
        private KnowledgeContextService $knowledge,
        private GenerationContextService $generation,
        private RecipeTemplateService $templates,
        private TeamSettingsService $settings,
        private TokenEngine $tokens,
    ) {
    }

    public function build(Team $team, string $description, array $parameter, bool $vkModus): array
    {
        // Spec 37 (2026-08-07): Rezept-Typ explizit. Steuert (a) die typ-abhängige Niveau-Auswahl
        // (der Selektor liest params['rezept_typ']) und geht (b) als eigenes Kontext-Feld an die KI
        // — Gürtel & Hosenträger zur Prompt-Einleitung (Basisrezept = Baustein, Gericht = Teller).
        $rezeptTyp = $vkModus ? 'gericht' : 'basisrezept';
        $wissen = $this->knowledge->contextFor('ai_generate_recipe', $description, $parameter['kompositions_stil'] ?? null, [], $parameter + ['rezept_typ' => $rezeptTyp]);
        $prompt = [
            'rezept_typ' => $vkModus
                ? 'GERICHT (essfertig angerichteter Verkaufsteller — Komposition erlaubt)'
                : 'BASISREZEPT (wiederverwendbarer Baustein / EINE Komponente — kein angerichteter Teller)',
            'description' => $description,
            'parameter' => $this->promptParameter($parameter),
        ];
        if (($typ = $this->settings->kuechenTyp($team)) !== null) {
            $prompt = ['kuechen_profil' => 'Mandanten-Profil (Soft-Default): ' . TeamSettingsService::KUECHEN_TYPEN[$typ]] + $prompt;
        }
        foreach ($this->generation->forGeneration(
            $team, $description, $vkModus,
            (bool) ($parameter['use_favorites_list'] ?? false),
            (bool) ($parameter['favorites_convenience_only'] ?? false),
        ) as $key => $value) {
            $prompt[$key] = $value;
        }

        $descriptionTokens = $this->tokens->tokenize($description);
        $templateContext = $this->templates->templates($team)->map(function ($template) use ($team, $descriptionTokens) {
            $nameTokens = $this->tokens->tokenize((string) $template->name);
            $score = count(array_intersect($descriptionTokens, $nameTokens));

            return [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'score' => $score,
                'slots' => $this->templates->slotsFor($team, (int) $template->id),
            ];
        })->sortByDesc('score')->take(5)->values()->all();
        if ($templateContext !== []) {
            $prompt['rezept_templates'] = $templateContext;
        }

        // Kontext-Inspektor (2026-08-07): kompaktes, UI-fertiges Bündel „auf welches Wissen
        // greift der Generator" — gruppierte Wissens-Docs je Kanal + gematchte Templates
        // (nur score>0 = echt zur Beschreibung passend) + Zeichen-Budget des Wissens-Blocks.
        // Reine String-/Int-Listen → gefahrlos durch Job-Cache + Livewire-Ergebnis reichbar.
        $kontext = [
            'wissen' => $wissen['used_by_category'] ?? [],
            'chars' => (int) ($wissen['total_chars'] ?? 0),
            'templates' => array_values(array_map(
                fn ($t) => ['id' => $t['id'], 'name' => $t['name']],
                array_filter($templateContext, fn ($t) => ($t['score'] ?? 0) > 0),
            )),
        ];

        return [
            'prompt' => $prompt,
            'knowledge' => $wissen['block'],
            'knowledge_used' => $wissen['files_used'],
            'kontext' => $kontext,
            'snapshot' => [
                'knowledge_files' => $wissen['files_used'],
                'template_ids' => array_column($templateContext, 'id'),
                'pairing_keys' => array_values(array_filter(array_keys($prompt), fn ($key) => str_contains((string) $key, 'pair'))),
                'built_at' => now()->toIso8601String(),
            ],
        ];
    }
}
