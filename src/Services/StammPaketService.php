<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Spec 82 §13 B1 — Stamm-Paket buchen und abbestellen (nur Plattform-Admin).
 *
 * Das Paket gehört dem Mandanten = Kunden-Hauptteam (Unter-Teams erben, das Master-Team hat es immer). Beim Abbestellen
 * zeigt das System, welche Abos dadurch ungültig würden (Abo setzt Paket voraus, Entscheidung 1 = C); mit Abos ist eine
 * ausdrückliche Bestätigung nötig. Jede Aktion wird protokolliert. Die Tabelle foodalchemist_team_stamm_optins bleibt die Quelle der Sichtbarkeit.
 */
class StammPaketService
{
    use PlattformAdminPruefung;

    /** Für lesende Aufrufer (MCP, Oberfläche): wirft FaRechtFehltException, wenn nicht Plattform-Admin. */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly AboAuskunft $abos,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /**
     * Alle Mandanten (Kunden-Hauptteams ohne das Master-Team) mit Paketstatus.
     *
     * @return list<array{team_id:int, name:string, gebucht:bool, seit:?string, von:?int, abos:int}>
     */
    public function uebersicht(): array
    {
        $optins = FoodAlchemistTeamStammOptin::all()->keyBy('team_id');
        $master = MasterTeam::id();

        return Team::whereNull('parent_team_id')->orderBy('name')->get(['id', 'name'])
            ->reject(fn ($t) => $master !== null && (int) $t->id === $master)
            ->map(function ($t) use ($optins) {
                $o = $optins->get($t->id);

                return ['team_id' => (int) $t->id, 'name' => (string) $t->name, 'gebucht' => $o !== null, 'seit' => $o?->opted_in_at?->format('d.m.Y'),
                    'von' => $o?->opted_in_by, 'abos' => count($this->abos->abosAmStammPaket($t))];
            })->values()->all();
    }

    /** @return array{team_id:int, gebucht:bool, seit:?string, ist_master:bool} */
    public function status(Team $mandant): array
    {
        $o = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();

        return ['team_id' => (int) $mandant->id, 'gebucht' => $o !== null, 'seit' => $o?->opted_in_at?->toDateString(), 'ist_master' => MasterTeam::istMaster($mandant)];
    }

    /** Was das Abbestellen bewirkt. @return array{team_id:int, gebucht:bool, abos:list<array<string,mixed>>, bestaetigung_noetig:bool} */
    public function vorschauAbbestellen(Team $mandant): array
    {
        $abos = $this->abos->abosAmStammPaket($mandant);

        return ['team_id' => (int) $mandant->id, 'gebucht' => $this->status($mandant)['gebucht'], 'abos' => $abos, 'bestaetigung_noetig' => $abos !== []];
    }

    public function buchen(Team $mandant, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Stamm-Paket buchen');
        $this->pruefeMandant($mandant);
        $war = $this->status($mandant)['gebucht'];
        $optin = StammPaket::freischalten($mandant, $admin->id);
        if (! $war) {
            $this->protokoll->schreibe('stamm_paket_gebucht', ['team_id' => $mandant->id, 'team' => $mandant->name], $admin, $optin);
        }

        return $this->status($mandant);
    }

    public function abbestellen(Team $mandant, ?User $user, bool $bestaetigt = false): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Stamm-Paket abbestellen');
        $this->pruefeMandant($mandant);
        $vorschau = $this->vorschauAbbestellen($mandant);
        if ($vorschau['gebucht'] && $vorschau['bestaetigung_noetig'] && ! $bestaetigt) {
            throw new \RuntimeException(sprintf('Das Abbestellen macht %d Abo(s) ungültig. Bitte die Vorschau prüfen und ausdrücklich bestätigen.', count($vorschau['abos'])));
        }
        $optin = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();   // Subjekt des Eintrags: auch die gelöschte Zeile trägt ihn
        if (StammPaket::abbestellen($mandant)) {
            $this->protokoll->schreibe('stamm_paket_abbestellt', ['team_id' => $mandant->id, 'team' => $mandant->name, 'abos_betroffen' => count($vorschau['abos']), 'bestaetigt' => $bestaetigt], $admin, $optin);
        }

        return $this->status($mandant);
    }

    /**
     * Die Lieferanten des Stamm-Pakets (global, stamm_paket) mit Freigabe-Stand für einen Mandanten. Unter-Teams erben die Auswahl des Haupt-Teams,
     * deshalb wird immer am Haupt-Team gelesen und gesetzt. Das Master-Team sieht immer alle (kein Stand nötig).
     *
     * @return array{team_id:int, gebucht:bool, gesamt:int, freigegeben:int, lieferanten:list<array{id:int, name:string, artikel:int, frei:bool}>}
     */
    public function lieferanten(Team $mandant, string $suche = ''): array
    {
        $haupt = $this->rechte->kundenHauptTeam($mandant);
        $frei = $this->freigegebeneIds($haupt);
        $alle = DB::table('foodalchemist_suppliers')->whereNull('team_id')->where('stamm_class', StammPaket::PAKET)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name']);
        $artikel = DB::table('foodalchemist_supplier_items')->whereNull('team_id')->where('stamm_class', StammPaket::PAKET)->whereNull('deleted_at')
            ->selectRaw('supplier_id, COUNT(*) AS n')->groupBy('supplier_id')->pluck('n', 'supplier_id');
        $zeilen = $alle->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name, 'artikel' => (int) ($artikel[$s->id] ?? 0), 'frei' => isset($frei[(int) $s->id])]);
        $gefiltert = $suche === '' ? $zeilen : $zeilen->filter(fn ($z) => mb_stripos($z['name'], $suche) !== false);

        return ['team_id' => (int) $haupt->id, 'gebucht' => $this->status($haupt)['gebucht'], 'gesamt' => $zeilen->count(),
            'freigegeben' => $zeilen->where('frei', true)->count(), 'lieferanten' => $gefiltert->values()->all()];
    }

    /**
     * Freigeschaltete Lieferanten eines Mandanten setzen — EXAKT diese Menge (`alle` = jeder Stamm-Lieferant, `keine` = leer). Nur Plattform-Admin,
     * nur bei gebuchtem Paket, nur Stamm-Lieferanten; Protokoll am Opt-in. Standard bei neuer Buchung: keine.
     *
     * @param  list<int>|'alle'|'keine'  $auswahl
     * @return array{team_id:int, gesamt:int, freigegeben:int, hinzu:int, entfernt:int}
     */
    public function setzeLieferanten(Team $mandant, array|string $auswahl, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Stamm-Lieferanten freischalten');
        $this->pruefeMandant($mandant);
        if (! $this->status($mandant)['gebucht']) {
            throw new \RuntimeException('Erst das Stamm-Paket buchen, dann Lieferanten freischalten.');
        }
        $stamm = DB::table('foodalchemist_suppliers')->whereNull('team_id')->where('stamm_class', StammPaket::PAKET)->whereNull('deleted_at')->pluck('name', 'id');
        $soll = match (true) {
            $auswahl === 'alle' => $stamm->keys()->map(fn ($i) => (int) $i)->all(),
            $auswahl === 'keine' => [],
            is_string($auswahl) => throw new \RuntimeException('auswahl: alle, keine oder eine Liste von Lieferanten-IDs.'),
            default => array_values(array_unique(array_map('intval', $auswahl))),
        };
        $fremd = array_values(array_diff($soll, $stamm->keys()->map(fn ($i) => (int) $i)->all()));
        if ($fremd !== []) {
            throw new \RuntimeException('Kein Lieferant des Stamm-Pakets: '.implode(', ', array_slice($fremd, 0, 10)).'. IDs liefert stamm_paket_lieferanten.GET.');
        }
        $ist = array_keys($this->freigegebeneIds($mandant));
        $hinzu = array_values(array_diff($soll, $ist));
        $weg = array_values(array_diff($ist, $soll));
        DB::transaction(function () use ($mandant, $admin, $hinzu, $weg) {
            foreach (array_chunk($hinzu, 200) as $teil) {
                DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(array_map(fn ($id) => [
                    'team_id' => $mandant->id, 'supplier_id' => $id, 'freigegeben_von' => $admin->id, 'created_at' => now(), 'updated_at' => now()], $teil));
            }
            if ($weg !== []) {
                DB::table(StammPaket::LIEFERANTEN_TABELLE)->where('team_id', $mandant->id)->whereIn('supplier_id', $weg)->delete();
            }
        });
        StammPaket::flush();
        $optin = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();
        if ($hinzu !== [] || $weg !== []) {
            $namen = fn (array $ids) => array_values(array_map(fn ($i) => (string) ($stamm[$i] ?? $i), array_slice($ids, 0, 15)));
            $this->protokoll->schreibe('stamm_lieferanten_gesetzt', ['team_id' => $mandant->id, 'team' => $mandant->name, 'hinzu' => count($hinzu), 'entfernt' => count($weg),
                'frei_gesamt' => count($soll), 'hinzu_namen' => $namen($hinzu), 'entfernt_namen' => $namen($weg)], $admin, $optin);
        }

        return ['team_id' => (int) $mandant->id, 'gesamt' => $stamm->count(), 'freigegeben' => count($soll), 'hinzu' => count($hinzu), 'entfernt' => count($weg)];
    }

    /** @return array<int, true> supplier_id => true (nur die Auswahl dieses Haupt-Teams, nicht der Eltern) */
    private function freigegebeneIds(Team $haupt): array
    {
        return DB::table(StammPaket::LIEFERANTEN_TABELLE)->where('team_id', $haupt->id)->pluck('supplier_id')->mapWithKeys(fn ($i) => [(int) $i => true])->all();
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team hat das Stamm-Paket immer, es lässt sich weder buchen noch abbestellen.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Das Stamm-Paket wird am Haupt-Team des Kunden gebucht. Unter-Teams erben es.');
        }
    }
}
