<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoiceLine;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;

/**
 * Spec 75b · Triple Match — EINE Rechenstelle für Bestellung ⇄ Lieferschein ⇄ Rechnung.
 * Rein lesend; UI (Reiter Abgleich, Rechnungsprüfung), Freigabe und MCP nutzen dieselben Regeln.
 *
 * Je Bestellzeile zwei Teilbefunde:
 *  - Lieferung (`ls`): offen | ok | zu_wenig | zu_viel — geliefert gegen bestellt
 *  - Rechnung (`re`):  offen | ok | menge | preis | nicht_geliefert — berechnet gegen geliefert,
 *    Rechnungspreis gegen Bestellpreis (Toleranz je Team)
 * Gesamtstatus = der schwerste Befund. „Geliefert, nicht berechnet" nach NICHT_BERECHNET_TAGE Tagen.
 */
class TripleMatchService
{
    public const TOLERANZ_PCT_DEFAULT = 0.5;

    public const TOLERANZ_EUR_DEFAULT = 0.05;

    public const NICHT_BERECHNET_TAGE = 14;

    /** Schwere für die Sortierung/Ableitung des Gesamtstatus. */
    public const SCHWERE = [
        'nicht_geliefert' => 6, 'menge' => 5, 'preis' => 4, 'zu_viel' => 3, 'zu_wenig' => 3,
        'nicht_berechnet' => 2, 'offen' => 1, 'ok' => 0,
    ];

    public const LABELS = [
        'ok' => 'passt', 'offen' => 'offen', 'zu_wenig' => 'zu wenig geliefert', 'zu_viel' => 'zu viel geliefert',
        'menge' => 'Menge Rechnung ≠ Lieferung', 'preis' => 'Preis weicht ab', 'nicht_geliefert' => 'berechnet, nicht geliefert',
        'nicht_berechnet' => 'geliefert, nicht berechnet',
    ];

    /** @return array{pct: float, eur: float} */
    public function toleranz(Team $team): array
    {
        $s = FoodAlchemistTeamSetting::where('team_id', $team->id)->first();

        return [
            'pct' => $s?->tm_toleranz_preis_pct !== null ? (float) $s->tm_toleranz_preis_pct : self::TOLERANZ_PCT_DEFAULT,
            'eur' => $s?->tm_toleranz_preis_eur !== null ? (float) $s->tm_toleranz_preis_eur : self::TOLERANZ_EUR_DEFAULT,
        ];
    }

    /** Toleranzen setzen — nur FA-Admin (Spec 61). null = Standard. */
    public function setzeToleranz(Team $team, ?float $pct, ?float $eur, ?int $userId = null): void
    {
        app(FaRechte::class)->pruefeId($userId, $team, \Platform\FoodAlchemist\Enums\FaRolle::Admin, 'Toleranzen festlegen');
        if (($pct !== null && $pct < 0) || ($eur !== null && $eur < 0)) {
            throw new \RuntimeException('Toleranzen dürfen nicht negativ sein.');
        }
        FoodAlchemistTeamSetting::updateOrCreate(['team_id' => $team->id], ['tm_toleranz_preis_pct' => $pct, 'tm_toleranz_preis_eur' => $eur]);
    }

    /** Preis passt, wenn die Abweichung höchstens ±pct % ODER ±eur je Gebinde beträgt (das Großzügigere gilt). */
    public function preisPasst(?float $bestellpreis, ?float $rechnungspreis, array $tol): bool
    {
        if ($rechnungspreis === null || $bestellpreis === null) {
            return $rechnungspreis === $bestellpreis;
        }
        $grenze = max($tol['eur'], abs($bestellpreis) * $tol['pct'] / 100);

        return abs($rechnungspreis - $bestellpreis) <= $grenze + 0.00001;
    }

    /**
     * Befund für EINE Bestellzeile.
     *
     * @return array{ls:string, re:string, status:string, label:string, delta_menge_ls:?float, delta_menge_re:?float, delta_preis:?float, delta_eur:float}
     */
    public function befund(float $bestellt, ?float $geliefert, ?float $berechnet, ?float $bestellpreis, ?float $rechnungspreis, array $tol, bool $berechnungUeberfaellig = false): array
    {
        $ls = match (true) {
            $geliefert === null => 'offen',
            abs($geliefert - $bestellt) < 0.01 => 'ok',
            $geliefert < $bestellt => 'zu_wenig',
            default => 'zu_viel',
        };
        $re = match (true) {
            $berechnet === null => $geliefert !== null && $berechnungUeberfaellig ? 'nicht_berechnet' : 'offen',
            $berechnet > 0.0001 && ($geliefert === null || $geliefert < 0.0001) => 'nicht_geliefert',
            $geliefert !== null && abs($berechnet - $geliefert) >= 0.01 => 'menge',
            ! $this->preisPasst($bestellpreis, $rechnungspreis, $tol) => 'preis',
            default => 'ok',
        };
        $status = self::SCHWERE[$re] >= self::SCHWERE[$ls] ? $re : $ls;
        if ($re === 'ok' && $ls !== 'offen') {
            $status = 'ok';   // Rechnung passt zur Lieferung: Unter-/Überlieferung ist im Lieferschein schon erledigt
        }
        $wertRe = $berechnet !== null ? $berechnet * (float) ($rechnungspreis ?? 0) : null;
        $wertSoll = ($geliefert ?? 0) * (float) ($bestellpreis ?? 0);

        return [
            'ls' => $ls, 're' => $re, 'status' => $status, 'label' => self::LABELS[$status],
            'delta_menge_ls' => $geliefert !== null ? round($geliefert - $bestellt, 2) : null,
            'delta_menge_re' => $berechnet !== null && $geliefert !== null ? round($berechnet - $geliefert, 2) : null,
            'delta_preis' => $rechnungspreis !== null && $bestellpreis !== null ? round($rechnungspreis - $bestellpreis, 4) : null,
            'delta_eur' => $wertRe !== null ? round($wertRe - $wertSoll, 2) : 0.0,
        ];
    }

    /**
     * Abgleich über alle Bestellzeilen gesendeter/bestätigter/gelieferter Bestellungen der letzten
     * `tage` Tage. Berechnet = Summe aus nicht stornierten Rechnungen, Preis gewichtet.
     *
     * @param  array{supplier_id?:?int, nur_abweichung?:bool, tage?:int}  $filter
     * @return array{zeilen: list<array<string,mixed>>, kpis: array<string,mixed>, toleranz: array{pct:float,eur:float}}
     */
    public function abgleich(Team $team, array $filter = []): array
    {
        $tol = $this->toleranz($team);
        $tage = max(1, (int) ($filter['tage'] ?? 90));
        $lines = FoodAlchemistOrderLine::query()
            ->whereHas('order', function ($q) use ($team, $filter, $tage) {
                $q->where('team_id', $team->id)
                    ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::Confirmed->value, OrderStatus::Delivered->value])
                    ->where(fn ($w) => $w->whereNull('sent_at')->orWhere('sent_at', '>=', now()->subDays($tage)));
                if (! empty($filter['supplier_id'])) {
                    $q->where('supplier_id', (int) $filter['supplier_id']);
                }
            })
            ->with('order.supplier')
            ->get();

        $re = $this->rechnungJeZeile($team, $lines->pluck('id')->all());
        $zeilen = [];
        foreach ($lines as $l) {
            $r = $re[$l->id] ?? null;
            $ueberfaellig = $l->received_at !== null && $l->received_at->lt(now()->subDays(self::NICHT_BERECHNET_TAGE));
            $b = $this->befund((float) $l->qty_packs, $l->received_qty_packs !== null ? (float) $l->received_qty_packs : null,
                $r['menge'] ?? null, $l->pack_price !== null ? (float) $l->pack_price : null, $r['preis'] ?? null, $tol, $ueberfaellig);
            if (! empty($filter['nur_abweichung']) && in_array($b['status'], ['ok', 'offen'], true)) {
                continue;
            }
            $zeilen[] = [
                'order_line_id' => (int) $l->id, 'order_id' => (int) $l->order_id, 'nummer' => 'ord-'.(int) $l->order_id,
                'lieferant' => (string) ($l->order?->supplier?->name ?? '—'), 'supplier_id' => (int) $l->order?->supplier_id,
                'designation' => (string) ($l->designation ?? '—'), 'packaging_unit' => $l->packaging_unit,
                'bestellt' => round((float) $l->qty_packs, 2),
                'geliefert' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs, 2) : null,
                'berechnet' => $r['menge'] ?? null,
                'preis_bestellt' => $l->pack_price !== null ? round((float) $l->pack_price, 4) : null,
                'preis_rechnung' => $r['preis'] ?? null,
                'rechnungen' => $r['nummern'] ?? [],
                'claim_status' => $l->claim_status,
            ] + $b;
        }
        usort($zeilen, fn ($a, $b) => [self::SCHWERE[$b['status']], abs($b['delta_eur'])] <=> [self::SCHWERE[$a['status']], abs($a['delta_eur'])]);

        $abw = array_filter($zeilen, fn ($z) => ! in_array($z['status'], ['ok', 'offen'], true));

        return [
            'zeilen' => $zeilen,
            'toleranz' => $tol,
            'kpis' => [
                'abweichungen' => count($abw),
                'summe_abweichung_eur' => round(array_sum(array_map(fn ($z) => abs($z['delta_eur']), $abw)), 2),
                'rechnungen_in_pruefung' => FoodAlchemistSupplierInvoice::where('team_id', $team->id)->where('status', FoodAlchemistSupplierInvoice::STATUS_ERFASST)->count(),
                'nicht_berechnet' => count(array_filter($zeilen, fn ($z) => $z['re'] === 'nicht_berechnet')),
                'gutschriften_erwartet_eur' => round((float) FoodAlchemistOrderLine::whereIn('id', $lines->pluck('id'))
                    ->whereIn('claim_status', ['open', 'credit_expected'])->sum('credit_expected_net'), 2),
            ],
        ];
    }

    /**
     * Berechnete Menge und gewichteter Preis je Bestellzeile aus nicht stornierten Rechnungen.
     *
     * @param  list<int>  $orderLineIds
     * @param  list<string>  $status  Rechnungsstatus, die zählen (Standard: alle außer storniert)
     * @return array<int, array{menge: float, preis: ?float, nummern: list<string>}>
     */
    public function rechnungJeZeile(Team $team, array $orderLineIds, ?array $status = null, ?int $ohneInvoiceId = null): array
    {
        if ($orderLineIds === []) {
            return [];
        }
        $status ??= [FoodAlchemistSupplierInvoice::STATUS_ERFASST, FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN, FoodAlchemistSupplierInvoice::STATUS_BEZAHLT];
        $rows = FoodAlchemistSupplierInvoiceLine::query()
            ->whereIn('order_line_id', $orderLineIds)->where('art', 'ware')
            ->whereHas('invoice', fn ($q) => $q->where('team_id', $team->id)->whereIn('status', $status)
                ->when($ohneInvoiceId !== null, fn ($w) => $w->where('id', '!=', $ohneInvoiceId)))
            ->with('invoice:id,invoice_number')
            ->get();

        $out = [];
        foreach ($rows->groupBy('order_line_id') as $olId => $grp) {
            // Nur Positionen mit Menge bzw. Preis zählen — eine reine Preis- oder Mengenangabe (Editor) bleibt sonst null.
            $mitMenge = $grp->filter(fn ($r) => $r->qty_packs !== null);
            $mitPreis = $grp->filter(fn ($r) => $r->pack_price !== null);
            $menge = (float) $mitMenge->sum(fn ($r) => (float) $r->qty_packs);
            $gewichtet = $grp->filter(fn ($r) => $r->qty_packs !== null && $r->pack_price !== null);
            $basis = (float) $gewichtet->sum(fn ($r) => (float) $r->qty_packs);
            $out[(int) $olId] = [
                'menge' => $mitMenge->isNotEmpty() ? round($menge, 2) : null,
                'preis' => $mitPreis->isEmpty() ? null : (abs($basis) > 0.0001
                    ? round((float) $gewichtet->sum(fn ($r) => (float) $r->qty_packs * (float) $r->pack_price) / $basis, 4)
                    : round((float) $mitPreis->last()->pack_price, 4)),
                'nummern' => $grp->pluck('invoice.invoice_number')->filter()->unique()->values()->all(),
            ];
        }

        return $out;
    }
}
