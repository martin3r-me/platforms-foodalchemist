<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Ebene 2: ambienter „aktiver Betrieb" als Session-Kontext (je User/Team), gegen den die
 * Preis-Flächen auflösen, solange kein Dokument einen eigenen Betrieb bindet. Reconcile-Regel
 * der Read-Flächen: dokument-gebundenes outlet_id ?? ActiveOutletContext::current($team).
 *
 * null = kein aktiver Betrieb ⇒ Team-Baseline (heutiges Verhalten). Team-scoped
 * re-autorisiert: ein gespeichertes fremdes/inaktives Outlet zählt nicht.
 */
class ActiveOutletContext
{
    private const KEY = 'fa.active_outlet';

    /** Der aktuell gewählte Betrieb des Teams oder null (= Team-Baseline). */
    public function current(Team $team): ?FoodAlchemistOutlet
    {
        $id = session(self::KEY . '.' . $team->id);
        if ($id === null) {
            return null;
        }

        return FoodAlchemistOutlet::where('team_id', $team->id)
            ->where('is_inactive', false)->find($id);
    }

    /** Setzt den aktiven Betrieb (null = zurück auf Team-Baseline). Validiert Besitz + aktiv. */
    public function set(Team $team, ?int $outletId): ?FoodAlchemistOutlet
    {
        $skey = self::KEY . '.' . $team->id;
        if ($outletId === null) {
            session()->forget($skey);

            return null;
        }
        $outlet = FoodAlchemistOutlet::where('team_id', $team->id)
            ->where('is_inactive', false)->find($outletId);
        if ($outlet === null) {
            session()->forget($skey);

            return null;
        }
        session([$skey => $outlet->id]);

        return $outlet;
    }
}
