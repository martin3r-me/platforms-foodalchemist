<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
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
    public function generiere(Team $team, string $description, array $parameter = [], ?array $kiRezeptOverride = null, bool $vkModus = false, ?string $createdVia = null, ?array $preparedContext = null, ?callable $onProgress = null): array
    {
        // P0.2: feine Fortschritts-Stufen — die UI zeigt live, WO der Lauf steht; ein
        // OOM-/Hänger-Tod bleibt am zuletzt gemeldeten Schritt stehen (Pinpoint der Ursache).
        $melde = static function (string $stufe) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($stufe);
            }
        };
        $kiRezept = $kiRezeptOverride;
        // Kontext-Inspektor: das UI-fertige „auf welches Wissen greift der Generator"-Bündel.
        // VOR dem OOM-`unset` unten gesichert (winzige String-Listen) und am Ende ans Ergebnis
        // gehängt. null im Override-Pfad (kein frischer Kontext) → UI blendet das Panel aus.
        $kontextAudit = null;
        if ($kiRezept === null) {
            $melde('Kontext & Wissen werden geladen …');
            $preparedContext ??= app(RecipeGenerationContextService::class)->build($team, $description, $parameter, $vkModus);
            $kontextAudit = $preparedContext['kontext'] ?? null;
            $kontext = $preparedContext['prompt'];
            $wissen = ['block' => $preparedContext['knowledge'], 'files_used' => $preparedContext['knowledge_used']];

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
            $melde('KI schreibt das Rezept …');
            $vorschlag = $this->ki->propose($vkModus ? 'vk.generator' : 'recipe.generator', $kontext, [
                'knowledge' => $wissen['block'],
                'knowledge_used' => $wissen['files_used'],            // M7-01: GL-13-§6-Audit-Lücke geschlossen
                // M7-03 §3.3 (Ist: commands.rs:20766-20780): valides JSON ohne
                // name/zutaten ist strukturell unbrauchbar → Gateway re-rollt
                'structural_retry' => fn (array $parsed) => ! empty($parsed['werte']['name']) && ! empty($parsed['werte']['zutaten']),
            ]);
            $kiRezept = $vorschlag->werte;
            // P0.2: die grossen Kontext-/Wissen-/Inventar-Arrays werden ab hier nicht mehr
            // gebraucht (die Transaktions-Closure haelt sie NICHT) → jetzt freigeben, damit
            // der Peak-Speicher waehrend Matching/Sync sinkt (OOM-Gegenmassnahme).
            unset($preparedContext, $kontext, $wissen, $inventar, $vorschlag);
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

        $melde('Zutaten werden zugeordnet …');

        $result = DB::transaction(function () use ($team, $kiRezept, $parameter, $mode, $pref, $preferRaw, $bio, $vkModus, $createdVia, $melde) {
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
                    // Kohärenz-Gate (2026-08-07): die V-21-Rolle aus dem Vorschlag füllt das
                    // bisher tote role-Feld (Schicht 1). Whitelist-Guard wie taste_direction —
                    // nur gültige Rollen, sonst null (kein Insert-Crash durch Freitext).
                    'role' => in_array($z['role'] ?? null, SpeisenKlassenService::ROLLEN, true) ? $z['role'] : null,
                    // Der Generator hat bereits bewusst geroutet. Ein Miss bleibt
                    // bis zur menschlichen LA-/GP- bzw. Subrezept-Bestätigung offen.
                    'auto_ground' => false,
                ];

                // Agentischer Resolver: BESTAND ZUERST (GL-04 voll, inkl. §4/§5-Aliasse).
                // VK bleibt Komponenten-/Basisrezept-first, außer klar kaufbaren Einzelartikeln
                // (Deko, Gewürz, fertig belegte Artikel): dort gewinnt vorhandenes GP, sonst LA→GP.
                $direktArtikel = $vkModus && $this->heuristik->istDirektArtikelKandidat($text);
                $zeilenMode = $direktArtikel ? 'gp_first' : $mode;
                $treffer = $this->matcher->matchIngredient($team, $text, $z['slug'] ?? null, $zeilenMode, $pref, $preferRaw, $bio);

                // Band-Gate (2026-08-06, Rahmeis-in-Tomatensuppe): FuzzyLow heißt laut
                // GL-04 §4.1 wörtlich „Review nötig" — der Generator ist aber der teuerste
                // Ort für einen stillen Fehl-Match (ein fremdes Sub-Rezept wird unbemerkt
                // in ein NEUES Rezept verdrahtet: „Balsamico-Reduktion" → „Rahmeis:
                // Balsamico", 1 von 2 Tokens = Score 0.50). Echte „gleiches Produkt,
                // mehr Deskriptoren"-Treffer hebt der Head-Match-Floor (0.90) ohnehin
                // auf Exact — was im FuzzyLow-Band bleibt, ist überproportional Gift.
                // Darum: nur Exact/FuzzyHigh wird verdrahtet, FuzzyLow bleibt OFFEN
                // (Review-Pfad mit Shortlist — der Mensch entscheidet). Der Matcher
                // selbst (Schwellen, 84 GL-04-Goldens) bleibt unberührt.
                $verdrahtbar = $treffer['status'] === MatchBand::Exact || $treffer['status'] === MatchBand::FuzzyHigh;
                if ($verdrahtbar && $treffer['target'] === 'gp') {
                    $zeile['gp_id'] = $treffer['gp_id'];
                    $zeile['match_method'] = 'gemini_proposed';
                    $zeile['match_confidence'] = round($treffer['score'], 3);
                    $statistik['bestand_gp']++;
                } elseif ($verdrahtbar && $treffer['target'] === 'sub_recipe') {
                    $zeile['referenced_recipe_id'] = $treffer['recipe_id'];
                    $zeile['match_method'] = 'recipe_ref';
                    $statistik['bestand_sub']++;
                } else {
                    // Keine automatische Anlage: Basisrezept-Stub bzw. LA→GP werden
                    // erst nach menschlicher Auswahl in getrennten Schritten angelegt.
                    $zeile['match_method'] = 'unmatched';
                    $statistik['offen']++;
                    $istBasisrezept = ! $direktArtikel && $this->heuristik->queryIstHalbfabrikat(
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
                        'primaer' => $istBasisrezept || (! $direktArtikel && $this->heuristik->istSubRezeptKandidat($text))
                            ? 'basisrezept_anlegen' : 'lieferantenartikel_waehlen',
                        'shortlist' => $this->matcher->candidatesFor($team, $text, $z['slug'] ?? null, 5),
                        'la_kandidaten' => $laKandidaten,
                        'lieferantenstrategie' => $istBasisrezept ? null : app(TeamSettingsService::class)
                            ->leadLaStrategie($team, $this->wgHint($z['commodity_group'] ?? $z['warengruppe'] ?? null))->value,
                        // Band-Gate-Transparenz: der abgewiesene FuzzyLow-Kandidat bleibt
                        // für die Review-Fläche sichtbar (Mensch bestätigt oder verwirft).
                        'schwacher_treffer' => $treffer['target'] !== 'none' ? [
                            'target' => $treffer['target'],
                            // id, damit die Review-Fläche „Meintest du?" auch VERKNÜPFEN kann
                            // (hardstopVerknuepfen bindet als Override — der Mensch übersteuert das Band-Gate).
                            'id' => (int) ($treffer['target'] === 'gp' ? $treffer['gp_id'] : $treffer['recipe_id']),
                            'name' => $treffer['gp_name'] ?? $treffer['recipe_name'],
                            'score' => round((float) $treffer['score'], 3),
                        ] : null,
                    ];
                }
                $zeilen[] = $zeile;
            }

            $recipe = $this->recipes->syncIngredients($team, $recipe->id, $zeilen);   // inkl. Recompute

            // #505 / Kohärenz-Gate (2026-08-07): recipeCohesion nach Zutaten-Sync (braucht
            // persistierte Zeilen) — jetzt auch für BASIS, nicht nur VK. Diagnose-Zahl, kein
            // Blocker; DB-only + fail-open. Die Anzeige konditioniert Phase 3 auf coverage_pct.
            $melde('Kohärenz wird geprüft …');
            try {
                $statistik['kohaerenz'] = app(PairingService::class)->recipeCohesion($recipe);
            } catch (\Throwable $e) {
                // Kohärenz ist Diagnose, kein Blocker der Generierung.
            }

            return ['recipe' => $recipe, 'statistik' => $statistik, 'offene' => $offene];
        });

        // Kohärenz-Gate (Phase 2, 2026-08-07) — NACH dem Transaktions-Commit, VOR dem Return.
        // Bewusst ausserhalb der Transaktion: der Kritiker ist ein KI-Call (Tier A, potenziell
        // >10 s) und darf die Zutaten-Transaktion nicht offen halten. Nur BASIS ist scharf — VK
        // folgt in eigener Runde. Die Konsumenten (GenerateRecipeJob, PlanningCascadeService)
        // rufen afterGenerated erst mit dem Rückgabewert $result['offene'] → sie sehen die
        // entdrahteten Zeilen automatisch; keine verwaisten Dependencies.
        if (! $vkModus) {
            $result = $this->kohaerenzGate($team, $result, $melde);
        }

        $result['kontext'] = $kontextAudit;   // Kontext-Inspektor fürs UI (null im Override-Pfad)

        return $result;
    }

    /**
     * Kohärenz-Gate (Phase 2, 2026-08-07) — der Qualitäts-Post-Check NACH dem Verdrahten.
     *
     * Zwei Stufen, aufsteigend teuer:
     *  a) REGEL (deterministisch, 0 Kosten, läuft immer): süß-in-herzhaft zwischen dem Gericht
     *     (taste_direction) und einem verdrahteten Sub-Rezept. Fängt den Rahmeis-Fall.
     *  b) KRITIKER (1 KI-Call, recipe.review) — GEGATET: nur wenn verdrahtete Sub-Rezepte
     *     existieren (die reale Risikofläche; beide Referenzfälle vom 2026-08-06 waren Subs).
     *     Reine GP-Rezepte überspringen den Call → keine Doppel-Latenz im Normalfall. Er
     *     beurteilt die VERDRAHTETEN Namen (nicht die KI-Absicht) und fängt den thematisch
     *     falschen „Gemüsefond: Bohne-Speck", den die Regel nicht sieht.
     *
     * Konsequenz je Treffer: die Zeile wird ENTdrahtet (Verknüpfung gelöst, Zeile bleibt als
     * offener Hard-Stop) und in offene[] mit `kritiker`-Grund gehängt — NIE stilles Löschen.
     * Fail-open: jeder Fehler im Gate lässt die Generierung unangetastet durch (Diagnose, kein
     * Blocker, kein OOM-Risiko — reiner Text-Call).
     *
     * @param  array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}  $result
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    private function kohaerenzGate(Team $team, array $result, callable $melde): array
    {
        /** @var FoodAlchemistRecipe $recipe */
        $recipe = $result['recipe'];
        $statistik = $result['statistik'];
        $offene = $result['offene'];
        $statistik['kritiker'] = ['geprueft' => false, 'uebersprungen_gating' => false, 'entdrahtet' => 0, 'befunde_abgelegt' => 0];

        try {
            $zeilen = $recipe->ingredients()
                ->with(['referencedRecipe:id,name,taste_direction', 'gp:id,name'])->get()->keyBy('id');
            $wiredSubs = $zeilen->filter(fn ($z) => $z->referenced_recipe_id !== null);

            // Treffer sammeln: ingredient_id => ['grund','konfidenz','quelle']. Regel gewinnt (billiger, hart).
            $treffer = [];

            // Stufe a — Regel: Geschmacks-Konflikt Gericht × verdrahtetes Sub (null/neutral = kein Urteil).
            $parentTaste = $recipe->taste_direction;
            if (in_array($parentTaste, ['suess', 'herzhaft'], true)) {
                foreach ($wiredSubs as $z) {
                    $subTaste = $z->referencedRecipe?->taste_direction;
                    if (in_array($subTaste, ['suess', 'herzhaft'], true) && $subTaste !== $parentTaste) {
                        $treffer[(int) $z->id] = [
                            'grund' => "Geschmacks-Konflikt: Gericht {$parentTaste}, Komponente «{$z->referencedRecipe->name}» {$subTaste}.",
                            'konfidenz' => 1.0,
                            'quelle' => 'regel',
                        ];
                    }
                }
            }

            // Stufe b — Kritiker (GEGATET auf verdrahtete Sub-Rezepte). Übrige Befunde in die Ablage.
            // Eigenes try/catch: scheitert der KI-Call, bleibt die deterministische Regel (Stufe a)
            // trotzdem wirksam — sie ist die billige, ausfallsichere Vorstufe, kein Anhängsel.
            if ($wiredSubs->isNotEmpty()) {
                try {
                    $melde('Kohärenz-Gate: Fremdkörper werden geprüft …');
                    $review = app(RecipeReviewService::class)->pruefe($team, $recipe->id);
                    $rest = [];
                    foreach ($review['befunde'] as $b) {
                        $istFremd = ($b['art'] ?? '') === 'fremdkoerper' && ($b['zutat_id'] ?? null) !== null;
                        if ($istFremd && (float) ($b['konfidenz'] ?? 0) >= RecipeFindingService::KONFIDENZ_SCHWELLE) {
                            $id = (int) $b['zutat_id'];
                            if (! isset($treffer[$id])) {                // Regel gewinnt bei Doppel-Flag
                                $treffer[$id] = [
                                    'grund' => (string) ($b['begruendung'] ?? 'Passt fachlich nicht ins Gericht.'),
                                    'konfidenz' => (float) $b['konfidenz'],
                                    'quelle' => 'ki',
                                ];
                            }
                        } else {
                            $rest[] = $b;                                // menge/einheit/fehlt/hinweis + Fremdkörper < Schwelle
                        }
                    }
                    if ($rest !== []) {                                  // Copilot/Signale sehen die übrigen Befunde
                        $abgelegt = app(RecipeFindingService::class)->speichere($team, $recipe->id, $rest);
                        $statistik['kritiker']['befunde_abgelegt'] = (int) ($abgelegt['neu'] ?? 0);
                    }
                    $statistik['kritiker']['geprueft'] = true;
                } catch (\Throwable $e) {
                    $statistik['kritiker']['fehler'] = true;             // Regel bleibt, Generierung läuft weiter
                }
            } else {
                $statistik['kritiker']['uebersprungen_gating'] = true;
            }

            // Entdrahten + offene[]-Eintrag je Treffer (Regel + Kritiker).
            foreach ($treffer as $id => $info) {
                $z = $zeilen->get($id);
                if ($z === null || ($z->gp_id === null && $z->referenced_recipe_id === null)) {
                    continue;                                            // nicht mehr da / schon offen
                }
                $wasSub = $z->referenced_recipe_id !== null;
                // Ziel-id VOR dem Entdrahten sichern — „Trotzdem verwenden" bindet genau dieses
                // Objekt wieder (hardstopVerknuepfen als Override).
                $zielRefId = (int) ($wasSub ? $z->referenced_recipe_id : $z->gp_id);
                $zielName = (string) (($wasSub ? $z->referencedRecipe?->name : $z->gp?->name) ?? $z->display_name ?? $z->raw_text);
                $text = (string) (($z->raw_text ?? '') !== '' ? $z->raw_text : ($z->display_name ?: $zielName));
                $position = (int) $z->position;

                app(HardstopResolveService::class)->entdrahte($team, $recipe->id, $id);
                $statistik['kritiker']['entdrahtet']++;
                // Zähler ehrlich halten: die Zeile ist nicht mehr verdrahtet, sondern offen.
                if ($wasSub) {
                    $statistik['bestand_sub'] = max(0, (int) ($statistik['bestand_sub'] ?? 0) - 1);
                } else {
                    $statistik['bestand_gp'] = max(0, (int) ($statistik['bestand_gp'] ?? 0) - 1);
                }
                $statistik['offen'] = (int) ($statistik['offen'] ?? 0) + 1;

                $istSub = $wasSub || $this->heuristik->istSubRezeptKandidat($text);
                $offene[] = [
                    'index' => $position - 1,                            // Kontrakt afterGenerated: position === index + 1
                    'text' => $text,
                    'primaer' => $istSub ? 'basisrezept_anlegen' : 'lieferantenartikel_waehlen',
                    'shortlist' => $this->matcher->candidatesFor($team, $text, null, 5),
                    'la_kandidaten' => [],
                    'lieferantenstrategie' => null,
                    'schwacher_treffer' => null,
                    // Kohärenz-Gate: WARUM die Zeile ENTdrahtet wurde (Review-Fläche Phase 3).
                    'kritiker' => [
                        'name' => $zielName,
                        'target' => $wasSub ? 'sub_recipe' : 'gp',
                        'ziel_id' => $zielRefId,                        // „Trotzdem verwenden" bindet dieses Objekt wieder
                        'grund' => $info['grund'],
                        'konfidenz' => round((float) $info['konfidenz'], 3),
                        'quelle' => $info['quelle'],
                    ],
                ];
            }

            if ($statistik['kritiker']['entdrahtet'] > 0) {
                $result['recipe'] = $recipe->refresh();
            }
        } catch (\Throwable $e) {
            // Fail-open: das Gate ist Qualität/Diagnose, kein Blocker. Der Lauf kommt normal durch.
            $statistik['kritiker'] = ['geprueft' => false, 'uebersprungen_gating' => false,
                'entdrahtet' => 0, 'befunde_abgelegt' => 0, 'fehler' => true];
        }

        $result['statistik'] = $statistik;
        $result['offene'] = $offene;

        return $result;
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
