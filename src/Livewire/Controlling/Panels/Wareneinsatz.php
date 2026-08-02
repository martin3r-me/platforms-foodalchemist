<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\EinkaufOptimizerService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\MargeImpactService;
use RuntimeException;

/**
 * Einkauf E4 — Wareneinsatz-Optimierung: der Ist-Wareneinsatz aus dem Einkaufsjournal
 * gegenüber dem optimalen Bezug (günstigster Lieferant) — als Listenpreis UND inkl.
 * Rückvergütung — plus die größten Einsparpotenziale. „Lieferant ausklammern" spielt
 * Was-wäre-wenn-Szenarien durch. Braucht Journal-Daten (FA-Bestellungen geliefert bzw.
 * Necta-Import); ohne Journal ein Leerzustand. team-scoped.
 *
 * **Spec 32:** war bis 2026-08-02 die eigene Seite `/einkauf/optimierung` (Livewire
 * `Einkauf\Optimierung`), ist jetzt Panel im Wareneinsatz-Tab des Controlling-Zentrums —
 * und hat dort den Hebel bekommen, der ihr gefehlt hat: die **Batch-Umstellung** der
 * markierten Positionen auf den jeweils günstigsten Lieferanten.
 *
 * Zwei Dinge sind an dem Batch nicht verhandelbar:
 *
 *  1. **Vorschau vor Ausführung.** Eine Umstellung ändert den EK jedes Rezepts, in dem das
 *     Grundprodukt steckt — das kann das halbe Portfolio sein. Was die Aktion anfasst, muss
 *     vorher dastehen ({@see self::vorschau}, gerechnet über `MargeImpactService`).
 *  2. **Ein Recompute-Lauf, nicht N.** Deshalb setzt der Batch je GP OHNE `recompute` und ruft
 *     am Ende einmal {@see LeadLaService::recomputeNutzerFuerGps} über die Vereinigung (V-049).
 */
class Wareneinsatz extends Component
{
    /** So viele Positionen zeigt die Vorschau namentlich; der Rest wird als Zahl ausgewiesen. */
    private const VORSCHAU_MAX = 20;

    /** @var list<int> ausgeklammerte Lieferanten (Session-State, Was-wäre-wenn) */
    public array $excludeSupplierIds = [];

    /** @var list<int> markierte Grundprodukte für die Batch-Umstellung (wire:model) */
    public array $auswahl = [];

    /** Ergebnis von {@see self::vorschau} — null = keine Vorschau offen. */
    public ?array $vorschau = null;

    public ?string $hinweis = null;

    public ?string $fehler = null;

    /**
     * Request-lokaler Puffer für den Optimizer-Lauf. Der Lauf geht über das ganze Journal und
     * holt je Grundprodukt die Lieferanten-Rangliste — ihn in einem Request zweimal zu fahren
     * (einmal für die Aktion, einmal fürs anschließende `render()`) wäre glatte Verschwendung.
     * Privat, also nicht Teil des Livewire-Zustands: der Puffer stirbt mit dem Request.
     */
    private ?array $laufCache = null;

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    /** Der Optimizer-Lauf für diesen Request (memoisiert, s. {@see self::$laufCache}). */
    private function lauf(EinkaufOptimizerService $optimizer, Team $team): array
    {
        return $this->laufCache ??= $optimizer->optimieren($team, array_map('intval', $this->excludeSupplierIds));
    }

    /** Alle umstellbaren Zeilen markieren (die bereits optimalen bringen nichts). */
    public function alleWaehlen(EinkaufOptimizerService $optimizer): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }

        $this->auswahl = array_values(array_map(
            fn ($z) => (int) $z['gp_id'],
            array_filter($this->lauf($optimizer, $team)['top'], fn ($z) => ! $z['lead_ist_optimal']),
        ));
        $this->vorschau = null;
    }

    public function auswahlLeeren(): void
    {
        $this->auswahl = [];
        $this->vorschau = null;
    }

    /**
     * Was würde die Umstellung anfassen? Zeigt die betroffenen Grundprodukte, die daran
     * hängenden Rezepte/Gerichte und die Summe der Einsparung — VOR dem Schreiben.
     */
    public function vorschau(EinkaufOptimizerService $optimizer, MargeImpactService $impact): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $team = $this->team();
        if ($team === null || $this->auswahl === []) {
            $this->fehler = $team === null ? 'Kein Team zugeordnet.' : 'Nichts markiert.';

            return;
        }

        $zeilen = $this->markierteZeilen($optimizer, $team);
        if ($zeilen === []) {
            $this->fehler = 'Die Markierung enthält keine umstellbare Position mehr.';

            return;
        }

        // Betroffene Menge = Direktnutzer + transitive Eltern (dieselbe Kaskade, die der
        // Recompute danach fährt). Die Gerichte-Zahl ist die, die Dominique interessiert:
        // wie viele VERKAUFSfähige Kalkulationen verschieben sich durch diesen Klick.
        $gpIds = array_map(fn ($z) => (int) $z['gp_id'], $zeilen);
        $recipeIds = $impact->betroffeneRezepte($gpIds);
        $nGerichte = $recipeIds === [] ? 0 : FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', $recipeIds)->where('is_sales_recipe', true)->count();

        $this->vorschau = [
            'n_gps' => count($zeilen),
            'ersparnis' => round(array_sum(array_map(fn ($z) => (float) $z['saving_rebate'], $zeilen)), 2),
            'n_rezepte' => count($recipeIds),
            'n_gerichte' => $nGerichte,
            'gps' => array_map(fn ($z) => [
                'name' => $z['name'],
                'nach' => $z['cheapest_rebate_supplier'],
                'ersparnis' => $z['saving_rebate'],
            ], array_slice($zeilen, 0, self::VORSCHAU_MAX)),
            'gekuerzt' => max(0, count($zeilen) - self::VORSCHAU_MAX),
        ];
    }

    /**
     * Die Umstellung ausführen: je markiertem GP den Lead auf den (rückvergütungs-)günstigsten
     * Artikel setzen, danach EINMAL die Rezeptbaum-Kaskade über alle betroffenen GPs.
     */
    public function umstellen(EinkaufOptimizerService $optimizer, LeadLaService $lead): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $team = $this->team();
        if ($team === null || $this->auswahl === []) {
            $this->fehler = $team === null ? 'Kein Team zugeordnet.' : 'Nichts markiert.';

            return;
        }

        $zeilen = $this->markierteZeilen($optimizer, $team);
        if ($zeilen === []) {
            $this->fehler = 'Die Markierung enthält keine umstellbare Position mehr.';

            return;
        }

        $gesetzt = [];
        $fehlgeschlagen = 0;
        foreach ($zeilen as $z) {
            $gp = FoodAlchemistGp::visibleToTeam($team)->whereKey($z['gp_id'])->first();
            if ($gp === null) {
                $fehlgeschlagen++;

                continue;
            }
            try {
                // recompute: false — die Kaskade läuft gleich EINMAL über die ganze Menge.
                $lead->setLeadLa($team, $gp, (int) $z['cheapest_rebate_la_id'],
                    'Controlling · Wareneinsatz-Batch', recompute: false);
                $gesetzt[] = (int) $gp->id;
            } catch (RuntimeException) {
                // Eine einzelne verweigerte Zeile (z. B. LA nicht mehr am GP) darf den Rest
                // nicht kippen — sie wird gezählt und am Ende ehrlich ausgewiesen.
                $fehlgeschlagen++;
            }
        }

        $nRezepte = $lead->recomputeNutzerFuerGps($gesetzt);

        // Der gepufferte Lauf beschreibt den Stand VOR der Umstellung — sonst zeigte die Tabelle
        // nach dem Klick unverändert die alten Einsparpotenziale.
        $this->laufCache = null;
        $this->auswahl = [];
        $this->vorschau = null;
        $this->hinweis = count($gesetzt) . ' Bezugsquelle(n) umgestellt, ' . $nRezepte . ' Rezept(e) nachgerechnet.'
            . ($fehlgeschlagen > 0 ? ' ' . $fehlgeschlagen . ' Position(en) übersprungen.' : '');
    }

    /**
     * Die markierten Zeilen aus dem AKTUELLEN Optimizer-Lauf — bewusst neu gerechnet und nicht
     * aus einem mitgeschleppten Zustand: zwischen Markieren und Klicken kann ein Preis gefallen
     * sein. Bereits optimale Positionen fallen raus, sonst schriebe der Batch No-ops.
     *
     * @return list<array<string,mixed>>
     */
    private function markierteZeilen(EinkaufOptimizerService $optimizer, Team $team): array
    {
        $ids = array_map('intval', $this->auswahl);

        return array_values(array_filter(
            $this->lauf($optimizer, $team)['top'],
            fn ($z) => in_array((int) $z['gp_id'], $ids, true) && ! $z['lead_ist_optimal'],
        ));
    }

    public function render(EinkaufOptimizerService $optimizer)
    {
        $team = $this->team();

        // Nach einer Umstellung ist der Lauf von vorhin überholt (neue Leads ⇒ neue Optimal-Zeilen).
        // Der Puffer wird deshalb in `umstellen()` verworfen, nicht hier pauschal.
        $r = $team !== null
            ? $this->lauf($optimizer, $team)
            : ['ist_total' => 0.0, 'optimal_list_total' => 0.0, 'optimal_rebate_total' => 0.0,
                'saving_list' => 0.0, 'saving_rebate' => 0.0, 'saving_list_pct' => null,
                'saving_rebate_pct' => null, 'top' => [], 'n_articles' => 0, 'n_skipped' => 0];

        return view('foodalchemist::livewire.controlling.panels.wareneinsatz', [
            'r' => $r,
            'lieferanten' => $team !== null
                ? FoodAlchemistSupplier::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
}
