<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M10R-1 / Doc 15 §10.5 + §10.8: Voll-Aggregation Gericht → Paket → Concept.
 * Rollt aus den VK-Gerichten hoch: Nährwerte/Person · Allergene/Diät · Kosten
 * (EK = HK1-Proxy / VK) · Produktionszeit (`work_time_min`). Speist die
 * Kalkulation, die deterministische Menü-Bewertung (M10R-3) und gibt der KI
 * Kontext + Selbst-Check (§10.10).
 *
 * Ehrlichkeits-Prinzip (wie Allergen-Rollup): Nährwerte werden NUR aus Gerichten
 * gerechnet, die sowohl Nährwert-Daten (pro 100 g) ALS AUCH ein Portionsgewicht
 * (`sales_quantity_per_unit_g`) haben — fehlt eins, trägt das Gericht nicht bei und
 * die Konfidenz sinkt (statt erfundener Zahlen). Konfidenz = schwächstes Glied.
 *
 * Mengen-Modell (Dominique-Entscheid 2026-06-15, unit-abhängig): pro Position wird
 * ein Portions-Äquivalent gebildet — Einheit Portion/Stück (oder keine) → `quantity` direkt
 * (Default 1.0); Gramm-Einheit → `quantity`×g/Einheit ÷ Portionsgramm. EK = ek_total_eur ÷
 * Portionszahl × Portions-Äquivalent. EINE Stelle (`portionsAequivalent()`), genutzt von
 * ConceptService::preisCockpit und PaketService::recomputePrice (Konsistenz-Garant).
 * Ehrlich: Gramm-Position ohne Rezept-Portionsgewicht trägt nicht bei (statt Phantasie-Zahl).
 *
 * Pure (keine Service-Abhängigkeiten) — wird von ConceptService/PaketService und den
 * Livewire-Panels genutzt, niemals direkt von Models (Plattform-Gebot).
 */
class ConcepterAggregateService
{
    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    /** Umbau-Spec Phase 5: löst je Position die geltende Darreichung auf (Preis-Wahrheit). */
    public function __construct(private DarreichungResolver $darreichungen) {}

    /** Recipe-Spalten, die alle Rollups brauchen (ein Select, kein N+1). */
    private function recipeCols(): array
    {
        return [
            'id', 'name', 'sales_wording_standard',             // Wording-Kette (Menü-Ansicht) — load() hier überschreibt sonst detail()
            'sales_net', 'ek_total_eur', 'work_time_min', 'sales_unit_count', 'sales_quantity_per_unit_g',
            'is_sales_recipe',                            // Basis vs. VK — Paket-Posten-Badge + g/P-EK-Zweig
            'yield_kg', 'yield_pieces',                     // Stück-Modus (kg↔Stück): Teiler/Gramm aus Ertrag+Yield
            'nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g',
            'nutri_carbs_g_per_100g', 'nutri_salt_g_per_100g', 'nutri_confidence',
            'nutri_sugar_g_per_100g', 'nutri_saturated_fat_g_per_100g',
            'spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_gluten_free',
            'spec_is_lactose_free', 'spec_contains_pork', 'spec_contains_beef', 'allergens_confidence',
        ];
    }

    /** Einheiten-Spalten für die Mengen-Umrechnung (ein Select, kein N+1). */
    private function einheitCols(): array
    {
        return ['id', 'slug', 'dimension', 'default_in_g'];
    }

    /**
     * Portions-Äquivalent einer Position: wie viele Rezept-Portionen pro Person die Menge
     * entspricht. KANONISCHE Mengen-Umrechnung — von ConceptService::preisCockpit und
     * PaketService::recomputePrice mitgenutzt, damit alle EK-Stellen identisch rechnen.
     *
     * - Portion/Stück oder KEINE Einheit → `quantity` direkt (Default 1.0).
     * - Gramm-Einheit (dimension=mass, default_in_g>0) → `quantity`×g/Einheit ÷ Portionsgramm.
     * - Gramm gewählt, aber Portionsgewicht (sales_quantity_per_unit_g) fehlt → null
     *   (Position trägt ehrlich NICHT bei, statt eine erfundene Zahl).
     */
    public static function portionsAequivalent(?float $quantity, ?object $unit, ?object $gericht, ?float $portionGOverride = null): ?float
    {
        $istGramm = $unit !== null
            && $unit->dimension === 'mass'
            && $unit->default_in_g !== null
            && (float) $unit->default_in_g > 0;

        if (! $istGramm) {
            return $quantity !== null ? $quantity : 1.0;
        }

        if ($quantity === null) {
            return null;
        }
        // Umbau-Spec Phase 5: Grammatur der aufgelösten Darreichung gewinnt gegen die Legacy-Spalte
        $portionG = $portionGOverride ?? $gericht?->sales_quantity_per_unit_g;
        if ($portionG === null || (float) $portionG <= 0) {
            return null;
        }

        return ($quantity * (float) $unit->default_in_g) / (float) $portionG;
    }

    /**
     * Stück-Modus (kg↔Stück): greift, wenn die Position eine Zähl-Einheit (Portion/Stück) trägt
     * UND das Rezept einen Ertrag in Stück (`yield_pieces`) hat. Dann ist 1 verrechnete Einheit
     * = 1 Stück → EK/Stück = ek_total_eur / yield_pieces, Gramm/Stück = yield_g / yield_pieces.
     * Rückwärtskompatibel: ohne `yield_pieces` (alle Bestandsdaten) nie aktiv.
     */
    public static function stueckModus(?object $unit, ?object $gericht): bool
    {
        return $unit !== null
            && ($unit->dimension ?? null) === 'count'
            && $gericht !== null
            && $gericht->yield_pieces !== null
            && (float) $gericht->yield_pieces > 0;
    }

    // ── Paket-Aggregat ──────────────────────────────────────────────────────

    /**
     * @return array{n_gerichte:int, naehrwerte:array, allergene:array,
     *               ek_pro_person:float, vk_summe:float, work_time_min:int}
     */
    public function paketAggregat(FoodAlchemistPaket $paket): array
    {
        return $this->aggregat($this->paketPositionen($paket));
    }

    /**
     * Die (gericht, quantity, unit, darreichung)-Liste eines Pakets — eine Quelle für
     * Aggregat UND Deklarationsblatt, damit beide über dieselbe Grundmenge reden.
     *
     * @return Collection<int, array{gericht: object, quantity: ?float, unit: ?object, darreichung: mixed}>
     */
    public function paketPositionen(FoodAlchemistPaket $paket): Collection
    {
        // load() statt loadMissing(): erzwingt den vollen Spalten-Satz, auch wenn der
        // Aufrufer die Gerichte schon mit reduzierten Spalten geladen hat (z. B. detail()).
        $paket->load([
            'dishes' => fn ($q) => $q->orderBy('position'),
            'dishes.unit' => fn ($q) => $q->select($this->einheitCols()),
            'dishes.dish' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        return $paket->dishes
            ->map(fn ($pg) => ['gericht' => $pg->dish, 'quantity' => $pg->quantity, 'unit' => $pg->unit,
                'darreichung' => $pg->dish !== null ? $this->darreichungen->fuerPaketGericht($pg) : null])
            ->filter(fn ($r) => $r['gericht'] !== null)->values();
    }

    // ── Concept-Aggregat (über Pakete + feste Gerichte) ──────────────────────

    /**
     * @return array{n_gerichte:int, n_slots:int, naehrwerte:array, allergene:array,
     *               ek_pro_person:float, vk_summe:float, work_time_min:int}
     */
    public function conceptAggregat(FoodAlchemistConcept $concept): array
    {
        $mitMenge = $this->conceptPositionen($concept);

        return ['n_slots' => $concept->slots->count()] + $this->aggregat($mitMenge);
    }

    /**
     * Die (gericht, quantity, unit, darreichung)-Liste eines Konzepts — über Pakete und
     * feste Gerichte hinweg. Eine Quelle für Aggregat UND Deklarationsblatt.
     *
     * @return Collection<int, array{gericht: object, quantity: ?float, unit: ?object, darreichung: mixed}>
     */
    public function conceptPositionen(FoodAlchemistConcept $concept): Collection
    {
        // load() statt loadMissing(): erzwingt den vollen Recipe-Spalten-Satz, auch wenn
        // der Aufrufer Slots/Gerichte schon mit reduzierten Spalten geladen hat (detail()).
        $concept->load([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.unit' => fn ($q) => $q->select($this->einheitCols()),
            'slots.package.dishes' => fn ($q) => $q->orderBy('position'),
            'slots.package.dishes.unit' => fn ($q) => $q->select($this->einheitCols()),
            'slots.package.dishes.dish' => fn ($q) => $q->select($this->recipeCols()),
            'slots.dish' => fn ($q) => $q->select($this->recipeCols()),
        ]);

        $mitMenge = collect();
        foreach ($concept->slots as $slot) {
            $slot->setRelation('concept', $concept); // Resolver braucht die Konzept-Servierform ohne Lazy-Load
            if ($slot->package) {
                foreach ($slot->package->dishes as $pg) {
                    if ($pg->dish) {
                        $mitMenge->push(['gericht' => $pg->dish, 'quantity' => $pg->quantity, 'unit' => $pg->unit,
                            'darreichung' => $this->darreichungen->fuerPaketGericht($pg)]);
                    }
                }
            } elseif ($slot->dish) {
                $mitMenge->push(['gericht' => $slot->dish, 'quantity' => $slot->quantity, 'unit' => $slot->unit,
                    'darreichung' => $this->darreichungen->fuerSlot($slot)]);
            }
        }

        return $mitMenge;
    }

    // ── Kern: Aggregat über eine (gericht, quantity)-Liste ─────────────────────

    /** @param Collection<int, array{gericht: object, quantity: ?float}> $mitMenge */
    private function aggregat(Collection $mitMenge): array
    {
        $ek = 0.0;
        $vk = 0.0;
        $zeit = 0;
        $ekPositionen = 0;
        $ekBeitragend = 0;
        $gewicht = 0.0;                 // Σ Effektiv-Gramm/Person
        $gewichtVollstaendig = true;    // false, sobald eine Position keine belastbare Gramm-Angabe hat
        foreach ($mitMenge as $r) {
            $zeit += (int) ($r['gericht']->work_time_min ?? 0);   // Σ roh (Planungsproxy), mengenunabhängig
            $ekPositionen++;

            // Basisrezept-Posten (Paket/Concept): Menge = GRAMM/Person → Beitrag als Batch-Bruchteil
            // (g/Person ÷ Batch-Gramm) für EK/Zeit/Gewicht; kein Einzel-VK. Stück-Modus hat
            // VORRANG: Basisrezept mit Zähl-Einheit + yield_pieces (Törtchen) rechnet weiter
            // über den Portion/Stück-Pfad — sonst würde die Stückzahl als Gramm verrechnet.
            if (! (bool) ($r['gericht']->is_sales_recipe ?? true)
                && ! self::stueckModus($r['unit'] ?? null, $r['gericht'])) {
                $yieldG = (float) ($r['gericht']->yield_kg ?? 0) * 1000;
                $mengeG = $r['quantity'] !== null ? (float) $r['quantity'] : null;
                if ($mengeG === null || $mengeG <= 0 || $yieldG <= 0) {
                    $gewichtVollstaendig = false;

                    continue;
                }
                $bruch = $mengeG / $yieldG;
                $ekBeitragend++;
                $ek += (float) ($r['gericht']->ek_total_eur ?? 0) * $bruch;
                $gewicht += $mengeG;

                continue;
            }

            $dar = $r['darreichung'] ?? null;
            $darPortionG = $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null;
            $pae = self::portionsAequivalent(
                $r['quantity'] !== null ? (float) $r['quantity'] : null,
                $r['unit'] ?? null,
                $r['gericht'],
                $darPortionG,
            );
            if ($pae === null) {
                $gewichtVollstaendig = false; // unbekannte Menge → auch Gewicht unvollständig
                continue; // Gramm-Position ohne Portionsgewicht → trägt ehrlich nicht bei
            }
            $ekBeitragend++;
            $stueck = self::stueckModus($r['unit'] ?? null, $r['gericht']);
            // Teiler von ek_total: Stück-Modus → yield_pieces, sonst Portionszahl (Batch→Portion).
            $anzahl = $stueck ? (float) $r['gericht']->yield_pieces : max(1, (int) ($r['gericht']->sales_unit_count ?? 1));
            // Umbau-Spec Phase 5: EK/VK der aufgelösten Darreichung gewinnt (exakt je Form,
            // inkl. Komponenten-Deltas); Legacy-Spalten nur noch Fallback.
            if ($dar?->ek_portion !== null && ! $stueck) {
                $ek += (float) $dar->ek_portion * $pae;
            } else {
                $ek += (float) ($r['gericht']->ek_total_eur ?? 0) / $anzahl * $pae;
            }
            $vk += (float) ($dar?->sales_net ?? $r['gericht']->sales_net ?? 0) * $pae;
            // Gewicht/Person = Portions-Äquivalent × Gramm je Einheit. Stück-Modus: yield_g / yield_pieces;
            // sonst Portionsgramm. Fehlt die Basis → Gewicht unvollständig (ehrlich).
            if ($stueck) {
                $yieldKg = $r['gericht']->yield_kg;
                if ($yieldKg !== null && (float) $yieldKg > 0) {
                    $gewicht += $pae * ((float) $yieldKg * 1000 / (float) $r['gericht']->yield_pieces);
                } else {
                    $gewichtVollstaendig = false;
                }
            } else {
                $portionG = $darPortionG ?? $r['gericht']->sales_quantity_per_unit_g;
                if ($portionG !== null && (float) $portionG > 0) {
                    $gewicht += $pae * (float) $portionG;
                } else {
                    $gewichtVollstaendig = false;
                }
            }
        }

        // Allergene: je Gericht EINMAL (Eigenschaft, nicht Portion) → dedupe.
        $distinkt = $mitMenge->pluck('gericht')->filter()->unique('id')->values();

        return [
            'n_gerichte' => $distinkt->count(),
            'naehrwerte' => $this->naehrwertAggregat($mitMenge),
            'allergene' => $this->allergenRollupFromGerichte($distinkt),
            'ek_per_person' => round($ek, 4),
            'ek_n_positionen' => $ekPositionen,               // kostentragende Positionen gesamt
            'ek_n_beitragend' => $ekBeitragend,               // davon mit belastbarem EK (Lücke = ehrlich aus)
            'vk_summe' => round($vk, 2),
            'gewicht_pro_person_g' => round($gewicht),        // Σ Effektiv-Gramm/Person
            'gewicht_vollstaendig' => $gewichtVollstaendig,   // false → ≥1 Position ohne Portionsgewicht (Gewicht unvollständig)
            'work_time_min' => $zeit,                       // Σ roher Rezept-Arbeitszeit (Planungsproxy)
            // Ohne Pax gibt es keinen belastbaren Produktionslauf. Zeit/HK2 kommen ausschließlich
            // aus OrderCostingService und werden hier bewusst nicht auf Portionen heruntergeraten.
            'arbeitszeit_min_pro_portion' => null,
        ];
    }

    /**
     * Nährwerte/Person = Σ (pro 100 g × Portionsgramm/100 × Menge-Faktor).
     *
     * @param  Collection<int, array{gericht: object, quantity: ?float}>  $mitMenge
     * @return array{kcal:?float, protein_g:?float, fett_g:?float, kh_g:?float, salz_g:?float,
     *               zucker_g:?float, gesfett_g:?float,
     *               n_gerichte:int, n_mit_naehrwerten:int, vollstaendig:bool, konfidenz:string}
     */
    public function naehrwertAggregat(Collection $mitMenge): array
    {
        $felder = [
            'kcal' => 'nutri_kcal_per_100g', 'protein' => 'nutri_protein_g_per_100g',
            'fett' => 'nutri_fat_g_per_100g', 'kh' => 'nutri_carbs_g_per_100g',
            'salz' => 'nutri_salt_g_per_100g',
            'zucker' => 'nutri_sugar_g_per_100g', 'gesfett' => 'nutri_saturated_fat_g_per_100g',
        ];
        $summe = ['kcal' => 0.0, 'protein' => 0.0, 'fett' => 0.0, 'kh' => 0.0, 'salz' => 0.0, 'zucker' => 0.0, 'gesfett' => 0.0];
        // Coverage 2026-08-13 auf DISTINKTE Gerichte umgestellt (Dominique): der Header „Aggregiert aus
        // N Gerichten" (Allergen-Rollup) dedupliziert ebenfalls → beide Labels müssen dieselbe Grundmenge
        // zeigen (vorher lief hier der Positions-Zähler → 5 vs. 6). Die SUMME bleibt bewusst über ALLE
        // Positionen: ein doppelt eingesetztes Gericht = 2 Portionen/Person und trägt korrekt doppelt bei.
        $beitragPositionen = 0;   // Positionen mit gültigem Beitrag → treibt Summe + null-Entscheidung
        $minKonf = null;
        $dishSeen = [];           // dish_id => true  (Nenner = distinkte Gerichte)
        $dishOk = [];             // dish_id => true  (mind. eine Position hat beigetragen)
        $dishFail = [];           // dish_id => true  (mind. eine Position ohne Nährwert/Portionsgramm)

        foreach ($mitMenge as $row) {
            $g = $row['gericht'] ?? null;
            if ($g === null) {
                continue;
            }
            $gid = $g->id ?? spl_object_id($g);
            $dishSeen[$gid] = true;
            $hatNutri = $g->nutri_kcal_per_100g !== null;

            // Basisrezept: Menge = GRAMM/Person → Nährwert = pro 100 g × g/Person ÷ 100
            // (kein Portionsgramm nötig). Zweig nur bei is_sales_recipe=0.
            if (! (bool) ($g->is_sales_recipe ?? true)) {
                $mengeG = $row['quantity'] !== null ? (float) $row['quantity'] : null;
                if ($mengeG === null || $mengeG <= 0 || ! $hatNutri) {
                    $dishFail[$gid] = true;

                    continue;
                }
                $faktorBasis = $mengeG / 100.0;
                foreach ($felder as $key => $spalte) {
                    if ($g->{$spalte} !== null) {
                        $summe[$key] += (float) $g->{$spalte} * $faktorBasis;
                    }
                }
                $beitragPositionen++;
                $dishOk[$gid] = true;
                $kB = self::KONF_RANG[$g->nutri_confidence] ?? 0;
                $minKonf = $minKonf === null ? $kB : min($minKonf, $kB);

                continue;
            }

            $portionG = $g->sales_quantity_per_unit_g !== null ? (float) $g->sales_quantity_per_unit_g : null;
            $pae = self::portionsAequivalent(
                $row['quantity'] !== null ? (float) $row['quantity'] : null,
                $row['unit'] ?? null,
                $g,
            );
            if ($portionG === null || $portionG <= 0 || ! $hatNutri || $pae === null) {
                $dishFail[$gid] = true;

                continue; // unvollständig — trägt nicht bei, deckelt später die Konfidenz
            }
            // Effektive Gramm/Person = Portions-Äquivalent × Portionsgramm.
            $faktor = $pae * ($portionG / 100.0);
            foreach ($felder as $key => $spalte) {
                if ($g->{$spalte} !== null) {
                    $summe[$key] += (float) $g->{$spalte} * $faktor;
                }
            }
            $beitragPositionen++;
            $dishOk[$gid] = true;
            $k = self::KONF_RANG[$g->nutri_confidence] ?? 0;
            $minKonf = $minKonf === null ? $k : min($minKonf, $k);
        }

        // Ein Gericht zählt als „hat Nährwert + Portionsgramm", wenn ALLE seine Positionen beigetragen
        // haben — sonst ist die Summe für dieses Gericht eine Untergrenze und es zählt ehrlich als Lücke.
        $nTotal = count($dishSeen);
        $nOk = count(array_filter(array_keys($dishSeen), fn ($id) => isset($dishOk[$id]) && ! isset($dishFail[$id])));

        $vollstaendig = $nTotal > 0 && $nOk === $nTotal;
        $konfRang = $minKonf ?? 0;
        if (! $vollstaendig) {
            $konfRang = min($konfRang, self::KONF_RANG['low']); // Lücken → max „low"
        }

        return [
            'kcal' => $beitragPositionen ? round($summe['kcal']) : null,
            'protein_g' => $beitragPositionen ? round($summe['protein'], 1) : null,
            'fett_g' => $beitragPositionen ? round($summe['fett'], 1) : null,
            'kh_g' => $beitragPositionen ? round($summe['kh'], 1) : null,
            'salz_g' => $beitragPositionen ? round($summe['salz'], 2) : null,
            'zucker_g' => $beitragPositionen ? round($summe['zucker'], 1) : null,
            'gesfett_g' => $beitragPositionen ? round($summe['gesfett'], 1) : null,
            'n_gerichte' => $nTotal,
            'n_mit_naehrwerten' => $nOk,
            'vollstaendig' => $vollstaendig,
            'confidence' => $beitragPositionen === 0 ? 'unknown' : (array_search($konfRang, self::KONF_RANG, true) ?: 'unknown'),
        ];
    }

    /**
     * Kanonische Allergen-/Diät-Rollup-Stelle (Doc 15 §10.5: „aus den Gerichten,
     * kein manuelles Gruppieren"). „all"-Flags (vegan/vegetarisch/halal/glutenfrei/
     * laktosefrei) nur wenn ALLE Gerichte sie erfüllen; „enthält"-Flags (Schwein/
     * Rind) bei MIND. EINEM. Konfidenz = schwächstes Glied.
     *
     * @param  Collection<int, object>  $gerichte  bereits deduplizierte Recipe-Sammlung
     * @return array{n_gerichte:int, is_vegan:bool, is_vegetarian:bool, is_halal:bool,
     *               is_gluten_free:bool, is_lactose_free:bool, contains_pork:bool,
     *               contains_beef:bool, konfidenz:string}
     */
    public function allergenRollupFromGerichte(Collection $gerichte): array
    {
        $gerichte = $gerichte->filter()->unique('id')->values();
        $alle = fn (string $feld) => $gerichte->isNotEmpty() && $gerichte->every(fn ($g) => (bool) $g->{$feld});
        $eines = fn (string $feld) => $gerichte->contains(fn ($g) => (bool) $g->{$feld});
        $minKonf = $gerichte->isEmpty() ? 0 : $gerichte->min(fn ($g) => self::KONF_RANG[$g->allergens_confidence] ?? 0);

        return [
            'n_gerichte' => $gerichte->count(),
            'is_vegan' => $alle('spec_is_vegan'),
            'is_vegetarian' => $alle('spec_is_vegetarian'),
            'is_halal' => $alle('spec_is_halal'),
            'is_gluten_free' => $alle('spec_is_gluten_free'),
            'is_lactose_free' => $alle('spec_is_lactose_free'),
            'contains_pork' => $eines('spec_contains_pork'),
            'contains_beef' => $eines('spec_contains_beef'),
            'confidence' => array_search($minKonf, self::KONF_RANG, true) ?: 'unknown',
        ];
    }

    /**
     * Spec 31 (GV-Ausbau) — LMIV-Kennzeichnungs-Rollup: die 14 EU-Allergene + 18 Zusatzstoffe
     * (GL-01/GL-09) über eine Gerichte-Sammlung, ALL-MAXIMAL. Anders als
     * {@see allergenRollupFromGerichte} (nur Diät-Flags) liefert dies die deklarationspflichtigen
     * LISTEN für den Speiseplan-Aushang.
     *
     * Aggregation je Merkmal, rechtssicher (Unbekanntes zählt NIE als „frei"):
     * - Allergen (`allergen_*` ∈ enthalten|spuren|nicht_enthalten|unbekannt):
     *   ein »enthalten« → enthalten; sonst ein »spuren« → spuren; sonst ein »unbekannt« → unbekannt;
     *   sonst (alle bekannt frei) → nicht_enthalten.
     * - Zusatzstoff (`additive_*` ∈ 3=ja|1=frei|0/NULL=unbekannt): ein 3 → ja; sonst ein 0/NULL →
     *   unbekannt; sonst (alle 1) → frei.
     * Konfidenz = schwächstes Glied (`allergens_confidence`).
     *
     * @param  Collection<int, object>  $gerichte  Recipe-Sammlung mit geladenen allergen_ und additive_ Spalten
     * @return array{n_gerichte:int, confidence:string,
     *               allergene:list<array{slug:string,label:string,status:string}>,
     *               zusatzstoffe:list<array{slug:string,label:string,status:string}>}
     */
    public function kennzeichnungFromGerichte(Collection $gerichte): array
    {
        $gerichte = $gerichte->filter()->unique('id')->values();

        $allergene = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $werte = $gerichte->map(fn ($g) => $g->{"allergen_{$slug}"} ?? 'unbekannt');
            $status = match (true) {
                $gerichte->isEmpty() => 'unbekannt',
                $werte->contains('enthalten') => 'enthalten',
                $werte->contains('spuren') => 'spuren',
                $werte->contains('unbekannt') => 'unbekannt',
                default => 'nicht_enthalten',
            };
            $allergene[] = ['slug' => $slug, 'label' => $label, 'status' => $status];
        }

        $zusatzstoffe = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $werte = $gerichte->map(fn ($g) => $g->{"additive_{$slug}"});
            $status = match (true) {
                $gerichte->isEmpty() => 'unbekannt',
                $werte->contains(fn ($v) => (int) $v === 3) => 'ja',
                $werte->contains(fn ($v) => $v === null || (int) $v === 0) => 'unbekannt',
                default => 'frei',
            };
            $zusatzstoffe[] = ['slug' => $slug, 'label' => $label, 'status' => $status];
        }

        $minKonf = $gerichte->isEmpty() ? 0 : $gerichte->min(fn ($g) => self::KONF_RANG[$g->allergens_confidence] ?? 0);

        return [
            'n_gerichte' => $gerichte->count(),
            'confidence' => array_search($minKonf, self::KONF_RANG, true) ?: 'unknown',
            'allergene' => $allergene,
            'zusatzstoffe' => $zusatzstoffe,
        ];
    }

    // ── §-Kennzeichnung: Code-Katalog + PER-GERICHT-Codes + Legende ──────────────
    // Eine Stelle für Speisekarte + Foodbook (kein Code-Drift). Codes sind GERICHT-bezogen
    // (Dominique 2026-08-25: „pro Gericht, nicht pro Konzept"), Legende = nur real Vorkommendes.

    /**
     * §-Kennzeichnungs-Code-Katalog (LMIV/ZZulV): Allergene = Buchstaben in EU-Reihenfolge,
     * Zusatzstoffe = Nummern.
     *
     * @return array{allergene: array<string,array{code:string,label:string}>, zusatzstoffe: array<string,array{code:string,label:string}>}
     */
    public function kennzeichnungKatalog(): array
    {
        $alg = [];
        $i = 0;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $alg[$slug] = ['code' => chr(65 + $i), 'label' => $label];
            $i++;
        }
        $zus = [];
        $j = 1;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $zus[$slug] = ['code' => (string) $j, 'label' => $label];
            $j++;
        }

        return ['allergene' => $alg, 'zusatzstoffe' => $zus];
    }

    /**
     * §-Codes GENAU EINES Gerichts (nicht aggregiert): Allergen-Buchstaben (+* bei Spuren) +
     * Zusatzstoff-Nummern aus der Gericht-Deklaration. Sammelt die real vorkommenden Slugs in
     * $usedAlg/$usedZus (by-ref) für die Legende.
     *
     * @return list<string>
     */
    public function gerichtCodes(FoodAlchemistRecipe $gericht, array &$usedAlg, array &$usedZus, ?array $katalog = null): array
    {
        $katalog ??= $this->kennzeichnungKatalog();
        $k = $this->kennzeichnungFromGerichte(collect([$gericht]));
        $codes = [];
        foreach ($k['allergene'] as $a) {
            if (in_array($a['status'], ['enthalten', 'spuren'], true)) {
                $usedAlg[$a['slug']] = true;
                $codes[] = $katalog['allergene'][$a['slug']]['code'] . ($a['status'] === 'spuren' ? '*' : '');
            }
        }
        foreach ($k['zusatzstoffe'] as $z) {
            if ($z['status'] === 'ja') {
                $usedZus[$z['slug']] = true;
                $codes[] = $katalog['zusatzstoffe'][$z['slug']]['code'];
            }
        }

        return $codes;
    }

    /**
     * Codes an eine ZEILEN-Liste hängen (jede Zeile mit `recipe_id`) + Legende dazu.
     *
     * Dritte Kopie vermieden: FoodbookService und SpeisekarteService führten die by-ref-
     * Sammelmechanik je selbst; die Concept-Karte und der Concept-Report brauchten sie als
     * Nächstes. Ein `arrow fn` scheidet dabei aus — `$usedAlg`/`$usedZus` MÜSSEN by-ref
     * laufen, sonst bleibt die Legende leer.
     *
     * @param  list<array<string, mixed>>  $zeilen
     * @return array{zeilen: list<array<string, mixed>>, legende: array{allergene: list<array{code:string,label:string}>, zusatzstoffe: list<array{code:string,label:string}>}}
     */
    public function codesFuerZeilen(array $zeilen, string $idFeld = 'recipe_id'): array
    {
        $ids = collect($zeilen)->pluck($idFeld)->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $katalog = $this->kennzeichnungKatalog();
        $usedAlg = [];
        $usedZus = [];

        if ($ids !== []) {
            // Volle Modelle: die Deklarations-Spalten müssen geladen sein, sonst liefert
            // `gerichtCodes()` still leere Codes (nicht geladenes Attribut = null).
            $dishes = $this->mitDeklarationsSpalten($ids)->keyBy('id');
            foreach ($zeilen as $i => $z) {
                $rid = isset($z[$idFeld]) ? (int) $z[$idFeld] : null;
                $zeilen[$i]['codes'] = $rid !== null && $dishes->get($rid) !== null
                    ? $this->gerichtCodes($dishes->get($rid), $usedAlg, $usedZus, $katalog)
                    : [];
            }
        }

        return [
            'zeilen' => $zeilen,
            'legende' => $this->kennzeichnungLegende($usedAlg, $usedZus, $katalog),
        ];
    }

    /**
     * Legende (nur real vorkommende Codes) aus den by-ref gesammelten Slugs.
     *
     * @return array{allergene: list<array{code:string,label:string}>, zusatzstoffe: list<array{code:string,label:string}>}
     */
    public function kennzeichnungLegende(array $usedAlg, array $usedZus, ?array $katalog = null): array
    {
        $katalog ??= $this->kennzeichnungKatalog();
        $alg = [];
        foreach ($katalog['allergene'] as $slug => $cl) {
            if (! empty($usedAlg[$slug])) {
                $alg[] = $cl;
            }
        }
        $zus = [];
        foreach ($katalog['zusatzstoffe'] as $slug => $cl) {
            if (! empty($usedZus[$slug])) {
                $zus[] = $cl;
            }
        }

        return ['allergene' => $alg, 'zusatzstoffe' => $zus];
    }

    // ── Deklarationsblatt (Concepter/Paket-Tab „Deklaration") ────────────────
    // Entscheid Dominique 2026-09-04: die Deklaration gehört JE GERICHT (rechtlich ist sie
    // je Speise geschuldet, nie je Angebot); übergeordnet stehen nur Tags. Der reine
    // ALL-MAXIMAL-Rollup war auf dieser Ebene wertlos — ein Gericht mit Gluten machte das
    // ganze Konzept „glutenhaltig", was mathematisch stimmt und niemandem hilft.
    //
    // Zusatzstoffe sind bewusst mit drin: sie werden in Foodbook und Speisekarte ohnehin
    // deklariert (FoodbookService/SpeisekarteService nutzen dieselben Bausteine) — der
    // Editor war die einzige Fläche, die sie verschwieg.

    /**
     * Lädt die Deklarations-Spalten (allergen_* / additive_* + Spezifikation) zu einer
     * ID-Liste nach und behält deren Reihenfolge. IDs, die es nicht mehr gibt, fallen
     * heraus — das ist gewollt.
     *
     * @param  list<int>  $ids
     * @return Collection<int, FoodAlchemistRecipe>
     */
    private function mitDeklarationsSpalten(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return collect();
        }

        $cols = ['id', 'name', 'is_sales_recipe', 'sales_quantity_per_unit_g', 'nutri_kcal_per_100g',
            'nutri_confidence', 'allergens_confidence', 'spec_is_vegan', 'spec_is_vegetarian',
            'spec_is_halal', 'spec_is_gluten_free', 'spec_is_lactose_free', 'spec_contains_pork',
            'spec_contains_beef', 'yield_kg', 'yield_pieces', 'sales_unit_count'];
        foreach (array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE) as $slug) {
            $cols[] = "allergen_{$slug}";
        }
        foreach (array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE) as $slug) {
            $cols[] = "additive_{$slug}";
        }

        $geladen = FoodAlchemistRecipe::whereIn('id', $ids)->get($cols)->keyBy('id');

        return collect($ids)->map(fn ($id) => $geladen->get($id))->filter()->values();
    }

    /**
     * Ein Deklarationsblatt über eine (gericht, quantity, unit)-Liste.
     *
     * @param  Collection<int, array{gericht: object, quantity: ?float, unit: ?object}>  $mitMenge
     * @param  string  $naehrwertModus  'summe' (Menü/Paket — der Gast isst alles) oder
     *                                  'spanne' (Auswahl à la carte — eine Summe wäre Unsinn)
     * @return array{
     *   zeilen: list<array{id:int,name:string,codes:list<string>,kcal:?float,portion_g:?float,
     *                      confidence:string,diaet:list<string>,fehlt:list<string>}>,
     *   legende: array{allergene: list<array{code:string,label:string}>, zusatzstoffe: list<array{code:string,label:string}>},
     *   quoten: array{n:int,vegetarisch:int,vegan:int,glutenfrei:int,laktosefrei:int,halal:int,
     *                 schwein:list<string>,rind:list<string>},
     *   luecken: list<array{name:string,fehlt:list<string>}>,
     *   modus: string, vollstaendig: bool, confidence: string, schwaechstes: ?string}
     */
    public function deklarationsblatt(Collection $mitMenge, string $naehrwertModus = 'summe'): array
    {
        $katalog = $this->kennzeichnungKatalog();
        $usedAlg = [];
        $usedZus = [];

        // Distinkt je Gericht: ein zweimal eingesetztes Gericht ist EINE Deklarationszeile.
        // (Für die Nährwert-SUMME zählt es doppelt — das macht naehrwertAggregat, nicht hier.)
        $roh = $mitMenge->map(fn ($r) => $r['gericht'] ?? null)->filter()->unique('id')->values();
        $mengeJeGericht = [];
        foreach ($mitMenge as $r) {
            $g = $r['gericht'] ?? null;
            if ($g === null) {
                continue;
            }
            $mengeJeGericht[$g->id] ??= ['quantity' => $r['quantity'] ?? null, 'unit' => $r['unit'] ?? null];
        }

        // Die Deklarations-Spalten NACHLADEN: `recipeCols()` führt sie nicht (14 Allergen-
        // Strings + die Zusatzstoff-Flags würden jedes Concept-Aggregat verteuern), und ein
        // nicht geladenes Eloquent-Attribut liefert still `null` — `gerichtCodes()` hätte
        // dann für jedes Gericht LEERE Codes gemeldet, ohne Fehler. Spaltenliste explizit
        // aus den Konstanten statt `select('*')`: `recipes` trägt JSON-Spalten.
        $gerichte = $this->mitDeklarationsSpalten($roh->pluck('id')->all());

        $zeilen = [];
        $luecken = [];
        $kcalWerte = [];
        $minKonf = null;
        $schwaechstes = null;

        foreach ($gerichte as $g) {
            $istGericht = (bool) ($g->is_sales_recipe ?? true);
            $portionG = $g->sales_quantity_per_unit_g !== null ? (float) $g->sales_quantity_per_unit_g : null;
            $kcal100 = $g->nutri_kcal_per_100g !== null ? (float) $g->nutri_kcal_per_100g : null;

            // Was fehlt, wird BENANNT statt in eine Sammelkonfidenz gedrückt: „8 von 9 fehlen"
            // ist eine Zahl, „Gericht X hat kein Portionsgramm" ist eine Arbeitsanweisung.
            $fehlt = [];
            if ($kcal100 === null) {
                $fehlt[] = 'Nährwerte';
            }
            if ($istGericht && ($portionG === null || $portionG <= 0)) {
                $fehlt[] = 'Portionsgramm';
            }
            if (($g->allergens_confidence ?? 'none') === 'none') {
                $fehlt[] = 'Allergen-Profil';
            }

            // kcal-Bedeutung folgt dem Modus: bei einer Auswahl interessiert die PORTION
            // („wenn der Gast das nimmt"), bei Menü/Paket der BEITRAG/Person — dann erklären
            // die Zeilen sichtbar die Summe darunter.
            $kcal = null;
            if ($kcal100 !== null) {
                if ($naehrwertModus === 'spanne' && $istGericht) {
                    $kcal = $portionG !== null && $portionG > 0 ? round($kcal100 * $portionG / 100.0) : null;
                } elseif ($istGericht) {
                    $pae = self::portionsAequivalent(
                        $mengeJeGericht[$g->id]['quantity'] ?? null,
                        $mengeJeGericht[$g->id]['unit'] ?? null,
                        $g,
                    );
                    $kcal = $pae !== null && $portionG !== null && $portionG > 0
                        ? round($kcal100 * $pae * $portionG / 100.0) : null;
                } else {
                    // Basisrezept: Menge ist GRAMM/Person, kein Portionsgramm nötig.
                    $mengeG = $mengeJeGericht[$g->id]['quantity'] ?? null;
                    $kcal = $mengeG !== null && (float) $mengeG > 0 ? round($kcal100 * (float) $mengeG / 100.0) : null;
                }
            }
            if ($kcal !== null) {
                $kcalWerte[] = $kcal;
            }

            $diaet = [];
            foreach (['spec_is_vegan' => 'vegan', 'spec_is_vegetarian' => 'vegetarisch',
                'spec_is_gluten_free' => 'glutenfrei', 'spec_is_lactose_free' => 'laktosefrei',
                'spec_is_halal' => 'halal'] as $feld => $tag) {
                if ((bool) ($g->{$feld} ?? false)) {
                    $diaet[] = $tag;
                }
            }
            // vegan impliziert vegetarisch — beide Pills nebeneinander sind Rauschen.
            if (in_array('vegan', $diaet, true)) {
                $diaet = array_values(array_diff($diaet, ['vegetarisch']));
            }

            $konf = (string) ($g->allergens_confidence ?? 'none');
            $rang = self::KONF_RANG[$konf] ?? 0;
            if ($minKonf === null || $rang < $minKonf) {
                $minKonf = $rang;
                $schwaechstes = (string) $g->name;
            }

            $zeilen[] = [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'codes' => $this->gerichtCodes($g, $usedAlg, $usedZus, $katalog),
                'kcal' => $kcal,
                'portion_g' => $portionG,
                'confidence' => $konf,
                'diaet' => $diaet,
                'fehlt' => $fehlt,
            ];
            if ($fehlt !== []) {
                $luecken[] = ['name' => (string) $g->name, 'fehlt' => $fehlt];
            }
        }

        $zaehl = fn (string $feld) => $gerichte->filter(fn ($g) => (bool) ($g->{$feld} ?? false))->count();
        $namen = fn (string $feld) => $gerichte->filter(fn ($g) => (bool) ($g->{$feld} ?? false))
            ->map(fn ($g) => (string) $g->name)->values()->all();

        return [
            'zeilen' => $zeilen,
            'legende' => $this->kennzeichnungLegende($usedAlg, $usedZus, $katalog),
            'quoten' => [
                'n' => $gerichte->count(),
                // Quoten statt Alles-oder-nichts: „3 von 9 vegetarisch" ist die Aussage, die
                // ein Kunde hören will — der ALL-Rollup sagte dazu nur „nicht vegetarisch".
                'vegetarisch' => $zaehl('spec_is_vegetarian'),
                'vegan' => $zaehl('spec_is_vegan'),
                'glutenfrei' => $zaehl('spec_is_gluten_free'),
                'laktosefrei' => $zaehl('spec_is_lactose_free'),
                'halal' => $zaehl('spec_is_halal'),
                'schwein' => $namen('spec_contains_pork'),
                'rind' => $namen('spec_contains_beef'),
            ],
            'luecken' => $luecken,
            'modus' => $naehrwertModus,
            'vollstaendig' => $luecken === [],
            'confidence' => $minKonf === null ? 'unknown' : (array_search($minKonf, self::KONF_RANG, true) ?: 'unknown'),
            'schwaechstes' => $schwaechstes,
            // Spanne nur, wenn es etwas zu spannen gibt (mind. zwei Werte).
            'kcal_min' => $kcalWerte === [] ? null : min($kcalWerte),
            'kcal_max' => $kcalWerte === [] ? null : max($kcalWerte),
            'kcal_schnitt' => $kcalWerte === [] ? null : round(array_sum($kcalWerte) / count($kcalWerte)),
        ];
    }

    /**
     * Deklarationsblatt eines Konzepts. Der Nährwert-Modus folgt der PREISDARSTELLUNG:
     *
     * - `gesamt` (ein Preis fürs Konzept) → der Gast isst alles → SUMME/Person ist richtig.
     * - `einzel` (à la carte, Auswahl) → niemand isst alle Positionen → eine Summe wäre
     *   sinnlos, und „Untergrenze" wäre sogar falsch: es gibt keine untere Grenze, sondern
     *   eine SPANNE über die Gerichte.
     */
    public function conceptDeklaration(FoodAlchemistConcept $concept): array
    {
        $modus = $concept->istEinzelpreis() ? 'spanne' : 'summe';   // eine Quelle für die Einzelpreis-Frage

        return $this->deklarationsblatt($this->conceptPositionen($concept), $modus);
    }

    /** Deklarationsblatt eines Pakets — ein Paket ist immer ein Gesamtpreis, also Summe. */
    public function paketDeklaration(FoodAlchemistPaket $paket): array
    {
        return $this->deklarationsblatt($this->paketPositionen($paket), 'summe');
    }

    // ── Cache-Persistenz ─────────────────────────────────────────────────────

    public function cachePaket(FoodAlchemistPaket $paket): FoodAlchemistPaket
    {
        $agg = $this->paketAggregat($paket);
        $paket->update([
            'nutrition_cache' => $agg['naehrwerte'],
            'work_time_min_cache' => $agg['work_time_min'],
        ]);

        return $paket->refresh();
    }

    public function cacheConcept(FoodAlchemistConcept $concept): FoodAlchemistConcept
    {
        $agg = $this->conceptAggregat($concept);
        $concept->update([
            'nutrition_cache' => $agg['naehrwerte'],
            'work_time_min_cache' => $agg['work_time_min'],
            'ek_per_person_cache' => $agg['ek_per_person'],
        ]);

        return $concept->refresh();
    }
}
