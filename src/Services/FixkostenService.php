<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFixkosten;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * M-K6 / Doc 16 §10.2: Fixkosten → abgeleitete Gemeinkosten-Zuschläge (mehrstufig).
 *
 *   abgeleiteter Satz % je Block = Σ Fixkosten(Block, monatlich) ÷ Bezugsbasis(Block-Basis) × 100
 *   Basis je Block-Typ: pct_mek → Wareneinsatz · pct_fek → Fertigungslohn · pct_hk → Herstellkosten.
 *
 * `aufgeloestesSchema()` liefert das Kalkulations-Schema mit ersetzten %-Werten für
 * alle Blöcke im Modus „abgeleitet" — das nutzt KalkulationService::berechne.
 */
class FixkostenService
{
    /** Editierbare Catering-Beispielwerte, ausdrücklich keine Branchen-Norm. */
    public const CATERING_EXAMPLE_COSTS = [
        ['label' => 'Beispiel: Produktionsmiete und Nebenkosten', 'amount' => 6000, 'block_key' => 'fertigungs_gk'],
        ['label' => 'Beispiel: Energie, Wasser und Spüle', 'amount' => 1800, 'block_key' => 'fertigungs_gk'],
        ['label' => 'Beispiel: Reinigung und Entsorgung', 'amount' => 900, 'block_key' => 'fertigungs_gk'],
        ['label' => 'Beispiel: Einkauf, Lager und Warenannahme', 'amount' => 1200, 'block_key' => 'gemeinkosten'],
        ['label' => 'Beispiel: Verwaltung, Software und Versicherungen', 'amount' => 1800, 'block_key' => 'verwaltung'],
        ['label' => 'Beispiel: Vertrieb und Marketing', 'amount' => 1500, 'block_key' => 'verwaltung'],
        ['label' => 'Beispiel: Fahrzeuge und Logistik', 'amount' => 1800, 'block_key' => 'logistik'],
    ];

    public const CATERING_EXAMPLE_BASES = ['mek' => 30000, 'fek' => 24000, 'hk' => 60000];

    public function __construct(private TeamSettingsService $settings)
    {
    }

    /** @return Collection<int, FoodAlchemistFixkosten> */
    public function liste(Team $team): Collection
    {
        return FoodAlchemistFixkosten::visibleToTeam($team)->whereNull('outlet_id')
            ->orderBy('block_key')->orderBy('label')->get();
    }

    /** Ebene 2: Fixkosten eines Betriebs (nur dessen eigene Override-Zeilen). */
    public function listeFuerOutlet(Team $team, FoodAlchemistOutlet $outlet): Collection
    {
        return $this->rowsFor($team, $outlet);
    }

    public function create(Team $team, array $in, ?FoodAlchemistOutlet $outlet = null): FoodAlchemistFixkosten
    {
        return FoodAlchemistFixkosten::create([
            'team_id' => $team->id,
            'outlet_id' => $outlet?->id,
            'label' => trim((string) ($in['label'] ?? 'Fixkosten')) ?: 'Fixkosten',
            'amount' => max(0, (float) str_replace(',', '.', (string) ($in['amount'] ?? 0))),
            'periode' => in_array($p = $in['periode'] ?? 'monatlich', ['monatlich', 'jaehrlich'], true) ? $p : 'monatlich',
            'block_key' => (string) ($in['block_key'] ?? 'gemeinkosten'),
        ]);
    }

    /**
     * Ebene 2 (volle Kopie): alle Team-Fixkosten-Zeilen als EIGENE Zeilen für den Betrieb anlegen.
     * Startpunkt, damit ein Betrieb nicht bei 0 beginnt. Idempotenz-Schutz: nur wenn er noch keine
     * eigenen Zeilen hat. @return int Anzahl kopierter Zeilen.
     */
    public function uebernimmTeamFixkosten(Team $team, FoodAlchemistOutlet $outlet): int
    {
        if ((int) $outlet->team_id !== (int) $team->id) {
            throw new \RuntimeException('Fremder Betrieb — Fixkosten-Übernahme nur durchs Besitzer-Team.');
        }
        if ($this->rowsFor($team, $outlet)->isNotEmpty()) {
            return 0;   // hat schon eigene Zeilen — nicht dazumischen
        }
        $n = 0;
        foreach ($this->rowsFor($team, null) as $row) {
            FoodAlchemistFixkosten::create([
                'team_id' => $team->id, 'outlet_id' => $outlet->id,
                'label' => $row->label, 'amount' => $row->amount,
                'periode' => $row->periode, 'block_key' => $row->block_key,
            ]);
            $n++;
        }

        return $n;
    }

    /** Legt einen einmaligen, sofort editierbaren Beispielsatz zum Durchrechnen an. */
    public function cateringBeispielwerte(Team $team): void
    {
        if ($this->liste($team)->isNotEmpty()) {
            throw new \RuntimeException('Es sind bereits Fixkosten erfasst. Beispielwerte werden nicht dazugemischt.');
        }
        foreach (self::CATERING_EXAMPLE_COSTS as $row) {
            $this->create($team, $row + ['periode' => 'monatlich']);
        }
    }

    public function update(Team $team, int $id, array $in): void
    {
        $row = FoodAlchemistFixkosten::visibleToTeam($team)->findOrFail($id);
        $this->guard($row, $team);
        $update = [];
        if (array_key_exists('label', $in)) {
            $update['label'] = trim((string) $in['label']) ?: $row->label;
        }
        if (array_key_exists('amount', $in)) {
            $update['amount'] = max(0, (float) str_replace(',', '.', (string) $in['amount']));
        }
        if (array_key_exists('periode', $in) && in_array($in['periode'], ['monatlich', 'jaehrlich'], true)) {
            $update['periode'] = $in['periode'];
        }
        if (array_key_exists('block_key', $in)) {
            $update['block_key'] = (string) $in['block_key'];
        }
        $row->update($update);
    }

    public function delete(Team $team, int $id): void
    {
        $row = FoodAlchemistFixkosten::visibleToTeam($team)->findOrFail($id);
        $this->guard($row, $team);
        $row->delete();
    }

    /** Ebene 2: alle eigenen Fixkosten-Zeilen eines Betriebs löschen (Reset auf Team-Werte). */
    public function loescheAlleFuerOutlet(Team $team, FoodAlchemistOutlet $outlet): int
    {
        if ((int) $outlet->team_id !== (int) $team->id) {
            return 0;
        }

        return (int) FoodAlchemistFixkosten::where('team_id', $team->id)->where('outlet_id', $outlet->id)->delete();
    }

    /** Σ je Block über eine Zeilen-Menge (monatlich). @return array<string, float> */
    private function sum(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->block_key] = ($out[$row->block_key] ?? 0) + $row->monatsbetrag();
        }

        return $out;
    }

    /** Sichtbare Fixkosten-Zeilen: outlet=null ⇒ Team-Zeilen (heute), sonst die des Betriebs. */
    private function rowsFor(Team $team, ?FoodAlchemistOutlet $outlet): Collection
    {
        $q = FoodAlchemistFixkosten::visibleToTeam($team);
        $q = $outlet === null ? $q->whereNull('outlet_id') : $q->where('outlet_id', $outlet->id);

        return $q->orderBy('block_key')->orderBy('label')->get();
    }

    /**
     * Σ Fixkosten je Block (monatlich). Ebene 2 — KEINE Vererbung: ein Betrieb zählt NUR
     * seine EIGENEN Zeilen (Blöcke ohne eigene Zeile = 0), damit ein Betrieb eine voll
     * eigenständige Kalkulation ist. outlet=null ⇒ reine Team-Summe. Startpunkt für einen
     * Betrieb: {@see uebernimmTeamFixkosten} (kopiert alle Team-Zeilen als eigene).
     *
     * @return array<string, float> block_key => €/Monat
     */
    public function summeJeBlock(Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        return $this->sum($this->rowsFor($team, $outlet));
    }

    /** Abgeleiteter Zuschlag-% für einen Block (0, wenn Basis fehlt). */
    public function abgeleiteterSatz(Team $team, array $block, ?array $summen = null, ?array $basen = null): float
    {
        $summen ??= $this->summeJeBlock($team);
        $basen ??= $this->settings->bezugsbasen($team);
        $basisTyp = match ($block['type']) {
            'pct_mek' => 'mek',
            'pct_fek' => 'fek',
            'pct_hk' => 'hk',
            default => null,
        };
        if ($basisTyp === null) {
            return 0.0;
        }
        $basis = (float) ($basen[$basisTyp] ?? 0);
        $summe = (float) ($summen[$block['key']] ?? 0);

        return $basis > 0 ? round($summe / $basis * 100, 2) : 0.0;
    }

    /**
     * Kalkulations-Schema mit aufgelösten %-Werten: Blöcke im Modus „abgeleitet"
     * bekommen den aus den Fixkosten abgeleiteten Satz; „manuell" behält den Wert.
     *
     * @return list<array{key:string,label:string,typ:string,wert:float,aktiv:bool,sort:int,modus:string}>
     */
    public function aufgeloestesSchema(Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $summen = $this->summeJeBlock($team, $outlet);
        $basen = $this->settings->bezugsbasen($team, $outlet);

        return array_map(function ($b) use ($team, $summen, $basen) {
            if (($b['mode'] ?? 'manuell') === 'abgeleitet') {
                $b['value'] = $this->abgeleiteterSatz($team, $b, $summen, $basen);
            }

            return $b;
        }, $this->settings->kalkulationSchema($team, $outlet));
    }

    private function guard(FoodAlchemistFixkosten $row, Team $team): void
    {
        if (! $row->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Fixkosten — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
