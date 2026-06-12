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
    public function generiere(Team $team, string $beschreibung, array $parameter = [], ?array $kiRezeptOverride = null, bool $vkModus = false): array
    {
        $kiRezept = $kiRezeptOverride;
        if ($kiRezept === null) {
            // M5-06 / GL-13: Souschef-Wissen (7 Always-Load + Domains + Pairing-Block)
            // als Fakten-Block in den User-Prompt; Stil-Filter (Achse 10) zieht im
            // VK-Modus über kompositions_stil. Leere Wissensbasis = leer, nie Fehler.
            $wissen = app(Ai\KnowledgeContextService::class)->contextFor(
                'ai_generate_recipe', $beschreibung, $parameter['kompositions_stil'] ?? null
            );
            $kontext = [
                'beschreibung' => $beschreibung,
                'parameter' => $parameter,
            ];
            // M6-07 / V-04 (Audit-Hebel 3): Reuse-at-Generation — lexikalischer
            // Prefetch des Bestands VOR der Benennung; die KI soll vorhandene
            // Basisrezepte EXAKT so benennen (billiger als Nach-Matching).
            $inventar = $this->bestandsInventar($team, $beschreibung);
            if ($inventar !== []) {
                $kontext['bestands_inventar'] = $inventar;
            }
            if ($vkModus) {
                // M6-06: VK-Achsen + Taxonomie-Vorrat für Klasse/AK-Vorschlag
                $kontext['speisen_klassen'] = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::query()
                    ->join('foodalchemist_dish_main_groups AS hg', 'hg.id', '=', 'foodalchemist_dish_classes.dish_main_group_id')
                    ->selectRaw("foodalchemist_dish_classes.id AS id, hg.code || ' / ' || foodalchemist_dish_classes.bezeichnung AS label")
                    ->orderBy('foodalchemist_dish_classes.id')->pluck('label', 'id')->all();
                $kontext['aufschlagsklassen'] = \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::where('is_inactive', false)
                    ->orderBy('code')->pluck('bezeichnung', 'code')->all();
            }
            $vorschlag = $this->ki->propose($vkModus ? 'vk.generator' : 'recipe.generator', $kontext, [
                'knowledge' => $wissen['block'],
                'knowledge_used' => $wissen['files_used'],            // M7-01: GL-13-§6-Audit-Lücke geschlossen
                // M7-03 §3.3 (Ist: commands.rs:20766-20780): valides JSON ohne
                // name/zutaten ist strukturell unbrauchbar → Gateway re-rollt
                'structural_retry' => fn (array $parsed) => ! empty($parsed['werte']['name']) && ! empty($parsed['werte']['zutaten']),
            ]);
            $kiRezept = $vorschlag->werte;
        }
        if (empty($kiRezept['name']) || empty($kiRezept['zutaten']) || ! is_array($kiRezept['zutaten'])) {
            throw new \RuntimeException('KI lieferte kein verwertbares Rezept (name + zutaten nötig) — Roh-Antwort prüfen.');
        }

        // Parameter → GL-04-Hooks (A-1: from_scratch UND teil_convenience drehen den Pool)
        // VK-Modus: Komponenten = Basisrezepte zuerst (D-6 — Zutaten sind GPs UND/ODER Basisrezepte)
        $convenience = $parameter['convenience'] ?? 'standard';
        $mode = $vkModus || in_array($convenience, ['from_scratch', 'teil_convenience'], true) ? 'sub_recipe_first' : 'gp_first';
        $preferRaw = $convenience === 'from_scratch';
        $pref = match ($parameter['frische'] ?? null) {
            'frisch' => 'fresh_first',
            'tk' => 'frozen_first',
            'konserve' => 'preserved_first',
            default => 'fresh_first',
        };
        $bio = ($parameter['bio'] ?? false) ? 'bio' : 'conventional';        // Bio nur auf Ansage (4.4r)

        return DB::transaction(function () use ($team, $kiRezept, $parameter, $mode, $pref, $preferRaw, $bio, $vkModus) {
            $recipe = $this->recipes->create($team, [
                'name' => $kiRezept['name'],
                'ist_verkaufsrezept' => $vkModus,
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
                'last_modified_by' => $vkModus ? 'vk_generator' : 'generator',
                'beschreibung_quelle' => ! empty($kiRezept['beschreibung']) ? 'ki' : null,
            ]);

            // M6-06: Klasse/AK aus dem Vorschlag — beides validiert, Lineage ki (GL-07)
            if ($vkModus) {
                $klasse = isset($kiRezept['speisen_klasse_id'])
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::find((int) $kiRezept['speisen_klasse_id'])
                    : null;
                $ak = isset($kiRezept['aufschlagsklasse_code'])
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::where('code', $kiRezept['aufschlagsklasse_code'])->first()
                    : null;
                $recipe->update(array_filter([
                    'speisen_klasse_id' => $klasse?->id,
                    'speisen_klasse_quelle' => $klasse !== null ? 'ki' : null,
                    'aufschlagsklasse_id' => $ak?->id ?? $klasse?->default_markup_class_id,
                    'mwst_satz' => $ak?->mwst_satz,
                ], fn ($v) => $v !== null));
            }

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

    /**
     * V-04: Top-Bestands-Kandidaten zur Beschreibung (Token-LIKE über die
     * Basisrezept-Namen, approved zuerst) — als »benenne EXAKT so«-Inventar.
     *
     * @return list<string>
     */
    private function bestandsInventar(Team $team, string $beschreibung, int $limit = 30): array
    {
        $tokens = array_values(array_filter(
            app(Matching\TokenEngine::class)->tokenize($beschreibung),
            fn ($t) => mb_strlen($t) >= 4,
        ));
        if ($tokens === []) {
            return [];
        }

        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereIn('status', ['draft', 'review', 'approved'])
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $t) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $t . '%']);
                }
            })
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'review' THEN 1 ELSE 2 END")
            ->orderBy('name')->limit($limit)->pluck('name')->all();
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
