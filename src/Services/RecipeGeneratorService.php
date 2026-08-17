<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
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
            // Reuse-Achse (L1): »komplett_neu« (Kreativ-Modus = Voll kreativ) ignoriert den Bestand
            // bewusst → der Benennungs-Nudge entfällt, damit nicht doch auf Bestand geschielt wird.
            if (($parameter['bestand'] ?? 'hybrid') !== 'komplett_neu') {
                $inventar = $this->bestandsInventar($team, $description);
                if ($inventar !== []) {
                    $kontext['bestands_inventar'] = $inventar;
                }
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
        // Bio dreiwertig: bio_pref (bio|conventional|neutral) gewinnt — „egal" ⇒ neutral (Adjustment 0),
        // damit Bio-GPs nicht ungewollt bestraft werden. Fallback auf den Bool `bio` (MCP-Pfad, 4.4r).
        $bio = match ($parameter['bio_pref'] ?? null) {
            'bio' => 'bio',
            'neutral' => 'neutral',
            'conventional' => 'conventional',
            default => ($parameter['bio'] ?? false) ? 'bio' : 'conventional',
        };

        $melde('Zutaten werden zugeordnet …');

        $result = DB::transaction(function () use ($team, $kiRezept, $parameter, $mode, $pref, $preferRaw, $bio, $convenience, $vkModus, $createdVia, $melde) {
            $recipe = $this->recipes->create($team, [
                // L5: getippter Titel (titel_vorgabe) ist der Namens-Anker — er gewinnt vor dem KI-Namen
                // (der Mensch hat bewusst benannt). Immer defensiv normalisieren (Umbrüche/Whitespace raus,
                // Länge gedeckelt), damit kein Brief-Text als Name in die varchar-Spalte rutscht.
                'name' => $this->normalisiereName((string) ($parameter['titel_vorgabe'] ?? '') ?: (string) $kiRezept['name']),
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
                $istGpTreffer = $verdrahtbar && $treffer['target'] === 'gp';

                // ── Zerlegungs-Vorrang (L2, Entscheid 2026-08-17 »Convenience entscheidet«) ────────
                // Die Sub-Entscheidung wird VOR die GP-Verdrahtung gezogen: bisher gewann ein GP-Treffer
                // (Exact/FuzzyHigh) IMMER und §4/T4 lief nur im unmatched-else — ein bestehendes
                // »Kartoffelpüree: TK«-GP übersteuerte die Zerlegung still. Jetzt entscheidet die
                // Convenience-Achse, ob ein GP-Treffer die Zeile flach machen darf.
                $llmSub = $this->llmSubRezeptFlag($z);
                $nameHalbfabrikat = ! $direktArtikel && $this->heuristik->queryIstHalbfabrikat(
                    app(Matching\TokenEngine::class)->tokenize($text)
                );
                // STARKES Sub (§4 »jus ist die sauce« / LLM-Flag true): IMMER Basisrezept — überstimmt
                // auch einen GP-Treffer und jede Convenience-Stufe. Marker sind praktisch nie Flachware.
                $strongSub = ! $direktArtikel && ($nameHalbfabrikat || ($llmSub === true));
                // Rolle komponente/beilage im VK-Gericht (T4) — jetzt Convenience-gesteuert:
                $rolleKomponente = $vkModus && ! $direktArtikel && in_array($z['role'] ?? null, ['komponente', 'beilage'], true);
                $istConvenienceGp = $istGpTreffer && $this->istConvenienceGp((int) ($treffer['gp_id'] ?? 0));
                $rolleWillSub = $rolleKomponente && match ($convenience) {
                    'from_scratch'     => true,                 // hart: selbst bauen (auch über GP-Treffer)
                    'teil_convenience' => ! $istConvenienceGp,  // Convenience-GP darf gewinnen, sonst Sub
                    'voll_convenience' => false,                // Fertigkomponente kaufen → nie Sub erzwingen
                    default            => ! $istGpTreffer,      // egal/standard: Bestand zuerst (GP gewinnt), sonst Sub
                };
                // Frische-Erlaubnis (L1.5): ist eine Zustands-Liste gesetzt und der GP-Treffer trägt einen
                // NICHT erlaubten Zustand, wird er NICHT verdrahtet → die Zeile bleibt offen (LA/GP im
                // richtigen Zustand suchen). Harter Filter, aber am Post-Match-Gate (Matcher unangetastet).
                $frischeErlaubt = array_values(array_filter((array) ($parameter['frische_erlaubt'] ?? []), 'is_string'));
                $frischeBlockiert = $istGpTreffer && $frischeErlaubt !== []
                    && ! $this->gpZustandErlaubt((int) ($treffer['gp_id'] ?? 0), $frischeErlaubt);
                // Ein GP-Treffer wird NUR verdrahtet, wenn die Zeile nicht ohnehin Basisrezept sein muss
                // UND der Zustand erlaubt ist.
                $gpBlockiert = $strongSub || $rolleWillSub || $frischeBlockiert;
                // Vorentscheidung fürs unmatched-else (unten): Basisrezept vs. Lieferantenartikel. Ein reiner
                // Frische-Block macht die Zeile NICHT zum Basisrezept (der richtige Weg ist LA/GP im Zustand).
                $istBasisrezept = $strongSub || $rolleWillSub || (! $direktArtikel && ($llmSub ?? $this->heuristik->istSubRezeptKandidat($text)));

                if ($istGpTreffer && ! $gpBlockiert) {
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
                    // $istBasisrezept / $strongSub / $rolleWillSub sind oben (Zerlegungs-Vorrang L2)
                    // bereits berechnet — hier nur noch verwenden (§4 »jus«, LLM-Flag, Convenience-Rolle).
                    $zeile['match_method'] = 'unmatched';
                    $statistik['offen']++;
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
                        // $istBasisrezept (oben, Zerlegungs-Vorrang L2) enthält bereits: §4-Name /
                        // LLM-Flag / Convenience-gesteuerte Rolle + den istSubRezeptKandidat-Fallback.
                        'primaer' => $istBasisrezept ? 'basisrezept_anlegen' : 'lieferantenartikel_waehlen',
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

        // Diät-/Allergen-Gate (L3, Entscheid »prüfen + entdrahten + melden«): läuft für BEIDE Modi
        // (ein veganes Gericht mit Butter ist ein VK-Fall). Deterministisch, 0 Kosten. Ein verdrahteter
        // GP, der eine harte Diät-Vorgabe (diaet_hart) oder ein No-Go-Allergen (allergen_nogo) explizit
        // verletzt, wird ENTdrahtet (Zeile bleibt offen) + als Befund gemeldet — der Mensch entscheidet
        // (keine harte Sperre). NULL/unbewertet blockt NIE (Doktrin: unbekannt ≠ Verstoß).
        $result = $this->diaetGate($team, $result, $parameter);

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
     * Diät-/Allergen-Gate (L3) — deterministischer Post-Check nach dem Verdrahten, für BEIDE Modi.
     * Ein verdrahteter GP, der eine harte Diät-Vorgabe (`diaet_hart`) oder ein No-Go-Allergen
     * (`allergen_nogo`) EXPLIZIT verletzt, wird ENTdrahtet (Zeile bleibt offener Hard-Stop, primär
     * »Lieferantenartikel wählen« = konforme Alternative) und als Befund gemeldet. Doktrin:
     * NULL/unbewertet blockt NIE (unbekannt ≠ Verstoß), `low_carb` ist per GP-Flag nicht prüfbar →
     * bewusst übersprungen. Prüft die GP-Diät-Tags (per-GP-Wahrheit) + Allergen-Override »enthalten«.
     * Fail-open: jeder Fehler lässt den Lauf unangetastet (Diagnose, kein Blocker).
     *
     * @param  array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}  $result
     * @param  array<string,mixed>  $parameter
     * @return array{recipe: FoodAlchemistRecipe, statistik: array, offene: array}
     */
    private function diaetGate(Team $team, array $result, array $parameter): array
    {
        /** @var FoodAlchemistRecipe $recipe */
        $recipe = $result['recipe'];
        $statistik = $result['statistik'];
        $offene = $result['offene'] ?? [];
        $statistik['diaet'] = ['geprueft' => false, 'uebersprungen' => false, 'entdrahtet' => 0, 'befunde' => []];

        $diaetHart = array_values(array_filter((array) ($parameter['diaet_hart'] ?? []), 'is_string'));
        $allergenNogo = array_values(array_filter((array) ($parameter['allergen_nogo'] ?? []), 'is_string'));
        if ($diaetHart === [] && $allergenNogo === []) {
            $statistik['diaet']['uebersprungen'] = true;
            $result['statistik'] = $statistik;

            return $result;
        }

        try {
            $zeilen = $recipe->ingredients()->whereNull('deleted_at')->whereNotNull('gp_id')->with('gp')->orderBy('position')->get();
            foreach ($zeilen as $z) {
                $gp = $z->gp;
                if ($gp === null) {
                    continue;
                }
                $gruende = $this->diaetVerstoesse($gp, $diaetHart, $allergenNogo);
                if ($gruende === []) {
                    continue;
                }
                $text = (string) (($z->raw_text ?? '') !== '' ? $z->raw_text : ($z->display_name ?: $gp->name));
                $position = (int) $z->position;
                $zielGpId = (int) $z->gp_id;

                app(HardstopResolveService::class)->entdrahte($team, (int) $recipe->id, (int) $z->id);
                $statistik['diaet']['entdrahtet']++;
                $statistik['bestand_gp'] = max(0, (int) ($statistik['bestand_gp'] ?? 0) - 1);
                $statistik['offen'] = (int) ($statistik['offen'] ?? 0) + 1;
                $statistik['diaet']['befunde'][] = $text . ': ' . implode(', ', $gruende);

                $offene[] = [
                    'index' => $position - 1,   // Kontrakt afterGenerated: position === index + 1
                    'text' => $text,
                    // Konforme Alternative suchen (kein Auto-Sub — ein Diät-Verstoß ist keine Zerlegungs-Frage).
                    'primaer' => 'lieferantenartikel_waehlen',
                    'shortlist' => $this->matcher->candidatesFor($team, $text, null, 5),
                    'la_kandidaten' => [],
                    'lieferantenstrategie' => null,
                    'schwacher_treffer' => null,
                    // Diät-/Allergen-Gate: WARUM die Zeile entdrahtet wurde (Review-Fläche + kein Auto-Plan).
                    'diaet_verstoss' => [
                        'ziel_id' => $zielGpId,          // „Trotzdem verwenden" bindet den GP wieder (Override)
                        'gruende' => $gruende,
                    ],
                ];
            }
            $statistik['diaet']['geprueft'] = true;
            if ($statistik['diaet']['entdrahtet'] > 0) {
                $result['recipe'] = $recipe->refresh();
            }
        } catch (\Throwable $e) {
            $statistik['diaet'] = ['geprueft' => false, 'uebersprungen' => false, 'entdrahtet' => 0, 'befunde' => [], 'fehler' => true];
        }

        $result['statistik'] = $statistik;
        $result['offene'] = $offene;

        return $result;
    }

    /**
     * Explizite Diät-/Allergen-Verstöße eines GP (L3). Prüft die per-GP-Diät-Tags (tri-state:
     * NULL=unbewertet blockt nicht) + Allergen-Override »enthalten«. Liefert Klartext-Gründe.
     *
     * @param  list<string>  $diaetHart      vegan|vegetarisch|glutenfrei|laktosefrei|halal|low_carb
     * @param  list<string>  $allergenNogo   EU-14-Keys (gluten|milk|sesame|…)
     * @return list<string>
     */
    private function diaetVerstoesse(FoodAlchemistGp $gp, array $diaetHart, array $allergenNogo): array
    {
        $g = [];
        foreach ($diaetHart as $form) {
            $verstoss = match ($form) {
                'vegan'       => $gp->tag_is_vegan === false,
                'vegetarisch' => $gp->tag_is_vegetarian === false,
                'halal'       => $gp->tag_is_halal === false || $gp->tag_contains_pork === true,
                'glutenfrei'  => $gp->tag_is_gluten_free === false || $this->gpAllergenEnthalten($gp, 'gluten'),
                'laktosefrei' => $gp->tag_is_lactose_free === false || $this->gpAllergenEnthalten($gp, 'milk'),
                default       => false,   // low_carb: kein GP-Flag → unprüfbar, nie blocken
            };
            if ($verstoss) {
                $g[] = 'verletzt ' . $form;
            }
        }
        foreach ($allergenNogo as $key) {
            if ($this->gpAllergenEnthalten($gp, $key)) {
                $g[] = 'enthält ' . $key;
            }
        }

        return $g;
    }

    /**
     * L5: Rezeptname defensiv normalisieren — Zeilenumbrüche/Tabs → Leerzeichen, Mehrfach-Whitespace
     * kollabiert, getrimmt, Länge gedeckelt (die Spalte ist varchar(255); ein KI-Echo des ganzen
     * Briefs darf nicht als Name landen). Leerer/whitespace-only Name fällt auf einen sicheren Default.
     */
    private function normalisiereName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n", "\t"], ' ', $name)));
        if ($clean === '') {
            return 'Unbenannt';
        }

        return mb_strimwidth($clean, 0, 200, '…');
    }

    /** Allergen-Override des GP auf »enthalten« (NULL/spuren/unbekannt blockt bewusst nicht). */
    private function gpAllergenEnthalten(FoodAlchemistGp $gp, string $field): bool
    {
        if (! in_array($field, FoodAlchemistGp::ALLERGEN_FIELDS, true)) {
            return false;
        }

        return (string) $gp->getAttribute("allergen_{$field}") === 'enthalten';
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
     * Etappe 1 (2026-08-14): das LLM-Komponenten-Flag `sub_rezept` aus EINER
     * Vorschlags-Zeile robust lesen. Kanonisch als JSON-Bool emittiert (b36ba00),
     * aber ein LLM liefert das Feld auch mal als 1/0 oder "true"/"ja" — darum
     * tolerant normalisieren. Rückgabe:
     *   true  → Zeile IST ein Halbfabrikat/Sub-Basisrezept (authoritativ)
     *   false → Zeile ist Rohware/Kondiment (authoritativ)
     *   null  → Flag fehlt/unverständlich → Aufrufer fällt auf die Namens-Heuristik zurück
     *
     * Kein blindes (bool)-Cast: das würde ein fehlendes Feld zu `false` machen und
     * so die Heuristik STILL abschalten — genau der Fallback, den wir behalten wollen.
     */
    private function llmSubRezeptFlag(array $z): ?bool
    {
        if (! array_key_exists('sub_rezept', $z)) {
            return null;
        }
        $v = $z['sub_rezept'];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1 ? true : ($v === 0 ? false : null);
        }
        if (is_string($v)) {
            $s = mb_strtolower(trim($v));
            if (in_array($s, ['true', '1', 'ja', 'yes'], true)) {
                return true;
            }
            if (in_array($s, ['false', '0', 'nein', 'no', ''], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Convenience-GP? (L2 Zerlegungs-Vorrang, teil_convenience): ein GP mit dem Convenience-Tag darf
     * bei Rolle komponente/beilage die Zerlegung gewinnen (Halbfabrikat kaufen statt Sub-Rezept bauen).
     * Fail-soft: kein/ungültiger GP → false (im Zweifel zerlegen, nicht flach kaufen).
     */
    private function istConvenienceGp(int $gpId): bool
    {
        if ($gpId <= 0) {
            return false;
        }

        return (bool) \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()
            ->whereKey($gpId)->value('tag_is_convenience');
    }

    /**
     * Frische-Erlaubnis (L1.5): trägt der GP einen erlaubten Zustand? Vergleicht primär die rohe
     * `gps.condition` (frisch|TK|trocken|konserviert — §9) gegen die Erlaubnis-Liste; ist die Spalte
     * leer, fällt es lenient auf den Namens-Bucket zurück (nur so werden Zustands-lose GPs nicht
     * fälschlich ausgefiltert). trocken/konserviert kollabieren im Bucket-Fallback (beide `preserved`).
     *
     * @param  list<string>  $erlaubtRaw  rohe Zustands-Werte (frisch|TK|trocken|konserviert)
     */
    private function gpZustandErlaubt(int $gpId, array $erlaubtRaw): bool
    {
        if ($gpId <= 0 || $erlaubtRaw === []) {
            return true;
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()
            ->whereKey($gpId)->first(['name', 'condition']);
        if ($gp === null) {
            return true;   // fail-open: kein GP zum Prüfen
        }
        $raw = trim((string) ($gp->condition ?? ''));
        if ($raw !== '') {
            return in_array($raw, $erlaubtRaw, true);
        }
        // condition unset → Namens-Bucket (lenient): erlaubte Roh-Werte auf Buckets abbilden.
        $erlaubtBuckets = array_map(static fn ($z) => match ($z) {
            'frisch' => 'fresh', 'TK' => 'frozen', 'trocken', 'konserviert' => 'preserved', default => 'unknown',
        }, $erlaubtRaw);
        $bucket = $this->heuristik->zustandClassResolved((string) $gp->name, null);

        return in_array($bucket, $erlaubtBuckets, true);
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
