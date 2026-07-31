<?php

namespace Platform\FoodAlchemist\Livewire\Einkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\RebateService;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\Suche;
use RuntimeException;

/**
 * Einkauf E3 — Einkaufs-Cockpit (Startseite des „reinen Einkäufers").
 *
 * Der Cross-Lieferanten-Preisvergleich, den es bisher nur eingebettet im GP-Detail gab,
 * als eigenständige Such-Fläche: pro Grundprodukt der günstigste/teuerste Lieferant +
 * Preisspanne — sauberer als das Kollegen-Tool, weil über die GP↔LA-Abstraktion statt
 * über Artikelnamen-Strings. Optionaler „inkl. Rückvergütung"-Schalter rechnet den
 * effektiven Netto-Preis (RebateService-Overlay), Filter nach Lieferant + Warengruppe.
 *
 * Such-first (wie das Vorbild): ohne Suche/Filter ein freundlicher Leerzustand statt
 * einer riesigen Tabelle. Ergebnis auf {@see self::MAX} gedeckelt — enger filtern.
 * Read-only-Vergleich; team-scoped. Reuse: LeadLaService::rangliste + RebateService.
 */
class Cockpit extends Component
{
    private const MAX = 60;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'wg')]
    public string $wgCode = '';

    #[Url(as: 'sup')]
    public ?int $supplierId = null;

    #[Url(as: 'rv')]
    public bool $mitRabatt = false;

    public ?string $hinweis = null;

    public ?string $fehler = null;

    /**
     * Einkauf E3: den günstigsten Lieferantenartikel eines GP in die Bestellschiene
     * seines Lieferanten übernehmen (1 Gebinde). OrderService holt/erstellt den Draft
     * und upsertet die Zeile (D1-gescopt). Das ist die Kern-Aktion des „reinen Einkäufers".
     */
    public function uebernehmen(int $laId): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet.';

            return;
        }
        try {
            $line = app(OrderService::class)->addManualLine($team, $laId, 1.0, 'aus Preisvergleich', Auth::id());
            $this->hinweis = '„' . ($line->designation ?? 'Artikel') . '" in die Bestellschiene übernommen (1 Gebinde).';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(LeadLaService $lead, RebateService $rebate)
    {
        $team = Auth::user()?->currentTeamRelation;
        $aktiv = trim($this->q) !== '' || $this->wgCode !== '' || $this->supplierId !== null;

        $zeilen = [];
        $gekappt = false;

        if ($team !== null && $aktiv) {
            $gps = FoodAlchemistGp::visibleToTeam($team)
                ->where('status', 'approved')
                ->when($this->wgCode !== '', fn ($qb) => $qb->where('commodity_group_code', $this->wgCode))
                ->when(trim($this->q) !== '', fn ($qb) => Suche::likeAny($qb, ['name', 'gp_key'], $this->q))
                ->orderBy('name')
                ->limit(self::MAX + 1)
                ->get();

            $gekappt = $gps->count() > self::MAX;
            foreach ($gps->take(self::MAX) as $gp) {
                $kette = $lead->rangliste($gp, $team);
                if ($this->mitRabatt) {
                    $rebate->enrichRangliste($team, $kette, $gp->commodity_group_code);
                }
                $zeile = $this->fasseZusammen($gp, $kette);
                if ($zeile !== null) {
                    $zeilen[] = $zeile;
                }
            }
            // Günstigste zuerst wäre irreführend (verschiedene Einheiten) — nach Name stabil.
        }

        return view('foodalchemist::livewire.einkauf.cockpit', [
            'zeilen' => $zeilen,
            'gekappt' => $gekappt,
            'aktiv' => $aktiv,
            'max' => self::MAX,
            'lieferanten' => $team !== null
                ? FoodAlchemistSupplier::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
            'warengruppen' => $team !== null
                ? app(VocabularyService::class)->listWarengruppen($team)
                : collect(),
        ])->layout('platform::layouts.app');
    }

    /**
     * Pro GP: günstigster/teuerster Lieferant + Spanne über den (optional rabatt-)
     * effektiven Vergleichspreis. Bei gesetztem Lieferant-Filter nur GPs, die dieser
     * Lieferant führt, plus die Info, ob er der günstigste ist.
     */
    private function fasseZusammen(FoodAlchemistGp $gp, $kette): ?array
    {
        $preisVon = fn ($la) => $this->mitRabatt
            ? ($la->vergleichspreis_mit_rabatt_wert ?? null)
            : ($la->vergleichspreis_wert ?? null);

        $bepreist = $kette->filter(fn ($la) => $preisVon($la) !== null)->values();
        if ($bepreist->isEmpty()) {
            return null;   // ohne Vergleichspreis kein Vergleich
        }

        $sortiert = $bepreist->sortBy(fn ($la) => (float) $preisVon($la))->values();
        $guenstigster = $sortiert->first();
        $teuerster = $sortiert->last();
        $minP = (float) $preisVon($guenstigster);
        $maxP = (float) $preisVon($teuerster);

        // Lieferant-Filter: GP muss diesen Lieferanten führen.
        $eigner = null;
        if ($this->supplierId !== null) {
            $eigner = $bepreist->firstWhere('supplier_id', $this->supplierId);
            if ($eigner === null) {
                return null;
            }
        }

        return [
            'gp_id' => (int) $gp->id,
            'name' => $gp->name,
            'wg' => $gp->commodity_group_code,
            'n' => $bepreist->count(),
            'guenstigster_la_id' => (int) $guenstigster->id,
            'guenstigster_supplier' => $guenstigster->supplier_name,
            'guenstigster_preis' => $minP,
            'teuerster_supplier' => $teuerster->supplier_name,
            'teuerster_preis' => $maxP,
            'spanne_pct' => $minP > 0 ? round(($maxP - $minP) / $minP * 100, 1) : null,
            'einheit' => $guenstigster->vergleichspreis_einheit ?? ($guenstigster->unit_code ?? ''),
            'filter_supplier_preis' => $eigner !== null ? (float) $preisVon($eigner) : null,
            'filter_supplier_ist_guenstigster' => $eigner !== null
                && (int) $eigner->supplier_id === (int) $guenstigster->supplier_id,
        ];
    }
}
