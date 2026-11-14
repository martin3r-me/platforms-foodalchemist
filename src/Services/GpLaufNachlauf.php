<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Nachlauf eines GP-Laufs (Kurator 14.11.2026, Werkzeuge gp-felder / gp-bereinigen / gp-umhaengen): für die betroffenen GPs
 *  1. `n_las_total` aus dem Ist-Stand (aktive Artikel-Verknüpfungen) neu zählen,
 *  2. einen ungültigen Lead (hängt nicht mehr am GP oder fehlt) über {@see LeadLaService::pickLeadLa} neu wählen,
 *  3. optional die bestehende Lead-Neuwahl {@see LeadLaService::repickVorschau} (Rang 1 statt altem Lead; manuell gesetzte Leads und Artikel ohne
 *     Preis bleiben) — nur dort, wo sich die Artikelmenge eines GP geändert hat (Merge-Sieger, Umhäng-Ziele/-Quellen), nicht bei reinen Feldänderungen.
 *
 * Jede Änderung landet im Protokoll (`n_las`, `lead`) und ist über {@see zuruecknehmen} umkehrbar. Schreibt per Query-Builder (keine Modell-Ereignisse).
 */
class GpLaufNachlauf
{
    public function __construct(private readonly LeadLaService $lead) {}

    /**
     * @param  list<int>  $gpIds
     * @param  array<string,mixed>  $protokoll  erhält `n_las` und `lead`
     * @return list<int> GPs mit neuem Lead
     */
    public function zaehlerUndLead(Team $team, array $gpIds, array &$protokoll, bool $repick = true): array
    {
        $protokoll['n_las'] ??= [];
        $protokoll['lead'] ??= [];
        $geaendert = [];
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        foreach ($gpIds as $gpId) {
            $gp = FoodAlchemistGp::query()->ohneTeamSicht('master-kuration')->find($gpId);
            if ($gp === null) {
                continue;
            }
            $n = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gpId)->whereNull('deleted_at')->count();
            if ((int) $gp->n_las_total !== $n) {
                $protokoll['n_las'][] = ['gp_id' => $gpId, 'alt' => (int) $gp->n_las_total, 'neu' => $n];
                DB::table('foodalchemist_gps')->where('id', $gpId)->update(['n_las_total' => $n, 'updated_at' => now()]);
            }

            $leadAlt = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;
            $leadGueltig = $leadAlt !== null && DB::table('foodalchemist_supplier_item_structures')
                ->where('supplier_item_id', $leadAlt)->where('gp_id', $gpId)->whereNull('deleted_at')->exists();
            if ($leadGueltig || ($leadAlt === null && ($n === 0 || ! $gp->requires_la))) {
                continue;
            }
            $neuLead = ($n === 0 || ! $gp->requires_la) ? null : $this->lead->pickLeadLa($gp->refresh(), $team);
            if ($neuLead !== $leadAlt) {
                $this->setzeLead($gpId, $leadAlt, $neuLead, 'ungueltig', $protokoll);
                $geaendert[] = $gpId;
            }
        }

        if ($repick && $gpIds !== []) {
            foreach (array_chunk($gpIds, 500) as $teil) {
                foreach ($this->lead->repickVorschau($team, null, $teil)['wechsel'] as $w) {
                    $this->setzeLead((int) $w['gp_id'], $w['alt_la_id'], (int) $w['neu_la_id'], 'repick', $protokoll);
                    $geaendert[] = (int) $w['gp_id'];
                }
            }
        }

        return array_values(array_unique($geaendert));
    }

    private function setzeLead(int $gpId, ?int $alt, ?int $neu, string $grund, array &$protokoll): void
    {
        DB::table('foodalchemist_gps')->where('id', $gpId)->update(['lead_la_supplier_item_id' => $neu, 'updated_at' => now()]);
        $protokoll['lead'][] = ['gp_id' => $gpId, 'alt' => $alt, 'neu' => $neu, 'grund' => $grund];
    }

    /** Rezepte neu rechnen, die die GPs nutzen (einmal, V-049). @param list<int> $gpIds */
    public function rechneRezepte(array $gpIds): int
    {
        return $gpIds === [] ? 0 : $this->lead->recomputeNutzerFuerGps(array_values(array_unique(array_map('intval', $gpIds))));
    }

    /**
     * Lead- und Zähler-Einträge eines Protokolls umkehren (umgekehrte Reihenfolge). Ein Lead wird nur zurückgesetzt, wenn er noch dem protokollierten
     * neuen Stand entspricht; Zähler werden immer auf den alten Wert gesetzt (der folgende Rückweg der Verknüpfungen stellt die Menge dazu her).
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int}
     */
    public function zuruecknehmen(array $p): array
    {
        $zurueck = 0;
        $skip = 0;
        foreach (array_reverse($p['lead'] ?? []) as $l) {
            $ist = DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->first();
            if ($ist !== null && ($ist->lead_la_supplier_item_id === null ? null : (int) $ist->lead_la_supplier_item_id) === $l['neu']) {
                DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->update(['lead_la_supplier_item_id' => $l['alt'], 'updated_at' => now()]);
                $zurueck++;
            } else {
                $skip++;
            }
        }
        foreach (array_reverse($p['n_las'] ?? []) as $n) {
            DB::table('foodalchemist_gps')->where('id', $n['gp_id'])->update(['n_las_total' => $n['alt']]);
        }

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip];
    }
}
