<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/** Einheitliche Personenzeit für Produktion und Angebotskalkulation. */
class ProductionTimeService
{
    /**
     * Spec 83 B3/B5 (Entscheid Dominique 14.10.2026): Anrichten am Gericht = Zahl der Teller-Komponenten × 5–10 s je Portion.
     * Startwert 5 s = unteres Ende der Spannweite: 4–7 Komponenten ergeben 0,33–0,58 min je Portion, das trifft den B3-Richtwert
     * 0,3–0,6 min. Quelle: Spec 83 B5, ungeprüft, Abgleich mit dem Küchenchef offen.
     */
    public const ANRICHTEN_SEKUNDEN_JE_KOMPONENTE = 5.0;

    /**
     * Spec 83 B3d (Entscheid Dominique 14.10.2026): Regenerieren = Personenzeit je Regenerier-Behälter (Bestücken und
     * Entnehmen), Startwert 30 s je GN-Behälter. Quelle: Spec 83, ungeprüft, Abgleich mit dem Küchenchef offen.
     */
    public const REGENERIEREN_SEKUNDEN_JE_BEHAELTER = 30.0;

    /**
     * Spec 83 B3i/B3k (Entscheide Dominique 14.10.2026): Das Anrichte-Maß hängt an der DARREICHUNGSFORM (serving_forms.code).
     * portion = Sekunden je Portion (Startwert Komponenten × 5 s) · stueck = Sekunden je Stück · behaelter = Minuten je Platte bzw.
     * Ausgabe-Behälter (Zahl aus dem Behälter-Bedarf, Zweck „ausgabe“) · vor_ort = Anrichten an der Live-Station, kein Küchen-Lohn.
     * Unbekannte/unbestimmte Form → wie portion, mit Warnung.
     */
    public const ANRICHTEN_FORMEN = [
        'teller' => 'portion', 'box' => 'portion', 'theke' => 'portion', 'foodtruck' => 'portion',
        'flying' => 'stueck', 'fingerfood' => 'stueck',
        'buffet' => 'behaelter', 'pause' => 'behaelter', 'sharing' => 'behaelter',
        'station' => 'vor_ort',
    ];

    /** Startwert Flying/Fingerfood: Sekunden je Stück. Quelle: Spec 83 B3k, ungeprüft. */
    public const ANRICHTEN_SEKUNDEN_JE_STUECK = 5.0;

    /** Startwert Buffet/Pause/Sharing: Minuten je Platte bzw. Ausgabe-Behälter. Quelle: Spec 83 B3k, ungeprüft. */
    public const ANRICHTEN_MINUTEN_JE_PLATTE = 2.0;

    /** Startwert Pauschale je Auftragszeile und Darreichung (Rüsten am Pass): 0 bis Dominique einen Wert nennt (Spec 83 B3l). */
    public const ANRICHTEN_PAUSCHALE_MIN = 0.0;

    /** @return array{einheit:string, masseinheit:?string, label:string} Einheit des Anrichte-Werts einer Servierform. */
    public static function anrichteEinheit(?string $formCode): array
    {
        $einheit = self::ANRICHTEN_FORMEN[$formCode ?? ''] ?? 'portion';

        return match ($einheit) {
            'stueck' => ['einheit' => 'stueck', 'masseinheit' => 's', 'label' => 'je Stück (s)'],
            'behaelter' => ['einheit' => 'behaelter', 'masseinheit' => 'min', 'label' => 'je Platte (min)'],
            'vor_ort' => ['einheit' => 'vor_ort', 'masseinheit' => null, 'label' => 'vor Ort (kein Küchen-Lohn)'],
            default => ['einheit' => 'portion', 'masseinheit' => 's', 'label' => 'je Teller (s)'],
        };
    }

    public function __construct(private TeamSettingsService $settings) {}

    /** @return array<string,mixed> */
    public function calculateForBatches(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $rawBatches,
        ?FoodAlchemistProductionStation $station = null,
        ?array $formen = null,
    ): array {
        $isPieces = $recipe->istStueckErtrag();
        $batches = max(0.0, $rawBatches);
        $yieldKg = (float) ($recipe->yield_kg_manual ?? $recipe->yield_kg ?? 0);
        $yieldPieces = (float) ($recipe->yield_pieces ?? 0);
        // Spec 83 B3: am Gericht ohne sales_unit_count gilt 1 Ansatz = 1 Portion — wie in Planungsblatt/Darreichung,
        // die Gerichts-Ansätze ohnehin als Portionen ÷ max(1, sales_unit_count) rechnen.
        $portionsPerBatch = (float) ($recipe->sales_unit_count ?? 0);
        if ($portionsPerBatch <= 0 && $recipe->is_sales_recipe) {
            $portionsPerBatch = 1.0;
        }
        $quantities = [
            'kg' => $yieldKg > 0 ? $batches * $yieldKg : null,
            'piece' => $yieldPieces > 0 ? $batches * $yieldPieces : null,
            'portion' => $portionsPerBatch > 0 ? $batches * $portionsPerBatch : null,
        ];
        $basis = $isPieces ? 'piece' : 'kg';
        $quantity = (float) ($quantities[$basis] ?? 0);
        $variableBasis = $this->zeitJeMenge($recipe, $basis)['basis'];

        return $this->calculate(
            $team,
            $recipe,
            $quantity,
            $basis,
            $station,
            $rawBatches,
            $quantities[$variableBasis] ?? null,
            $formen,
        );
    }

    /** @return array<string,mixed> */
    public function calculate(
        Team $team,
        FoodAlchemistRecipe $recipe,
        float $totalQuantity,
        string $quantityBasis,
        ?FoodAlchemistProductionStation $station = null,
        ?float $rawBatches = null,
        ?float $variableQuantityOverride = null,
        ?array $formen = null,
    ): array {
        $basis = in_array($quantityBasis, ['kg', 'piece', 'portion'], true) ? $quantityBasis : 'kg';
        $isPieces = $basis === 'piece';
        $recipeCap = $isPieces ? $recipe->batch_max_pieces : $recipe->batch_max_kg;
        $stationCap = $isPieces ? $station?->batch_max_pieces : $station?->batch_max_kg;
        $teamCap = $isPieces ? $this->settings->defaultTopfDeckelStueck($team) : $this->settings->defaultTopfDeckelKg($team);
        $caps = array_values(array_filter([
            ['value' => $recipeCap, 'source' => 'recipe'],
            ['value' => $stationCap, 'source' => 'station'],
            ['value' => $teamCap, 'source' => 'team_default'],
        ], fn (array $cap) => $cap['value'] !== null && (float) $cap['value'] > 0));
        usort($caps, fn (array $a, array $b) => (float) $a['value'] <=> (float) $b['value']);
        $effective = $caps[0] ?? null;

        if ($recipe->is_sales_recipe) {
            // Spec 83 B3j (Entscheid Dominique 14.10.2026, ersetzt B3b): am Gericht gibt es kein „Pass einrichten“ mehr —
            // work_time_min zählt dort NICHT (Daten bleiben stehen). Ein Vorgang, keine Topf-Deckel-Rechnung.
            $operations = 1;
            $effective = ['value' => null, 'source' => 'gericht'];
        } elseif ($totalQuantity > 0 && $effective !== null) {
            $operations = max(1, (int) ceil($totalQuantity / (float) $effective['value'] - 1e-9));
        } else {
            $operations = max(1, (int) ceil((float) ($rawBatches ?? 1) - 1e-9));
        }

        $zeitJeMenge = $this->zeitJeMenge($recipe, $basis);
        $variableBasis = $zeitJeMenge['basis'];
        $variableQuantity = $variableBasis === $basis
            ? max(0.0, $totalQuantity)
            : max(0.0, (float) ($variableQuantityOverride ?? 0));
        $warnings = [];
        if ($zeitJeMenge['min'] > 0
            && $variableBasis !== $basis
            && $variableQuantityOverride === null) {
            $warnings[] = "Variable Zeit auf {$variableBasis} kann aus {$basis} ohne Umrechnung nicht belastbar berechnet werden.";
        }

        // Entscheid 2026-09-04: Ruestzeit ist eine Herstellungs-Groesse und gilt nur am
        // Basisrezept. Am Gericht wird sie ignoriert — sonst rechneten Alt-Werte, die die
        // UI nicht mehr zeigt, still weiter in die Personenminuten.
        $setup = $recipe->is_sales_recipe ? 0.0 : max(0.0, (float) ($recipe->setup_time_min ?? 0));
        $batch = $recipe->is_sales_recipe ? 0.0 : max(0.0, (float) ($recipe->work_time_min ?? 0)) * $operations;
        $variable = $zeitJeMenge['min'] * $variableQuantity;
        $passive = max(0.0, (float) ($recipe->standzeit_min ?? 0));

        // Spec 83 B3c/B3j: am Gericht zwei getrennte Blöcke — Regenerieren und Anrichten (Pass einrichten entfällt).
        $bloecke = null;
        $regen = 0.0;
        $quelle = $zeitJeMenge['quelle'];
        if ($recipe->is_sales_recipe) {
            // B3i/B3l: Anrichten je Darreichungsform (Pauschale + Zeit je Einheit × Menge) ersetzt die Rechnung pro Menge
            $a = $this->anrichten($team, $recipe, $formen, $rawBatches, $zeitJeMenge);
            $variable = $a['minuten'];
            $quelle = $a['quelle'];
            $warnings = array_merge($warnings, $a['warnungen']);
            unset($a['warnungen'], $a['minuten'], $a['quelle']);
            $r = $this->regenerieren($team, $recipe, $rawBatches);
            $regen = $r['minuten'];
            $passive += $r['geraete_min'];                   // Gerätezeit: passiv, kein Lohn, nur Durchlaufzeit
            $warnings = array_merge($warnings, $r['warnungen']);
            unset($r['warnungen']);
            // B3h: beide Blöcke anteilig nach Komponenten auf die beteiligten Posten (Sammel-Pass trägt nichts)
            $anteile = $this->postenAnteile($recipe, $team);
            $bloecke = [
                'regenerieren' => ['minuten' => round($regen, 3)] + $r + ['aufteilung' => $this->verteile($anteile, $regen)],
                'anrichten' => ['minuten' => round($variable, 3), 'quelle' => $quelle,
                    'komponenten' => $zeitJeMenge['komponenten'] ?? $this->tellerKomponenten($recipe)] + $a
                    + ['aufteilung' => $this->verteile($anteile, $variable)],
            ];
        }
        $active = $setup + $batch + $regen + $variable;

        return [
            'operations' => $operations,
            'batch_limit' => $effective !== null && $effective['value'] !== null ? (float) $effective['value'] : null,
            'batch_limit_source' => $effective['source'] ?? null,
            'quantity' => max(0.0, $totalQuantity),
            'quantity_basis' => $basis,
            'setup_minutes' => round($setup, 3),
            'batch_minutes' => round($batch, 3),
            'variable_minutes' => round($variable, 3),
            'variable_quantity' => round($variableQuantity, 3),
            'variable_quantity_basis' => $variableBasis,
            'variable_time_per_unit' => round($zeitJeMenge['min'], 4),
            'variable_time_source' => $quelle,                         // gepflegt | komponenten | darreichung | startwert | vor_ort | gemischt | null
            'anrichten_komponenten' => $zeitJeMenge['komponenten'],    // nur bei abgeleiteter Anrichte-Zeit
            'active_person_minutes' => round($active, 3),
            'passive_minutes' => round($passive, 3),
            'elapsed_minutes' => round($active + $passive, 3),
            'regenerieren_minutes' => round($regen, 3),
            'bloecke' => $bloecke,                                      // nur am Gericht, sonst null
            'warnings' => $warnings,
        ];
    }

    /**
     * Spec 83 B3i/B3l: Anrichte-Zeit eines Gerichts über seine bestellten Darreichungen. Je Form: Pauschale (einmal je Zeile)
     * + Zeit je Einheit × Menge; Einheit aus {@see ANRICHTEN_FORMEN}. Wert je Einheit: an der Darreichung gepflegt > am Gericht
     * gepflegt (`variable_work_time_min`, nur Teller-Formen) > Startwert. Ohne Formen (z. B. Produktionsauftrag, Schritt 1) gilt
     * die Standard-Darreichung für alle Portionen — mit Warnung, wenn das Gericht mehrere Darreichungen hat.
     *
     * @param  list<array{darreichung_id?:?int, dar?:mixed, portionen:float}>|null  $formen
     * @param  array{min:float, basis:string, quelle:?string, komponenten:?int}  $zeitJeMenge
     * @return array{minuten:float, quelle:?string, formen:list<array<string,mixed>>, warnungen:list<string>}
     */
    public function anrichten(Team $team, FoodAlchemistRecipe $gericht, ?array $formen, ?float $ansaetze, array $zeitJeMenge): array
    {
        $ppb = (float) ($gericht->sales_unit_count ?? 0) > 0 ? (float) $gericht->sales_unit_count : 1.0;
        $warnungen = [];
        $alle = $gericht->exists ? $gericht->presentations()->with('servingForm')->get() : collect();
        if ($formen === null) {
            $standard = $alle->firstWhere('is_standard', true) ?? $alle->first();
            $formen = [['dar' => $standard, 'portionen' => max(0.0, (float) ($ansaetze ?? 0)) * $ppb]];
            if ($alle->count() > 1) {
                $warnungen[] = sprintf('Anrichten „%s“: bestellte Darreichungen unbekannt — gerechnet mit der Standard-Darreichung.', $gericht->name);
            }
        }
        $komponenten = $this->tellerKomponenten($gericht);
        $summe = 0.0;
        $raus = [];
        foreach ($formen as $f) {
            $dar = $f['dar'] ?? (isset($f['darreichung_id']) && $f['darreichung_id'] !== null ? $alle->firstWhere('id', (int) $f['darreichung_id']) : null);
            $portionen = max(0.0, (float) ($f['portionen'] ?? 0));
            if ($portionen <= 0) {
                continue;
            }
            $code = $dar?->servingForm?->code;
            $einheit = self::anrichteEinheit($code);
            if ($dar !== null && ($code === null || ! array_key_exists($code, self::ANRICHTEN_FORMEN))) {
                $warnungen[] = sprintf('Anrichten „%s“: Servierform „%s“ unbestimmt — gerechnet wie Teller.', $gericht->name, $dar->servingForm?->label ?? '?');
            }
            $gepflegt = $dar?->anrichten_wert !== null ? (float) $dar->anrichten_wert : null;
            $pauschale = $einheit['einheit'] === 'vor_ort' ? 0.0 : (float) ($dar?->anrichten_pauschal_min ?? self::ANRICHTEN_PAUSCHALE_MIN);
            $menge = $portionen;
            $wert = null;
            $minuten = 0.0;
            switch ($einheit['einheit']) {
                case 'vor_ort':
                    $quelle = 'vor_ort';
                    break;
                case 'stueck':
                    $menge = $portionen * (float) ($dar?->unit_count ?: 1);
                    $wert = $gepflegt ?? self::ANRICHTEN_SEKUNDEN_JE_STUECK;
                    $quelle = $gepflegt !== null ? 'darreichung' : 'startwert';
                    $minuten = $wert * $menge / 60;
                    break;
                case 'behaelter':
                    $b = app(BehaelterBedarfService::class)->behaelterFuerGericht($team, $gericht, $portionen / $ppb, 'ausgabe');
                    $menge = (float) $b['anzahl'];
                    $wert = $gepflegt ?? self::ANRICHTEN_MINUTEN_JE_PLATTE;
                    $quelle = $gepflegt !== null ? 'darreichung' : 'startwert';
                    $minuten = $wert * $menge;
                    if ($b['anzahl'] <= 0) {
                        $warnungen[] = sprintf('Anrichten „%s“ (%s): keine Ausgabe-Behälter hinterlegt — Platten-Zeit fehlt.', $gericht->name, $dar?->servingForm?->label ?? 'Platte');
                    }
                    break;
                default:   // portion
                    if ($gepflegt !== null) {
                        [$wert, $quelle, $minuten] = [$gepflegt, 'darreichung', $gepflegt * $portionen / 60];
                    } elseif ($zeitJeMenge['quelle'] === 'gepflegt' && $zeitJeMenge['basis'] === 'portion') {
                        [$wert, $quelle, $minuten] = [$zeitJeMenge['min'] * 60, 'gepflegt', $zeitJeMenge['min'] * $portionen];
                    } elseif ($zeitJeMenge['quelle'] === 'gepflegt') {
                        // Altwert am Gericht je kg bzw. Stück (B3 Entscheid 1: Gepflegtes gewinnt) — Menge der Portionen dieser Form
                        $gramm = $dar !== null ? (float) (app(DarreichungService::class)->grammJeEinheit($dar, $gericht) ?? 0) * (float) ($dar->unit_count ?: 1)
                            : (float) ($gericht->yield_kg_manual ?? $gericht->yield_kg ?? 0) * 1000 / $ppb;
                        $basisMenge = $zeitJeMenge['basis'] === 'kg' ? $portionen * $gramm / 1000 : $portionen * (float) ($dar?->unit_count ?: 1);
                        [$wert, $quelle, $minuten] = [null, 'gepflegt', $zeitJeMenge['min'] * $basisMenge];
                    } else {
                        $wert = $komponenten * self::ANRICHTEN_SEKUNDEN_JE_KOMPONENTE;
                        $quelle = $komponenten > 0 ? 'komponenten' : null;
                        $minuten = $wert * $portionen / 60;
                    }
            }
            $minuten += $pauschale;
            $summe += $minuten;
            $raus[] = ['darreichung_id' => $dar?->id, 'form' => $code, 'einheit' => $einheit['einheit'], 'masseinheit' => $einheit['masseinheit'],
                'portionen' => round($portionen, 3), 'menge' => round($menge, 3), 'je_einheit' => $wert !== null ? round($wert, 3) : null,
                'pauschal_min' => round($pauschale, 3), 'quelle' => $quelle, 'minuten' => round($minuten, 3)];
        }
        $quellen = array_values(array_unique(array_filter(array_column($raus, 'quelle'))));

        return ['minuten' => $summe, 'quelle' => count($quellen) > 1 ? 'gemischt' : ($quellen[0] ?? null),
            'formen' => $raus, 'warnungen' => $warnungen];
    }

    /**
     * Regenerier-Block eines Gerichts für eine Zahl Ansätze (= Portionen, s. calculateForBatches):
     * Personenzeit = Regenerier-Behälter ({@see BehaelterBedarfService::regenerierBehaelter}) × 30 s;
     * Gerätezeit = längstes Programm aus der Regenerations-Kaskade (Spec 51), getrennt und passiv.
     *
     * @return array{minuten: float, behaelter: ?int, sekunden_je_behaelter: float, geraete_min: float, quelle: ?string, warnungen: list<string>}
     */
    public function regenerieren(Team $team, FoodAlchemistRecipe $gericht, ?float $ansaetze): array
    {
        $kaskade = app(RegenerationCascadeService::class)->fuerRezept($gericht);
        $geraete = collect([...$kaskade['gesamt'], ...$kaskade['komponenten']])
            ->pluck('duration_min')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->max() ?? 0.0;
        $leer = ['minuten' => 0.0, 'behaelter' => null, 'sekunden_je_behaelter' => self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER,
            'geraete_min' => (float) $geraete, 'quelle' => null, 'warnungen' => []];
        if ($ansaetze === null || ! $gericht->exists) {
            return $leer;
        }
        $b = app(BehaelterBedarfService::class)->regenerierBehaelter($team, $gericht, $ansaetze);
        $warnungen = $b['nicht_berechenbar'] === [] ? [] : [sprintf('Regenerier-Behälter nicht berechenbar für %s.', implode(', ', $b['nicht_berechenbar']))];
        if ($b['anzahl'] <= 0) {
            return ['warnungen' => $warnungen] + $leer;
        }

        return ['minuten' => $b['anzahl'] * self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER / 60.0, 'behaelter' => $b['anzahl'],
            'sekunden_je_behaelter' => self::REGENERIEREN_SEKUNDEN_JE_BEHAELTER, 'geraete_min' => (float) $geraete,
            'quelle' => 'behaelter', 'warnungen' => $warnungen];
    }

    /**
     * Zeit pro Menge eines Rezepts. Gepflegt (`variable_work_time_min` > 0, egal welche Basis) gewinnt immer. Sonst am Gericht
     * die abgeleitete Anrichte-Zeit je Portion: Teller-Komponenten × {@see ANRICHTEN_SEKUNDEN_JE_KOMPONENTE} (Spec 83 B3).
     *
     * @return array{min: float, basis: string, quelle: ?string, komponenten: ?int}
     */
    public function zeitJeMenge(FoodAlchemistRecipe $recipe, string $fallbackBasis = 'kg'): array
    {
        $gepflegt = max(0.0, (float) ($recipe->variable_work_time_min ?? 0));
        if ($gepflegt > 0 || ! $recipe->is_sales_recipe) {
            return ['min' => $gepflegt, 'basis' => $recipe->variable_work_time_basis ?: $fallbackBasis,
                'quelle' => $gepflegt > 0 ? 'gepflegt' : null, 'komponenten' => null];
        }
        $n = $this->tellerKomponenten($recipe);

        return ['min' => $n * self::ANRICHTEN_SEKUNDEN_JE_KOMPONENTE / 60.0, 'basis' => 'portion',
            'quelle' => $n > 0 ? 'komponenten' : null, 'komponenten' => $n];
    }

    /**
     * Teller-Komponenten eines Gerichts = seine Zutatenzeilen (je Zeile ein Bestandteil, der auf den Teller kommt: Sub-Rezept
     * wie Jus/Püree oder GP wie Garnitur). Nicht gezählt: optionale Zeilen und „qs“ (Salz, Pfeffer nach Geschmack) — die werden
     * nicht einzeln angerichtet.
     */
    public function tellerKomponenten(FoodAlchemistRecipe $recipe): int
    {
        return count($this->tellerKomponentenZeilen($recipe));
    }

    /** @return list<\Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient> */
    private function tellerKomponentenZeilen(FoodAlchemistRecipe $recipe): array
    {
        if (! $recipe->exists) {
            return [];
        }
        $zeilen = $recipe->relationLoaded('ingredients') ? $recipe->ingredients : $recipe->ingredients()->with('unit')->get();

        return $zeilen->filter(fn ($z) => ! $z->is_optional && $z->unit?->slug !== 'qs')->values()->all();
    }

    /**
     * Spec 83 B3g/B3h (Entscheide Dominique 14.10.2026): Der Pass ist ein Sammelposten ohne eigenes Personal. Regenerieren und
     * Anrichten eines Gerichts belasten die BETEILIGTEN Posten anteilig nach ihren Komponenten.
     *
     * Posten einer Komponente = Posten ihres Sub-Rezepts (`default_station_id`). Posten des Gerichts = `default_station_id` bzw. die
     * Team-Vorgabe „Posten für Gerichte" ({@see TeamSettingsService::gerichtePostenId}). Das ist dieselbe Regel wie das Routing der
     * Auftragszeilen (Spec 83 B2, ProductionOrderService::standardPosten): nur für das Team sichtbare, aktive Posten zählen, ein
     * fremder Posten (z. B. am Master-Rezept im Abo) wird nicht übernommen. Komponenten ohne nutzbaren Posten gehen an den Posten
     * des Gerichts, wenn der kein Sammelposten ist; sonst als eigene Position „nicht zugeteilt" (station_id null). Geraten wird nichts.
     *
     * @return list<array{station_id:?int, komponenten:int, anteil:float}>
     */
    public function postenAnteile(FoodAlchemistRecipe $gericht, Team $team): array
    {
        $zeilen = $this->tellerKomponentenZeilen($gericht);
        $subPosten = FoodAlchemistRecipe::whereIn('id', array_filter(array_map(fn ($z) => $z->referenced_recipe_id, $zeilen)))
            ->pluck('default_station_id', 'id');
        $gerichtRoh = $gericht->default_station_id !== null ? (int) $gericht->default_station_id : null;
        $vorgabe = $this->settings->gerichtePostenId($team);
        $kandidaten = array_values(array_unique(array_filter([$gerichtRoh, $vorgabe, ...$subPosten->filter()->map(fn ($v) => (int) $v)->all()])));
        // nutzbar = sichtbar + aktiv für das Team; Wert = ist Sammelposten (Pass ohne eigenes Personal)
        $nutzbar = $kandidaten === [] ? collect() : FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
            ->whereIn('id', $kandidaten)->pluck('ist_sammelposten', 'id')->map(fn ($v) => (bool) $v);
        $traegt = fn (?int $id) => $id !== null && $nutzbar->has($id) && ! $nutzbar[$id];   // nutzbar und kein Sammelposten

        $gerichtPosten = $gerichtRoh !== null && $nutzbar->has($gerichtRoh) ? $gerichtRoh : $vorgabe;
        $gerichtPosten = $traegt($gerichtPosten) ? $gerichtPosten : null;
        if ($zeilen === []) {
            return [['station_id' => $gerichtPosten, 'komponenten' => 0, 'anteil' => 1.0]];
        }
        $je = [];
        foreach ($zeilen as $z) {
            $posten = $z->referenced_recipe_id !== null ? ($subPosten[$z->referenced_recipe_id] ?? null) : null;
            $posten = $traegt($posten !== null ? (int) $posten : null) ? (int) $posten : $gerichtPosten;
            $je[$posten ?? 0] = ($je[$posten ?? 0] ?? 0) + 1;
        }
        $n = count($zeilen);
        $raus = [];
        foreach ($je as $key => $anzahl) {
            $raus[] = ['station_id' => $key === 0 ? null : (int) $key, 'komponenten' => $anzahl, 'anteil' => round($anzahl / $n, 4)];
        }

        return $raus;
    }

    /**
     * @param  list<array{station_id:?int, komponenten:int, anteil:float}>  $anteile
     * @return list<array{station_id:?int, komponenten:int, anteil:float, minuten:float}>
     */
    private function verteile(array $anteile, float $minuten): array
    {
        if ($minuten <= 0) {
            return [];
        }
        $summe = array_sum(array_column($anteile, 'komponenten')) ?: 1;

        return array_map(fn ($a) => $a + ['minuten' => round($minuten * ($a['komponenten'] ?: $summe) / $summe, 3)], $anteile);
    }
}
