<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Spec 84 C1 Schritt 1 · Aufbereitung der Kaskaden-Läufe für die Planungs-Ansicht (Stufen, Status je Session, Auto-Titel),
 * aus `Livewire\Planung\Index` herausgelöst (wortgleich verschoben). Zustandsfrei: rechnet nur über übergebene Steps/Sessions.
 */
final class KaskadenAnsicht
{
    /**
     * Stufen-Ableitung fürs Cockpit: je Ebene (concept · gericht · rezept) Zähler + Zustand. Nur
     * erreichte Stufen (mind. 1 Step) — so enthüllt sich die Kaskade fortschreitend. Rein für die Anzeige.
     *
     * Die Basisrezepte-Stufe ist erreicht, sobald das Gericht als Entwurf steht: ihre Zeilen sind
     * dann `geplant` (Sub-Rezept benannt, erzeugt wird es bei der Freigabe der Stufe darüber) bzw.
     * `skipped` (Bestands-Rezept übernommen). Zustand `geplant` heisst also: sichtbar, aber noch
     * nicht erzeugt — kein Freigabe-Knopf (der käme erst bei `prüfen`).
     *
     * @param  \Illuminate\Support\Collection<int,\Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep>  $steps
     * @return list<array<string,mixed>>
     */
    public function stufenAusSteps($steps): array
    {
        $defs = [['kind' => 'concept', 'label' => 'Concept'], ['kind' => 'gericht', 'label' => 'Gerichte'], ['kind' => 'rezept', 'label' => 'Basisrezepte']];
        $out = [];
        foreach ($defs as $d) {
            $grp = $steps->where('kind', $d['kind']);
            $total = $grp->count();
            if ($total === 0) {
                continue;
            }
            $running = $grp->whereIn('status', ['queued', 'running'])->count();
            $done = $grp->where('status', 'done')->count();
            $freigegeben = $grp->where('status', 'freigegeben')->count();
            $verworfen = $grp->where('status', 'verworfen')->count();
            $failed = $grp->where('status', 'failed')->count();
            // `geplant` = benanntes Sub-Rezept, das auf die Freigabe der Stufe darüber wartet;
            // `skipped` = übernommenes Bestands-Rezept (Reuse) — nichts zu erzeugen, also fertig.
            $geplant = $grp->where('status', 'geplant')->count();
            $uebernommen = $grp->where('status', 'skipped')->count();
            // …ABER nur, wenn die Übernahme wirklich fertig ist. 2026-09-07: Lauf 65 zählte
            // Step 460 („Basisrezept: Gemüsebrühe", draft, 0 Schritte, unbepreist) als fertig
            // und der Lauf meldete „abgeschlossen". Eine unreife Übernahme ist eine offene
            // Aufgabe, kein Abschluss — sie erscheint als eigene Zahl und nicht in `fertig`.
            $unreif = $grp->where('status', 'skipped')->filter(function ($st) {
                $reuse = is_array($st->deferred['reuse'] ?? null) ? $st->deferred['reuse'] : null;

                return $reuse !== null && ! ($reuse['reif'] ?? false);
            })->count();
            // Spec 80 C7: eine Stufe nur aus gescheiterten Schritten ist nicht „erledigt" (demo Lauf #79
            // zeigte „Erledigt" neben „0 von 1 fertig").
            $zustand = $running > 0 ? 'läuft'
                : ($done > 0 ? 'prüfen' : ($geplant > 0 ? 'geplant' : ($unreif > 0 ? 'prüfen'
                    : ($failed > 0 && $freigegeben + $uebernommen === 0 ? 'fehlgeschlagen' : 'erledigt'))));
            $out[] = [
                'kind' => $d['kind'], 'label' => $d['label'], 'total' => $total,
                'running' => $running, 'done' => $done, 'freigegeben' => $freigegeben,
                'verworfen' => $verworfen, 'failed' => $failed, 'geplant' => $geplant,
                'uebernommen' => $uebernommen, 'uebernommen_unreif' => $unreif,
                'fertig' => $done + $freigegeben + max(0, $uebernommen - $unreif),
                'zustand' => $zustand,
            ];
        }

        return $out;
    }

    /**
     * Landing-Kaskaden-Status je Session (finale Etappe — Hauptseite): der JÜNGSTE Lauf einer Session
     * bestimmt ihr Status-Badge und ihren Stufen-Fortschritt. Zwei Query-Pässe (Läufe + deren Steps),
     * in PHP gruppiert — KEIN N+1 über die Session-Liste. Sessions ohne Lauf tauchen nicht auf → die
     * Blade zeigt sie als „Entwurf" (verwaister Entwurf, sichtbar). Rein für die Anzeige.
     *
     * Status-Ableitung = jüngster Lauf-Status (running→läuft · review→prüfen · done→fertig ·
     * failed→fehlgeschlagen), Spiegel von {@see PlanningCascadeService::recomputeRunStatus}.
     *
     * @param  list<int>  $sessionIds
     * @return array<int,array<string,mixed>>  sessionId → {status, running, run_id, scope, stufen}
     */
    public function landingKaskadenMap(?Team $team, array $sessionIds): array
    {
        if ($team === null || $sessionIds === []) {
            return [];
        }
        // Jüngster Lauf je Session (orderByDesc id → erster Treffer je Session gewinnt = Retry gewinnt).
        $laeufe = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->whereIn('planning_session_id', $sessionIds)
            ->orderByDesc('id')
            ->get(['id', 'planning_session_id', 'scope', 'status', 'source_owner_type', 'source_owner_id']);
        $latest = [];
        foreach ($laeufe as $r) {
            $sid = (int) $r->planning_session_id;
            if (! isset($latest[$sid])) {
                $latest[$sid] = $r;
            }
        }
        if ($latest === []) {
            return [];
        }
        // Steps aller jüngsten Läufe in EINEM Pass holen → je Lauf gruppieren (kein N+1).
        $runIds = array_map(fn ($r) => (int) $r->id, array_values($latest));
        $stepsByRun = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::whereIn('cascade_run_id', $runIds)
            // `deferred` mitholen: stufenAusSteps liest daraus den Reifegrad der Übernahmen.
            // Ohne die Spalte zählte eine unreife Übernahme auf der Hauptseite still als fertig.
            // `phase`/`phase_at` (Spec 53 / Paket C): Poll-Gate + Board-Anzeige der laufenden Phase.
            ->get(['cascade_run_id', 'kind', 'status', 'label', 'ref_id', 'deferred', 'phase', 'phase_at'])
            ->groupBy('cascade_run_id');

        // Spec 42 / Board: Ausgabe-Ziel (Owner) je Lauf — Namen batch-auflösen (ein Query je Owner-Typ,
        // kein N+1). foodbook=label, speisekarte/speiseplan=name, offer=Angebots-Referenz (id-only).
        $ownerIds = [];
        foreach ($latest as $r) {
            if ($r->source_owner_type !== null && $r->source_owner_id !== null) {
                $ownerIds[(string) $r->source_owner_type][] = (int) $r->source_owner_id;
            }
        }
        $ownerNamen = ['foodbook' => [], 'speisekarte' => [], 'speiseplan' => []];
        if (! empty($ownerIds['foodbook'])) {
            $ownerNamen['foodbook'] = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::whereIn('id', array_unique($ownerIds['foodbook']))->pluck('label', 'id')->all();
        }
        if (! empty($ownerIds['speisekarte'])) {
            $ownerNamen['speisekarte'] = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::whereIn('id', array_unique($ownerIds['speisekarte']))->pluck('name', 'id')->all();
        }
        if (! empty($ownerIds['speiseplan'])) {
            $ownerNamen['speiseplan'] = \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::whereIn('id', array_unique($ownerIds['speiseplan']))->pluck('name', 'id')->all();
        }

        $badge = ['running' => 'läuft', 'review' => 'prüfen', 'done' => 'fertig', 'failed' => 'fehlgeschlagen'];
        $out = [];
        foreach ($latest as $sid => $r) {
            $steps = $stepsByRun->get((int) $r->id) ?? collect();
            $ot = $r->source_owner_type !== null ? (string) $r->source_owner_type : null;
            $oid = $r->source_owner_id !== null ? (int) $r->source_owner_id : null;
            // Spec 53 / Paket C: ein Step kann noch eine Phase melden (Anreicherung/Konformität), auch
            // wenn der Run selbst schon nicht mehr `running` ist — Board-Poll-Gate ($irgendeinLaeuft)
            // und Anzeige sollen das sehen. Jüngste Phase (nach phase_at) für die Board-Zeile.
            $phaseStep = $steps->whereNotNull('phase')->sortByDesc('phase_at')->first();
            $out[$sid] = [
                'status' => $badge[$r->status] ?? (string) $r->status,
                'running' => $r->status === 'running' || $phaseStep !== null,
                'phase' => $phaseStep?->phase,
                'run_id' => (int) $r->id,
                'scope' => (string) $r->scope,
                'stufen' => $this->stufenAusSteps($steps),
                // Auto-Titel-Baustein: Name des erzeugten Artefakts (Concept/Gericht/Rezept) dieses Laufs.
                'titel' => $this->artefaktTitel($steps, (string) $r->scope),
                // Board: Ausgabe-Ziel (Owner-Typ + Name, falls auflösbar).
                'owner_type' => $ot,
                'owner_name' => ($ot !== null && $oid !== null) ? ($ownerNamen[$ot][$oid] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * Auto-Titel: Name des erzeugten Artefakts eines Laufs — bevorzugt die Run-Ebene
     * (concept > gericht > rezept), sonst irgendein benanntes Step-Artefakt. Der Step-`label`
     * trägt nach markStepDone den echten Rezept-/Concept-Namen.
     */
    public function artefaktTitel($steps, string $scope): ?string
    {
        foreach (array_values(array_unique([$scope, 'concept', 'gericht', 'rezept'])) as $kind) {
            $s = $steps->first(fn ($x) => $x->kind === $kind && $x->ref_id !== null && trim((string) $x->label) !== '');
            if ($s !== null) {
                return trim((string) $s->label);
            }
        }
        $any = $steps->first(fn ($x) => $x->ref_id !== null && trim((string) $x->label) !== '');

        return $any !== null ? trim((string) $any->label) : null;
    }
}
