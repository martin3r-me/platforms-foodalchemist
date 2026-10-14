<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;

/**
 * Spec 82 Abo für Pakete, PR 3 (Preis im Mandantenkontext, §12 C): Preis und EK eines Pakets je (Paket × Team).
 *
 * Keine neue Wahrheit: gerechnet wird ausschließlich mit {@see KostenCacheService} (Team-EK der Rezept-Fassung) und
 * {@see TeamVkService} (Team-VK der gewählten Darreichung). Es wird nichts gespeichert und keine Master-Spalte gelesen.
 *
 *  - Paket-FASSUNG (Abo): immer live aus den Rezept-Fassungen, mit den Preisen und dem Basissatz des betrachtenden Teams.
 *  - Eigene Pakete (auch die eigene Kopie eines Abo-Pakets): gespeicherte Spalten wie bisher; PaketService::recomputePrice
 *    ruft {@see berechne}, sobald ein Gericht eine Rezept-Fassung ist (die Kopie zeigt auf Fassungen).
 *
 * Auflage 4 / Fehlerklasse #322: ist irgendein Posten ohne vollständigen EK oder ohne VK, gibt es für das ganze Paket
 * „kein Preis" (null, `vollstaendig=false`, Grund + fehlende Rezepte) — nie eine Teilsumme, nie 0 €.
 */
class PaketPreisService
{
    public function __construct(
        private KostenCacheService $kosten,
        private TeamVkService $teamVk,
        private MargeService $marge,
    ) {}

    /**
     * Preis eines Pakets für ein betrachtendes Team. Fassungen live; alles andere = gespeicherte Spalten (Master unverändert).
     *
     * @return array{vk_pro_person: ?float, ek_pro_person: ?float, food_cost_percent: ?float, vollstaendig: bool, grund: ?string, fehlende: list<string>, quelle: string}
     */
    public function fuer(FoodAlchemistPaket $paket, Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        if (! $paket->ist_fassung) {
            return [
                'vk_pro_person' => $paket->price_per_person !== null ? (float) $paket->price_per_person : null,
                'ek_pro_person' => $paket->ek_per_person !== null ? (float) $paket->ek_per_person : null,
                'food_cost_percent' => $paket->food_cost_percent !== null ? (float) $paket->food_cost_percent : null,
                'vollstaendig' => $paket->price_per_person !== null,
                'grund' => $paket->price_per_person !== null ? null : 'kein_preis',
                'fehlende' => [], 'quelle' => 'gespeichert',
            ];
        }

        return $this->berechne($paket, $team, $outlet);
    }

    /**
     * Für Lese-Sichten (Browser, Detail, Kandidaten, Kalkulation): legt bei einer Abo-Fassung die Team-Preise als reine
     * Speicher-Attribute über price_per_person/ek_per_person/food_cost_percent (nie gespeichert — eine Fassung ist
     * schreibgeschützt und gehört dem Master). Andere Pakete bleiben unverändert (Master sieht seinen Preis wie bisher).
     */
    public function anwenden(FoodAlchemistPaket $paket, Team $team): FoodAlchemistPaket
    {
        if (! $paket->ist_fassung) {
            return $paket;
        }
        $r = $this->berechne($paket, $team);
        $paket->setAttribute('price_per_person', $r['vk_pro_person']);
        $paket->setAttribute('ek_per_person', $r['ek_pro_person']);
        $paket->setAttribute('food_cost_percent', $r['food_cost_percent']);
        $paket->setAttribute('price_mode', 'auto');
        // doppelt abgesichert: nicht dirty (kein Pfad schreibt die Werte versehentlich mit) + Merker, der jedes save() wirft
        $paket->syncOriginalAttributes(['price_per_person', 'ek_per_person', 'food_cost_percent', 'price_mode']);
        $paket->preisOverlay = true;

        return $paket;
    }

    /**
     * Rechnet die Gerichte eines Pakets mit den Preisen des Teams, soweit sie Rezept-Fassungen sind; eigene (Arbeits-)Rezepte
     * laufen wie in PaketService::recomputePrice über ihre gespeicherten Werte.
     *
     * @return array{vk_pro_person: ?float, ek_pro_person: ?float, food_cost_percent: ?float, vollstaendig: bool, grund: ?string, fehlende: list<string>, quelle: string}
     */
    public function berechne(FoodAlchemistPaket $paket, Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $gerichte = $paket->dishes()->with(['dish', 'unit:id,slug,dimension,default_in_g', 'presentation'])->get();
        if ($gerichte->isEmpty()) {
            return $this->ergebnis(null, null, false, 'keine_gerichte', []);
        }

        $vk = 0.0;
        $ek = 0.0;
        $grund = null;
        $fehlende = [];
        foreach ($gerichte as $g) {
            $rezept = $g->dish;
            if ($rezept === null) {
                continue;   // gelöschtes Rezept fällt weg (wie im Arbeitsstand)
            }
            $posten = $rezept->ist_fassung
                ? $this->postenAusFassung($g, $team, $outlet)
                : $this->postenAusArbeitsstand($g);
            if ($posten === null) {
                continue;   // Gramm-Position ohne Portionsgewicht trägt ehrlich nicht bei
            }
            if ($posten['grund'] !== null) {
                $grund ??= $posten['grund'];
                $fehlende[] = (string) $rezept->name;

                continue;
            }
            $vk += $posten['vk'];
            $ek += $posten['ek'];
        }

        if ($grund !== null) {
            return $this->ergebnis(null, null, false, $grund, array_values(array_unique($fehlende)));
        }

        return $this->ergebnis($vk > 0 ? round($vk, 2) : null, $ek > 0 ? round($ek, 4) : null, true, $vk > 0 ? null : 'kein_preis', []);
    }

    /**
     * Ein Posten, dessen Rezept eine Fassung ist: Team-EK und Team-VK über genau die beiden Dienste.
     *
     * @return array{vk: float, ek: float, grund: null}|array{grund: string}|null  null = trägt nicht bei (kein Portionsgewicht)
     */
    private function postenAusFassung(FoodAlchemistPaketGericht $g, Team $team, ?FoodAlchemistOutlet $outlet): ?array
    {
        $rezept = $g->dish;
        if (! (bool) ($rezept->is_sales_recipe ?? true)) {
            // Basisrezept-Posten: Menge/Person = GRAMM; EK = g/Person ÷ Batch-Gramm × Team-Batch-EK; kein Einzel-VK
            $k = $this->kosten->fuer($rezept, $team);
            $yieldG = (float) ($rezept->yield_kg ?? 0) * 1000;
            $mengeG = $g->quantity !== null ? (float) $g->quantity : null;
            if ($yieldG <= 0 || $mengeG === null || $mengeG <= 0) {
                return null;
            }
            if (! $k['vollstaendig'] || $k['ek_summe'] === null) {
                return ['grund' => 'ek_unvollstaendig'];
            }

            return ['vk' => 0.0, 'ek' => (float) $k['ek_summe'] * ($mengeG / $yieldG), 'grund' => null];
        }

        $dar = app(DarreichungResolver::class)->fuerPaketGericht($g);
        $pae = ConcepterAggregateService::portionsAequivalent(
            $g->quantity !== null ? (float) $g->quantity : null,
            $g->unit,
            $rezept,
            $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null,
        );
        if ($pae === null) {
            return null;
        }
        $r = $this->teamVk->fuer($rezept, $team, $outlet, $dar);
        if ($r['vk'] === null || $r['mek'] === null) {
            return ['grund' => (string) ($r['grund'] ?? 'kein_vk')];
        }

        return ['vk' => $r['vk'] * $pae, 'ek' => $r['mek'] * $pae, 'grund' => null];
    }

    /**
     * Ein Posten mit eigenem Rezept (Arbeitszeile des Besitzers): gespeicherte Werte wie PaketService::recomputePrice.
     *
     * @return array{vk: float, ek: float, grund: null}|null
     */
    private function postenAusArbeitsstand(FoodAlchemistPaketGericht $g): ?array
    {
        $rezept = $g->dish;
        if (! (bool) ($rezept->is_sales_recipe ?? true)) {
            $yieldG = (float) ($rezept->yield_kg ?? 0) * 1000;
            $mengeG = $g->quantity !== null ? (float) $g->quantity : null;

            return $yieldG > 0 && $mengeG !== null && $mengeG > 0
                ? ['vk' => 0.0, 'ek' => (float) ($rezept->ek_total_eur ?? 0) * ($mengeG / $yieldG), 'grund' => null]
                : null;
        }
        $dar = app(DarreichungResolver::class)->fuerPaketGericht($g);
        $pae = ConcepterAggregateService::portionsAequivalent(
            $g->quantity !== null ? (float) $g->quantity : null,
            $g->unit,
            $rezept,
            $dar?->quantity_per_unit_g !== null ? (float) $dar->quantity_per_unit_g : null,
        );
        if ($pae === null) {
            return null;
        }
        $anzahl = max(1, (int) ($rezept->sales_unit_count ?? 1));

        return [
            'vk' => (float) ($dar?->sales_net ?? $rezept->sales_net ?? 0) * $pae,
            'ek' => $dar?->ek_portion !== null ? (float) $dar->ek_portion * $pae : (float) ($rezept->ek_total_eur ?? 0) / $anzahl * $pae,
            'grund' => null,
        ];
    }

    /** @param list<string> $fehlende */
    private function ergebnis(?float $vk, ?float $ek, bool $vollstaendig, ?string $grund, array $fehlende): array
    {
        $m = $this->marge->marge($vk, $ek);

        return ['vk_pro_person' => $vk, 'ek_pro_person' => $ek, 'food_cost_percent' => $m['wareneinsatz_pct'] ?? null,
            'vollstaendig' => $vollstaendig && $vk !== null, 'grund' => $grund, 'fehlende' => $fehlende, 'quelle' => 'team_kalkulation'];
    }
}
