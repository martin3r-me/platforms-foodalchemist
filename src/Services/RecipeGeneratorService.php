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
 *   1. KI-Vorschlag (recipe.generator) → {name, description, preparation, zutaten[]}
 *   2. Resolver je Zutat: BESTAND ZUERST (GL-04 voll — Aliasse, Pools, Tiebreaker
 *      mit den Richtungs-Parametern als Hooks), NEUES nur für Lücken:
 *      Treffer bleiben offen: Halbfabrikat → Basisrezept bestätigen/anlegen;
 *      Grund-Zutat → Lieferantenartikel wählen, danach GP bestätigen/anlegen.
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
     * @param array $parameter convenience|frische|bio|niveau|sektor|diaet_hart|aroma|use_favorites_list
     * @param ?string $createdVia 03·L5: Herkunfts-Lineage am Rezept (mcp bei MCP-Aufruf).
     *                            Default null = byte-identisches Verhalten der UI-Pfade.
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    public function generiere(Team $team, string $description, array $parameter = [], ?array $kiRezeptOverride = null, bool $vkModus = false, ?string $createdVia = null): array
    {
        $kiRezept = $kiRezeptOverride;
        if ($kiRezept === null) {
            // M5-06 / GL-13: Souschef-Wissen (7 Always-Load + Domains + Pairing-Block)
            // als Fakten-Block in den User-Prompt; Stil-Filter (Achse 10) zieht im
            // VK-Modus über kompositions_stil. Leere Wissensbasis = leer, nie Fehler.
            $wissen = app(Ai\KnowledgeContextService::class)->contextFor(
                'ai_generate_recipe', $description, $parameter['kompositions_stil'] ?? null
            );
            $kontext = [
                'description' => $description,
                'parameter' => $parameter,
            ];
            // M7-07: Küchen-Profil VOR den Hooks (Soft-Default-Schicht,
            // commands.rs:12590-Pendant) — explizite Richtungs-Parameter gewinnen
            $kuechenTyp = app(TeamSettingsService::class)->kuechenTyp($team);
            if ($kuechenTyp !== null) {
                $kontext = ['kuechen_profil' => 'Mandanten-Profil (Soft-Default — explizite '
                    . 'Richtungs-Parameter haben VORRANG): '
                    . TeamSettingsService::KUECHEN_TYPEN[$kuechenTyp]] + $kontext;
            }
            // M6-07 / V-04 (Audit-Hebel 3): Reuse-at-Generation — lexikalischer
            // Prefetch des Bestands VOR der Benennung; die KI soll vorhandene
            // Basisrezepte EXAKT so benennen (billiger als Nach-Matching).
            $inventar = $this->bestandsInventar($team, $description);
            if ($inventar !== []) {
                $kontext['bestands_inventar'] = $inventar;
            }
            if ($vkModus) {
                // M6-06: VK-Achsen + Taxonomie-Vorrat für Klasse/AK-Vorschlag
                $kontext['speisen_klassen'] = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::query()
                    ->join('foodalchemist_dish_main_groups AS hg', 'hg.id', '=', 'foodalchemist_dish_classes.dish_main_group_id')
                    ->selectRaw("foodalchemist_dish_classes.id AS id, CONCAT(hg.code, ' / ', foodalchemist_dish_classes.label) AS label")
                    ->orderBy('foodalchemist_dish_classes.id')->pluck('label', 'id')->all();
                $kontext['aufschlagsklassen'] = \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::where('is_inactive', false)
                    ->orderBy('code')->pluck('label', 'code')->all();
            }
            // #505 Slice 1: hybrides Grounding — reale GP-/Rezept-Kandidaten + Anker-Graph-
            // Pairing (rollenabhängig) additiv in den Prompt, damit die KI auf EXISTIERENDE
            // GPs benennt (weniger Drift) statt Namen zu erfinden. Food DNA injiziert propose() selbst.
            // 06·H3: opt-in Favoriten (Default aus → byte-identisch); H4b: optional nur Convenience-Favoriten
            $useFavoritesList = (bool) ($parameter['use_favorites_list'] ?? false);
            $favoritesConvenienceOnly = (bool) ($parameter['favorites_convenience_only'] ?? false);
            foreach (app(GenerationContextService::class)->forGeneration($team, $description, $vkModus, $useFavoritesList, $favoritesConvenienceOnly) as $gKey => $gVal) {
                $kontext[$gKey] = $gVal;
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

        return DB::transaction(function () use ($team, $kiRezept, $parameter, $mode, $pref, $preferRaw, $bio, $vkModus, $createdVia) {
            $recipe = $this->recipes->create($team, [
                'name' => $kiRezept['name'],
                'is_sales_recipe' => $vkModus,
                'created_via' => $createdVia,
                'description' => $kiRezept['description'] ?? null,
                // Enum-Guard: taste_direction ist die grobe Menüplanungs-Richtung (suess|herzhaft|neutral,
                // varchar(16)) — nicht das Aroma-Profil. Ein Generator-Freitext ("cremig-süßlich, …") lebt
                // in description; hier nur den Enum-Wert durchlassen, sonst null (crasht sonst den Insert).
                'taste_direction' => in_array($kiRezept['taste_direction'] ?? null, ['suess', 'herzhaft', 'neutral'], true)
                    ? $kiRezept['taste_direction']
                    : null,
                'production_depth' => match ($parameter['convenience'] ?? null) {
                    'from_scratch' => 'from_scratch',
                    'teil_convenience' => 'teilfertig',
                    'voll_convenience' => 'convenience',
                    default => null,
                },
            ]);
            $recipe->update([
                'preparation' => $kiRezept['preparation'] ?? null,
                'last_modified_by' => $vkModus ? 'vk_generator' : 'generator',
                'description_source' => ! empty($kiRezept['description']) ? 'ki' : null,
            ]);

            // M6-06: Klasse/HG/AK aus dem Vorschlag — validiert, Lineage ki (GL-07).
            // Modell A (Regelwerk_Verkaufsgerichte v1.1): Klasse = Diätform; die Hauptgruppe
            // ist die Kategorie und trägt den Aufschlag-Default (nicht mehr die Klasse).
            if ($vkModus) {
                $klasse = isset($kiRezept['dish_class_id'])
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::find((int) $kiRezept['dish_class_id'])
                    : null;
                $hg = isset($kiRezept['dish_main_group_id'])
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::find((int) $kiRezept['dish_main_group_id'])
                    : null;
                $ak = isset($kiRezept['aufschlagsklasse_code'])
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::where('code', $kiRezept['aufschlagsklasse_code'])->first()
                    : null;
                $recipe->update(array_filter([
                    'dish_class_id' => $klasse?->id,
                    'dish_class_source' => $klasse !== null ? 'ki' : null,
                    'dish_main_group_id' => $hg?->id,
                    'markup_class_id' => $ak?->id ?? $klasse?->default_markup_class_id ?? $hg?->default_markup_class_id,
                    'vat_rate' => $ak?->vat_rate,
                    // L8b: die Portion kommt AUS DEM VORSCHLAG — sie ist die letzte
                    // Vorbedingung des Wirtschaftlichkeits-Glieds (L8a) und die einzige,
                    // für die es keinen ableitbaren Default gibt (V-041: `yield_kg /
                    // sales_unit_count` wäre der Chargenpreis). `portionG()` lässt nur
                    // plausible Werte durch; alles andere bleibt die benannte Lücke.
                    'sales_quantity_per_unit_g' => $this->portionG($kiRezept['portion_g'] ?? null),
                ], fn ($v) => $v !== null));
            }

            // Diese Schlüssel bleiben für die Ergebnisfläche stabil. Bei der
            // Generierung selbst werden keine Stubs/GPs mehr automatisch angelegt;
            // eine spätere menschliche Hard-Stop-Aktion aktualisiert sie lokal.
            $statistik = ['bestand_gp' => 0, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'gp_neu_aus_la' => 0, 'offen' => 0];
            $offene = [];
            $zeilen = [];
            foreach (array_values($kiRezept['zutaten']) as $i => $z) {
                $text = trim((string) ($z['text'] ?? $z['name'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $einheitId = $this->einheitId($team, (string) ($z['unit'] ?? 'g'));
                $zeile = [
                    'raw_text' => $text,
                    'display_name' => $text,
                    'quantity' => (float) ($z['quantity'] ?? 1),
                    'unit_vocab_id' => $einheitId,
                    'note' => $z['note'] ?? null,
                    // Der Generator hat bereits bewusst geroutet. Ein Miss bleibt
                    // bis zur menschlichen LA-/GP- bzw. Subrezept-Bestätigung offen.
                    'auto_ground' => false,
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
                } else {
                    // Keine automatische Anlage: Basisrezept-Stub bzw. LA→GP werden
                    // erst nach menschlicher Auswahl in getrennten Schritten angelegt.
                    $zeile['match_method'] = 'unmatched';
                    $statistik['offen']++;
                    $istBasisrezept = $this->heuristik->queryIstHalbfabrikat(
                        app(Matching\TokenEngine::class)->tokenize($text)
                    );
                    $laKandidaten = $istBasisrezept ? [] : app(LaCandidateFinder::class)
                        ->find($team, $text, $this->wgHint($z['commodity_group'] ?? $z['warengruppe'] ?? null), 3)
                        ->map(fn ($la) => [
                            'id' => (int) $la->id,
                            'designation' => (string) $la->designation,
                            'supplier' => (string) ($la->supplier?->name ?? ''),
                            'score' => (float) ($la->score ?? 0),
                            'gp_id' => $la->structure?->gp_id !== null ? (int) $la->structure->gp_id : null,
                            'gp_name' => $la->structure?->gp?->name,
                        ])->all();
                    $offene[] = [
                        'index' => $i,
                        'text' => $text,
                        'primaer' => $istBasisrezept || $this->heuristik->istSubRezeptKandidat($text)
                            ? 'basisrezept_anlegen' : 'lieferantenartikel_waehlen',
                        'shortlist' => $this->matcher->candidatesFor($team, $text, $z['slug'] ?? null, 5),
                        'la_kandidaten' => $laKandidaten,
                    ];
                }
                $zeilen[] = $zeile;
            }

            $recipe = $this->recipes->syncIngredients($team, $recipe->id, $zeilen);   // inkl. Recompute

            // #505 Slice 2: VK-Kohärenz nach Zutaten-Sync (recipeCohesion braucht persistierte Zeilen).
            if ($vkModus) {
                try {
                    $statistik['kohaerenz'] = app(PairingService::class)->recipeCohesion($recipe);
                } catch (\Throwable $e) {
                    // Kohärenz ist Diagnose, kein Blocker der Generierung.
                }
            }

            return ['recipe' => $recipe, 'statistik' => $statistik, 'offene' => $offene];
        });
    }

    /**
     * V-04: Top-Bestands-Kandidaten zur Beschreibung (Token-LIKE über die
     * Basisrezept-Namen, approved zuerst) — als »benenne EXAKT so«-Inventar.
     *
     * @return list<string>
     */
    private function bestandsInventar(Team $team, string $description, int $limit = 30): array
    {
        $tokens = array_values(array_filter(
            app(Matching\TokenEngine::class)->tokenize($description),
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

    /**
     * Spec 16·E1: KI-WG-Hint auf den 2-stelligen Warengruppen-Code normalisieren
     * (das Modell liefert „01" oder „01 Gemüse" → beides ⇒ „01"). Kein 2-Stellen-
     * Präfix ⇒ null (Finder sucht dann global über alle Leads; nie ein Fehltreffer).
     */
    private function wgHint(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return preg_match('/^\s*(\d{2})\b/', $raw, $m) === 1 ? $m[1] : null;
    }

    /**
     * L8b · Portionsgewicht aus dem Vorschlag — mit engem Plausibilitäts-Band.
     *
     * Warum ein Band und kein blindes Durchschreiben: dieser Wert läuft direkt in
     * die Preis-Formel (`ek_portion = EK/g × Grammatur × Anzahl`). Eine
     * Halluzination in der falschen Größenordnung erzeugt keinen sichtbaren
     * Fehler, sondern einen falschen VK — und der ist schlimmer als kein VK,
     * weil er das Wirtschaftlichkeits-Glied grün melden lässt. 20–3000 g deckt
     * Amuse (20 g) bis Platte-je-Einheit (3 kg) ab; darüber/darunter ist es
     * praktisch immer eine Charge oder ein Zahlendreher.
     *
     * Alles Unplausible fällt STILL auf null — dann greift die benannte Lücke
     * aus L8a (`luecken: ['portion']`), die an der Fläche sichtbar ist. Ein
     * Fehler wäre hier falsch: das Rezept selbst ist verwertbar.
     */
    private function portionG(mixed $raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }
        $g = round((float) $raw, 1);

        return $g >= 20.0 && $g <= 3000.0 ? $g : null;
    }

    private function einheitId(Team $team, string $slug): int
    {
        $slug = mb_strtolower(trim($slug)) ?: 'g';
        $unit = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', $slug)->first()
            ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->first()
            ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->orderBy('id')->first();
        if ($unit === null) {
            throw new \RuntimeException('Kein Einheiten-Vokabular vorhanden (M1-02 zuerst).');
        }

        return $unit->id;
    }
}
