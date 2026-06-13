<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFixkosten;

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
    public function __construct(private TeamSettingsService $settings)
    {
    }

    /** @return Collection<int, FoodAlchemistFixkosten> */
    public function liste(Team $team): Collection
    {
        return FoodAlchemistFixkosten::visibleToTeam($team)->orderBy('block_key')->orderBy('bezeichnung')->get();
    }

    public function create(Team $team, array $in): FoodAlchemistFixkosten
    {
        return FoodAlchemistFixkosten::create([
            'team_id' => $team->id,
            'bezeichnung' => trim((string) ($in['bezeichnung'] ?? 'Fixkosten')) ?: 'Fixkosten',
            'betrag' => max(0, (float) str_replace(',', '.', (string) ($in['betrag'] ?? 0))),
            'periode' => in_array($in['periode'] ?? 'monatlich', ['monatlich', 'jaehrlich'], true) ? $in['periode'] : 'monatlich',
            'block_key' => (string) ($in['block_key'] ?? 'gemeinkosten'),
        ]);
    }

    public function update(Team $team, int $id, array $in): void
    {
        $row = FoodAlchemistFixkosten::visibleToTeam($team)->findOrFail($id);
        $this->guard($row, $team);
        $update = [];
        if (array_key_exists('bezeichnung', $in)) {
            $update['bezeichnung'] = trim((string) $in['bezeichnung']) ?: $row->bezeichnung;
        }
        if (array_key_exists('betrag', $in)) {
            $update['betrag'] = max(0, (float) str_replace(',', '.', (string) $in['betrag']));
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

    /** Σ Fixkosten je Block (monatlich). @return array<string, float> block_key => €/Monat */
    public function summeJeBlock(Team $team): array
    {
        $out = [];
        foreach ($this->liste($team) as $row) {
            $out[$row->block_key] = ($out[$row->block_key] ?? 0) + $row->monatsbetrag();
        }

        return $out;
    }

    /** Abgeleiteter Zuschlag-% für einen Block (0, wenn Basis fehlt). */
    public function abgeleiteterSatz(Team $team, array $block, ?array $summen = null, ?array $basen = null): float
    {
        $summen ??= $this->summeJeBlock($team);
        $basen ??= $this->settings->bezugsbasen($team);
        $basisTyp = match ($block['typ']) {
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
    public function aufgeloestesSchema(Team $team): array
    {
        $summen = $this->summeJeBlock($team);
        $basen = $this->settings->bezugsbasen($team);

        return array_map(function ($b) use ($team, $summen, $basen) {
            if (($b['modus'] ?? 'manuell') === 'abgeleitet') {
                $b['wert'] = $this->abgeleiteterSatz($team, $b, $summen, $basen);
            }

            return $b;
        }, $this->settings->kalkulationSchema($team));
    }

    private function guard(FoodAlchemistFixkosten $row, Team $team): void
    {
        if (! $row->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Fixkosten — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
