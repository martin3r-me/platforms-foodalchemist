<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;

/**
 * Spec 82 §13 B1/B4 — Auskunft über Abos, die an einem Stamm-Paket oder am Master-Team hängen.
 *
 * Die eine Schnittstelle, die Stamm-Paket-Abbestellung und Master-Wechsel für ihre Wirkungs-Vorschau fragen.
 * Seit Durchstich Schritt 1 aus `foodalchemist_abos` gefüllt (nur aktive Abos). Im Test per Container ersetzbar.
 */
class AboAuskunft
{
    /**
     * Abos des Mandanten, die ein gebuchtes Stamm-Paket voraussetzen (Entscheidung 1 = C) und ohne es ungültig würden.
     * Das sind alle seine aktiven Abos.
     *
     * @return list<array{id:int, sammlung:string, master_team_id:int}>
     */
    public function abosAmStammPaket(Team $mandant): array
    {
        return $this->query()->where('a.mandant_team_id', $mandant->id)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'sammlung' => (string) ($r->sammlung ?? '—'), 'master_team_id' => (int) $r->master_team_id])
            ->all();
    }

    /** Gibt es das Abo-Modell schon (Tabelle migriert, Durchstich Schritt 1)? Sonst entfällt „Abos kündigen“ beim Beenden. */
    public function verfuegbar(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_abos');
    }

    /**
     * Alle Abos des Mandanten kündigen (Beenden eines Mandanten, §13 C3): genutzte Rezepte als tiefe Kopie (§12 D), ungenutzte
     * verschwinden. Solange es das Abo-Modell nicht gibt, gibt es nichts zu kündigen. Mit Spec 82 Schritt 7 wird nur diese Methode gefüllt.
     *
     * @return list<array{id:int, sammlung:string}> die gekündigten Abos
     */
    public function kuendigen(Team $mandant, ?\Platform\Core\Models\User $user): array
    {
        // Seit Durchstich Schritt 1 gibt es Abos. Die Kündigung mit tiefer Kopie (§12 D) ist Schritt 5 und noch nicht gebaut —
        // bis dahin NIE still „0 gekündigt" melden, während Abos aktiv bleiben: mit Abos abbrechen, ohne Abos nichts zu tun.
        $aktiv = $this->abosDesMandanten($mandant);
        if ($aktiv !== []) {
            throw new \RuntimeException(sprintf('%d Abo(s) aktiv: Die Kündigung mit tiefer Kopie (Spec 82 §12 D) ist noch nicht gebaut — '
                . 'dieser Mandant lässt sich erst nach Durchstich Schritt 5 beenden.', count($aktiv)));
        }

        return [];
    }

    /**
     * Alle Abos des Mandanten (Beenden: werden gekündigt, genutzte Rezepte als tiefe Kopie, §12 D).
     *
     * @return list<array{id:int, sammlung:string, master_team_id:int}>
     */
    public function abosDesMandanten(Team $mandant): array
    {
        return $this->abosAmStammPaket($mandant);   // heute dieselbe Menge: alle aktiven Abos des Mandanten
    }

    /**
     * Abos, deren Sichtbarkeit am Master-Team hängt (ein Master-Wechsel ändert sie alle).
     *
     * @return list<array{id:int, mandant_team_id:int, sammlung:string}>
     */
    public function abosAmMaster(?int $masterTeamId): array
    {
        if ($masterTeamId === null) {
            return [];
        }

        return $this->query()->where('a.master_team_id', $masterTeamId)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'mandant_team_id' => (int) $r->mandant_team_id, 'sammlung' => (string) ($r->sammlung ?? '—')])
            ->all();
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_abos AS a')
            ->leftJoin('foodalchemist_sammlungen AS s', 's.id', '=', 'a.sammlung_id')
            ->where('a.status', FoodAlchemistAbo::AKTIV)->orderBy('a.id')
            ->select('a.id', 'a.master_team_id', 'a.mandant_team_id', 's.name AS sammlung');
    }
}
