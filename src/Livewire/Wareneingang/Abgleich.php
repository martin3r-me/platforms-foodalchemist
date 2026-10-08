<?php

namespace Platform\FoodAlchemist\Livewire\Wareneingang;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;
use Platform\FoodAlchemist\Services\TripleMatchService;

/**
 * Spec 75b · Reiter Abgleich: Triple Match über alle Bestellzeilen (bestellt | geliefert | berechnet),
 * sortiert nach Schwere und Euro-Wirkung. Aus dem Befund heraus: Reklamation anlegen.
 */
class Abgleich extends Component
{
    public ?int $fLieferant = null;

    public bool $nurAbweichung = true;

    public int $tage = 90;

    public string $tolPct = '';

    public string $tolEur = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function reklamieren(int $orderLineId, TripleMatchService $tm, LieferantenRechnungService $re): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $zeile = collect($tm->abgleich($this->team(), ['supplier_id' => $this->fLieferant, 'tage' => $this->tage])['zeilen'])
                ->firstWhere('order_line_id', $orderLineId) ?? throw new \RuntimeException('Position nicht mehr im Abgleich.');
            $menge = $zeile['delta_menge_re'] ?? ($zeile['berechnet'] !== null && $zeile['geliefert'] === null ? $zeile['berechnet'] : null);
            $re->reklamieren($this->team(), $orderLineId, [
                'claim_status' => 'credit_expected',
                'claim_qty_packs' => $menge !== null ? abs((float) $menge) : null,
                'credit_expected_net' => $zeile['delta_eur'] > 0 ? $zeile['delta_eur'] : null,
                'claim_note' => $zeile['label'].' ('.implode(', ', $zeile['rechnungen']).')',
            ], Auth::id());
            $this->hinweis = 'Reklamation angelegt: '.$zeile['designation'].'.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function toleranzSpeichern(TripleMatchService $tm): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        $zahl = fn (string $v) => trim($v) === '' ? null : (float) str_replace(',', '.', trim($v));
        try {
            $tm->setzeToleranz($this->team(), $zahl($this->tolPct), $zahl($this->tolEur), Auth::id());
            $this->hinweis = 'Toleranz gespeichert.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(TripleMatchService $tm, FaRechte $rechte)
    {
        $team = $this->team();
        $a = $tm->abgleich($team, ['supplier_id' => $this->fLieferant, 'nur_abweichung' => $this->nurAbweichung, 'tage' => $this->tage]);

        return view('foodalchemist::livewire.wareneingang.abgleich', [
            'zeilen' => $a['zeilen'],
            'toleranz' => $a['toleranz'],
            'kpis' => [
                ['label' => 'Abweichungen', 'value' => $a['kpis']['abweichungen'], 'primary' => true, 'kpi' => 'abweichungen'],
                ['label' => 'Summe Abweichungen', 'value' => number_format($a['kpis']['summe_abweichung_eur'], 2, ',', '.').' €', 'tone' => $a['kpis']['summe_abweichung_eur'] > 0 ? 'warn' : null],
                ['label' => 'Rechnungen in Prüfung', 'value' => $a['kpis']['rechnungen_in_pruefung']],
                ['label' => 'Geliefert, nicht berechnet', 'value' => $a['kpis']['nicht_berechnet'], 'title' => 'älter als '.TripleMatchService::NICHT_BERECHNET_TAGE.' Tage'],
                ['label' => 'Erwartete Gutschriften', 'value' => number_format($a['kpis']['gutschriften_erwartet_eur'], 2, ',', '.').' €'],
            ],
            'lieferanten' => FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->get(['id', 'name']),
            'darf' => $rechte->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'istAdmin' => $rechte->darf(Auth::user(), $team, FaRolle::Admin),
        ]);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
