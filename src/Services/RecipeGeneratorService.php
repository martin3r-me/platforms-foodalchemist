<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;

/**
 * M4-14: Basisrezept-Generator — ✨ Rezept aus Beschreibung mit Richtungs-
 * Parametern + Bestand-Nutzung Hybrid (agentischer Resolver):
 *
 *   1. KI-Vorschlag (recipe.generator) → {name, beschreibung, zubereitung, zutaten[]}
 *   2. Resolver je Zutat: BESTAND ZUERST (GL-04 voll — Aliasse, Pools, Tiebreaker
 *      mit den Richtungs-Parametern als Hooks), NEUES nur für Lücken:
 *      Halbfabrikat ohne Treffer → Sub-Rezept-Stub (F4.1); Grund-Zutat ohne
 *      Treffer → unmatched (Hard-Stop-Zeile: „GP anlegen" vs „Basisrezept anlegen"
 *      per Button-Heuristik P8)
 *   3. Anlage (draft) + Zutaten-Sync + GL-02-Recompute — EIN Durchstich.
 *
 * Parameter-Mapping (A-1: Rust ist neuer als die Doku):
 *   convenience from_scratch|teil_convenience → mode=sub_recipe_first + prefer_raw
 *   frische frisch|tk|konserve → VariantPref fresh|frozen|preserved_first
 *   bio → BioPref (Default conventional — Bio nie zufällig)
 *
 * Aus-Foto/PDF blockiert auf die Martin-Vision-Frage (Offene Entscheide).
 * $kiRezeptOverride: Test-/Streaming-Pfad ab der KI-Grenze (FakeProvider ist
 * ein Kontext-Echo und kann strukturell kein Rezept erfinden — dokumentiert).
 */
class RecipeGeneratorService
{
    public function __construct(
        private AiGatewayService $ki,
        private IngredientMatchService $matcher,
        private MatchHeuristics $heuristik,
        private RecipeService $recipes,
    ) {
    }

    /**
     * @param array $parameter convenience|frische|bio|niveau|sektor|diaet_hart|aroma
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    public function generiere(Team $team, string $beschreibung, array $parameter = [], ?array $kiRezeptOverride = null): array
    {
        $kiRezept = $kiRezeptOverride;
        if ($kiRezept === null) {
            // M5-06 / GL-13: Souschef-Wissen (7 Always-Load + Domains + Pairing-Block)
            // als Fakten-Block in den User-Prompt; Stil-Filter zieht erst beim VK-
            // Generator (M6, Tabelle 4.1). Leere Wissensbasis = leerer Block, nie Fehler.
            $wissen = app(Ai\KnowledgeContextService::class)->contextFor(
                'ai_generate_recipe', $beschreibung, $parameter['kompositions_stil'] ?? null
            );
            $vorschlag = $this->ki->propose('recipe.generator', [
                'beschreibung' => $beschreibung,
                'parameter' => $parameter,
            ], ['knowledge' => $wissen['block']]);
            $kiRezept = $vorschlag->werte;
        }
        if (empty($kiRezept['name']) || empty($kiRezept['zutaten']) || ! is_array($kiRezept['zutaten'])) {
            throw new \RuntimeException('KI lieferte kein verwertbares Rezept (name + zutaten nötig) — Roh-Antwort prüfen.');
        }

        // Parameter → GL-04-Hooks (A-1: from_scratch UND teil_convenience drehen den Pool)
        $convenience = $parameter['convenience'] ?? 'standard';
        $mode = in_array($convenience, ['from_scratch', 'teil_convenience'], true) ? 'sub_recipe_first' : 'gp_first';
        $preferRaw = $convenience === 'from_scratch';
        $pref = match ($parameter['frische'] ?? null) {
            'frisch' => 'fresh_first',
            'tk' => 'frozen_first',
            'konserve' => 'preserved_first',
            default => 'fresh_first',
        };
        $bio = ($parameter['bio'] ?? false) ? 'bio' : 'conventional';        // Bio nur auf Ansage (4.4r)

        return DB::transaction(function () use ($team, $kiRezept, $parameter, $mode, $pref, $preferRaw, $bio) {
            $recipe = $this->recipes->create($team, [
                'name' => $kiRezept['name'],
                'beschreibung' => $kiRezept['beschreibung'] ?? null,
                'geschmacksrichtung' => $kiRezept['geschmacksrichtung'] ?? null,
                'fertigungstiefe' => match ($parameter['convenience'] ?? null) {
                    'from_scratch' => 'from_scratch',
                    'teil_convenience' => 'teilfertig',
                    'voll_convenience' => 'convenience',
                    default => null,
                },
            ]);
            $recipe->update([
                'zubereitung' => $kiRezept['zubereitung'] ?? null,
                'last_modified_by' => 'generator',
                'beschreibung_quelle' => ! empty($kiRezept['beschreibung']) ? 'ki' : null,
            ]);

            $statistik = ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'offen' => 0];
            $offene = [];
            $zeilen = [];
            foreach (array_values($kiRezept['zutaten']) as $i => $z) {
                $text = trim((string) ($z['text'] ?? $z['name'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $einheitId = $this->einheitId($team, (string) ($z['einheit'] ?? 'g'));
                $zeile = [
                    'raw_text' => $text,
                    'display_name' => $text,
                    'menge' => (float) ($z['menge'] ?? 1),
                    'einheit_vocab_id' => $einheitId,
                    'note' => $z['note'] ?? null,
                ];

                // Agentischer Resolver: BESTAND ZUERST (GL-04 voll, inkl. §4/§5-Aliasse)
                $treffer = $this->matcher->matchIngredient($team, $text, $z['slug'] ?? null, $mode, $pref, $preferRaw, $bio);
                if ($treffer['target'] === 'gp') {
                    $zeile['gp_id'] = $treffer['gp_id'];
                    $zeile['match_method'] = 'gemini_proposed';
                    $zeile['match_confidence'] = round($treffer['score'], 3);
                    $statistik['bestand_gp']++;
                } elseif ($treffer['target'] === 'sub_recipe') {
                    $zeile['referenced_recipe_id'] = $treffer['recipe_id'];
                    $zeile['match_method'] = 'recipe_ref';
                    $statistik['bestand_sub']++;
                } elseif ($this->heuristik->queryIstHalbfabrikat(app(Matching\TokenEngine::class)->tokenize($text))) {
                    // Lücke + Halbfabrikat ⇒ Stub anlegen (Neues NUR für Lücken)
                    $stub = $this->recipes->createSubRecipeStub($team, $this->stubName($text), $recipe->id);
                    $zeile['referenced_recipe_id'] = $stub['recipe']->id;
                    $zeile['match_method'] = 'recipe_ref';
                    $statistik['stub_neu'] += $stub['neu'] ? 1 : 0;
                    $statistik['bestand_sub'] += $stub['neu'] ? 0 : 1;
                } else {
                    // Hard-Stop-Zeile: Button-Heuristik entscheidet die primäre Aktion (P8)
                    $zeile['match_method'] = 'unmatched';
                    $statistik['offen']++;
                    $offene[] = [
                        'index' => $i,
                        'text' => $text,
                        'primaer' => $this->heuristik->istSubRezeptKandidat($text) ? 'basisrezept_anlegen' : 'gp_anlegen',
                        'shortlist' => $this->matcher->candidatesFor($team, $text, $z['slug'] ?? null, 5),
                    ];
                }
                $zeilen[] = $zeile;
            }

            $recipe = $this->recipes->syncIngredients($team, $recipe->id, $zeilen);   // inkl. Recompute

            return ['recipe' => $recipe, 'statistik' => $statistik, 'offene' => $offene];
        });
    }

    /** „500 ml brauner Kalbsfond" → Stub-Name ohne Mengen-Präfix. */
    private function stubName(string $text): string
    {
        return trim((string) preg_replace('/^[\d.,\/\s]+(g|kg|ml|l|el|tl|stk|stück|prise[n]?)?\s+/iu', '', $text)) ?: $text;
    }

    private function einheitId(Team $team, string $slug): int
    {
        $slug = mb_strtolower(trim($slug)) ?: 'g';
        $einheit = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $slug)->first()
            ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->first()
            ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->orderBy('id')->first();
        if ($einheit === null) {
            throw new \RuntimeException('Kein Einheiten-Vokabular vorhanden (M1-02 zuerst).');
        }

        return $einheit->id;
    }
}
