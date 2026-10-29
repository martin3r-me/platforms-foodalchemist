<?php

namespace Platform\FoodAlchemist\Services\Naehrwerte;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\NaehrwertAggregatService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\NaehrwertPruefModus;

/**
 * Signal „Nährwerte am Artikel prüfen“ je Lieferantenartikel mit hartem Befund (Plan 29.10.2026, PR C). Läuft im Detektor-Lauf, nur im
 * Modus scharf, nur für Artikel, die das Team besitzt (globale Artikel: Master-Team).
 *
 * Gedrosselt: höchstens `signale_je_lauf` NEUE Signale je Lauf (kein Signal-Sturm, Dominique 29.10.); offene werden aktualisiert, der
 * Rest rückt in den nächsten Läufen nach. Reihenfolge nach Regel — zuerst das physikalisch Unmögliche, dann Energie und Geschwister.
 * Ein Artikel, der nicht mehr hart ist (korrigiert, freigegeben, Quelle berichtigt), schließt sein Signal selbst.
 */
class NaehrwertSignalService
{
    public const QUELLE = 'naehrwert_pruefung';

    /** Reihenfolge der ersten Chargen. */
    private const PRIO = ['H1' => 1, 'H2' => 2, 'H3' => 3, 'W1' => 4, 'W5' => 5, 'H4' => 6, 'A' => 7];

    public function __construct(private readonly SignalService $signals) {}

    /** @return int Anzahl erzeugter/aktualisierter Signale */
    public function laufen(Team $team): int
    {
        if (! NaehrwertPruefModus::istScharf()) {
            return 0;
        }
        $gps = FoodAlchemistGp::visibleToTeam($team)->pluck('name', 'id')->all();
        $kandidaten = [];
        foreach (app(NaehrwertAggregatService::class)->pruefung(array_keys($gps)) as $gpId => $artikel) {
            foreach ($artikel as $a) {
                if (! $a['zaehlt'] && ! isset($kandidaten[$a['la_id']])) {
                    $kandidaten[$a['la_id']] = $a + ['gp_id' => $gpId, 'gp' => $gps[$gpId] ?? null];
                }
            }
        }
        $eigene = FoodAlchemistSupplierItem::ohneTeamSicht('naehrwert-pruefung')->whereIn('id', array_keys($kandidaten))->get()
            ->filter(fn (FoodAlchemistSupplierItem $i) => $i->isOwnedBy($team))->keyBy('id');
        $kandidaten = array_intersect_key($kandidaten, $eigene->all());

        // Schließen: offene Signale, deren Artikel nicht mehr hart ist
        $offen = FoodAlchemistSignal::where('team_id', $team->id)->where('type', SignalTyp::NaehrwertPruefen->value)
            ->where('status', SignalStatus::Offen->value)->whereNull('outlet_id')->pluck('dedup_key')->all();
        foreach ($offen as $key) {
            $laId = (int) substr((string) $key, strlen('nw-la-'));
            if (! isset($kandidaten[$laId])) {
                $this->signals->schliesseGemessen($team, SignalTyp::NaehrwertPruefen, (string) $key, self::QUELLE, 'Artikel nicht mehr auffällig (korrigiert, freigegeben oder Quelle berichtigt)');
            }
        }

        uasort($kandidaten, fn ($x, $y) => [$this->prio($x), $x['la_id']] <=> [$this->prio($y), $y['la_id']]);
        $neuFrei = max(0, (int) config('foodalchemist.naehrwert_pruefung.signale_je_lauf', 25));
        $n = 0;
        foreach ($kandidaten as $laId => $a) {
            $key = 'nw-la-' . $laId;
            $istOffen = in_array($key, $offen, true);
            if (! $istOffen && $neuFrei === 0) {
                continue;
            }
            $hart = array_values(array_filter($a['befunde'], fn (NaehrwertBefund $b) => $b->schwere === NaehrwertBefund::HART));
            $erster = $hart[0];
            $this->signals->erzeuge($team, SignalTyp::NaehrwertPruefen, SignalSeverity::Warnung,
                'Nährwerte prüfen: ' . mb_strimwidth((string) $a['bezeichnung'], 0, 80, '…') . ' — ' . $erster->grund,
                [
                    'dedup_key' => $key, 'ref_type' => 'supplier_item', 'ref_id' => $laId, 'source' => self::QUELLE,
                    'description' => sprintf('%s · %s · Feld %s = %s, erwartet %s. Der Artikel zählt nicht in den Nährwerten von „%s“, bis er freigegeben oder korrigiert ist.',
                        $a['lieferant'] ?? '–', $a['bezeichnung'], $erster->feld, is_scalar($erster->wert) ? $erster->wert : '–', $erster->erwartet, $a['gp'] ?? '–'),
                    'payload' => ['la_id' => $laId, 'gp_id' => $a['gp_id'], 'lieferant' => $a['lieferant'],
                        'befunde' => array_map(fn (NaehrwertBefund $b) => $b->toArray(), $a['befunde'])],
                ]);
            $n++;
            if (! $istOffen) {
                $neuFrei--;
            }
        }

        return $n;
    }

    private function prio(array $a): int
    {
        $p = 99;
        foreach ($a['befunde'] as $b) {
            if ($b->schwere === NaehrwertBefund::HART) {
                $p = min($p, self::PRIO[$b->regel] ?? 50);
            }
        }

        return $p;
    }
}
