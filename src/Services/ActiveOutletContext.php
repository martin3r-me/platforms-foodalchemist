<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistActiveOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Ebene 2: ambienter „aktiver Betrieb" (je User/Team), gegen den die Preis-Flächen auflösen,
 * solange kein Dokument einen eigenen Betrieb bindet. Reconcile-Regel der Read-Flächen:
 * dokument-gebundenes outlet_id ?? ActiveOutletContext::current($team).
 *
 * ZWEI Speicher, EINE Wahrheit:
 *  - HTTP-Session (Web-Fast-Path, der Sidebar-Dropdown) — schnell, aber MCP teilt sie nicht.
 *  - durabel je (User, Team) in `foodalchemist_active_outlets` — cross-session + MCP
 *    (outlets.SET_ACTIVE). `set()` schreibt beide, `current()` liest Session, sonst durabel.
 *
 * null = kein aktiver Betrieb ⇒ Team-Baseline (heutiges Verhalten). Team-scoped re-autorisiert:
 * ein gespeichertes fremdes/inaktives Outlet zählt nicht. $userId optional (MCP reicht den
 * Context-User durch; Web nutzt den eingeloggten auth()->id()).
 */
class ActiveOutletContext
{
    private const KEY = 'fa.active_outlet';

    /** Der aktuell gewählte Betrieb des Teams oder null (= Team-Baseline). */
    public function current(Team $team, ?int $userId = null): ?FoodAlchemistOutlet
    {
        $uid = $userId ?? auth()->id();
        $skey = self::KEY . '.' . $team->id;

        // Web-Fast-Path: Session (existiert nur im HTTP-Request; MCP hat keine).
        $id = session()->has($skey) ? session($skey) : null;

        // Durabler Fallback (cross-session / MCP): pro (User, Team).
        if ($id === null && $uid !== null) {
            $id = FoodAlchemistActiveOutlet::where('user_id', $uid)->where('team_id', $team->id)->value('outlet_id');
        }
        if ($id === null) {
            return null;
        }

        return FoodAlchemistOutlet::where('team_id', $team->id)
            ->where('is_inactive', false)->find($id);
    }

    /** Setzt den aktiven Betrieb (null = zurück auf Team-Baseline). Validiert Besitz + aktiv. Schreibt Session + durabel. */
    public function set(Team $team, ?int $outletId, ?int $userId = null): ?FoodAlchemistOutlet
    {
        $uid = $userId ?? auth()->id();
        $skey = self::KEY . '.' . $team->id;

        $outlet = $outletId !== null
            ? FoodAlchemistOutlet::where('team_id', $team->id)->where('is_inactive', false)->find($outletId)
            : null;

        // Session (Web) — im MCP-Request ohne Session-Store ein harmloser No-op.
        if ($outlet === null) {
            session()->forget($skey);
        } else {
            session([$skey => $outlet->id]);
        }

        // Durabel (cross-session / MCP): ein Datensatz je (User, Team).
        if ($uid !== null) {
            FoodAlchemistActiveOutlet::updateOrCreate(
                ['user_id' => $uid, 'team_id' => $team->id],
                ['outlet_id' => $outlet?->id],
            );
        }

        return $outlet;
    }
}
