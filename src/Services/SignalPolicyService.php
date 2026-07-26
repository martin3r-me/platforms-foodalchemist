<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalPolicy;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;

/**
 * Spec 21 · E2 — Rausch-Guard: aus n Einzel-Alarmen eines Typs wird **eine**
 * Zustands-Zeile („788 Basisrezepte teil-unbepreist — bekannt, akzeptiert bis 31.08.").
 *
 * **Unterdrückt wird die Darstellung, nie der Befund.** Die Einzel-Signale bleiben
 * vollständig in der Tabelle und über den Typ-Filter aufklappbar; nur die Inbox-Liste
 * zeigt statt der Masse eine Zeile. Ein Guard, der Signale löscht oder gar nicht erst
 * entstehen lässt, würde die Zahl schönen und dem Detail-Panel (S3) die Objekte
 * wegnehmen — deshalb greift er bewusst erst an der Präsentation.
 *
 * **Die Veränderung bleibt immer laut:** `threshold`/`accepted_until` dämpfen nur den
 * Bestand; ein Zuwachs schlägt weiter als `qualitaet_drift` durch (E3). Einzig `muted`
 * schaltet auch den Drift ab — das ist die bewusste „interessiert mich nicht"-Aussage.
 */
class SignalPolicyService
{
    /** Zustand einer Typ-Zeile. */
    public const STATE_ALARM = 'alarm';

    public const STATE_AKZEPTIERT = 'akzeptiert';

    public const STATE_FRIST_ABGELAUFEN = 'frist_abgelaufen';

    public const STATE_STUMM = 'stumm';

    public function __construct(private SignalService $signals, private SignalTrendService $trend)
    {
    }

    /**
     * Wirksame Policies je Typ. Eigene Zeile schlägt geerbte Eltern-Zeile
     * (Katalog-Vererbung: das Kind-Team darf die Lage-Bewertung überstimmen).
     *
     * @return array<string,FoodAlchemistSignalPolicy>
     */
    public function alle(Team $team): array
    {
        $rows = FoodAlchemistSignalPolicy::visibleToTeam($team)->get();
        $out = [];
        foreach ($rows as $p) {
            $vorhanden = $out[$p->type] ?? null;
            if ($vorhanden === null || (! $vorhanden->isOwnedBy($team) && $p->isOwnedBy($team))) {
                $out[$p->type] = $p;
            }
        }

        return $out;
    }

    public function fuer(Team $team, SignalTyp $typ): ?FoodAlchemistSignalPolicy
    {
        return $this->alle($team)[$typ->value] ?? null;
    }

    /**
     * Policy setzen/aktualisieren — immer im eigenen Team (geerbte Eltern-Zeile wird
     * nicht verändert, sondern überstimmt). Nur übergebene Schlüssel werden angefasst;
     * `null` löscht den jeweiligen Regler bewusst.
     *
     * @param  array{threshold?:int|null,accepted_until?:string|\DateTimeInterface|null,note?:string|null,muted?:bool}  $attrs
     */
    public function setzen(Team $team, SignalTyp $typ, array $attrs): FoodAlchemistSignalPolicy
    {
        $daten = [];
        if (array_key_exists('threshold', $attrs)) {
            $daten['threshold'] = $attrs['threshold'] === null ? null : max(0, (int) $attrs['threshold']);
        }
        if (array_key_exists('accepted_until', $attrs)) {
            $daten['accepted_until'] = $attrs['accepted_until'] === null || $attrs['accepted_until'] === ''
                ? null
                : Carbon::parse($attrs['accepted_until'])->toDateString();
        }
        if (array_key_exists('note', $attrs)) {
            $daten['note'] = $attrs['note'] === null ? null : trim((string) $attrs['note']);
        }
        if (array_key_exists('muted', $attrs)) {
            $daten['muted'] = (bool) $attrs['muted'];
        }

        return FoodAlchemistSignalPolicy::updateOrCreate(
            ['team_id' => $team->id, 'type' => $typ->value],
            $daten
        )->refresh();
    }

    /** Eigene Policy entfernen (geerbte bleibt wirksam). */
    public function loeschen(Team $team, SignalTyp $typ): bool
    {
        return FoodAlchemistSignalPolicy::where('team_id', $team->id)->where('type', $typ->value)->delete() > 0;
    }

    /**
     * Die Zustands-Sicht: je Typ mit offenen Signalen (oder gesetzter Policy) eine Zeile
     * mit Bestand, Delta zum Vorlauf und Policy-Bewertung. Das ist, was die Signale-Seite
     * über der Einzelliste zeigt und was `signal_policies.GET` zurückgibt.
     *
     * Sortierung: Alarme zuerst (die will man sehen), darin nach Bestand — akzeptierte
     * und stumme Lagen sinken nach unten, verschwinden aber nie.
     *
     * @return list<array<string,mixed>>
     */
    public function zustand(Team $team): array
    {
        $counts = $this->signals->offeneNachTyp($team);
        $policies = $this->alle($team);
        $deltas = $this->deltasJeTyp($team);

        $zeilen = [];
        foreach (SignalTyp::cases() as $typ) {
            $count = (int) ($counts[$typ->value] ?? 0);
            $policy = $policies[$typ->value] ?? null;
            if ($count === 0 && $policy === null) {
                continue; // weder Bestand noch Entscheidung — keine Zeile
            }
            $zeilen[] = $this->zeile($typ, $count, $policy, $deltas[$typ->value] ?? null);
        }

        usort($zeilen, function ($a, $b) {
            $rang = fn (array $z) => $z['state'] === self::STATE_STUMM ? 2 : ($z['state'] === self::STATE_AKZEPTIERT ? 1 : 0);
            return [$rang($a), -$a['count']] <=> [$rang($b), -$b['count']];
        });

        return $zeilen;
    }

    /**
     * Zustands-Zeile für **einen** Typ — die Panel-Sicht (Spec 21 §7 Punkt 8, Etappe S3b-2).
     *
     * Baut über dasselbe {@see zeile()} wie {@see zustand()}, damit State-Ableitung und
     * Hinweis-Text nicht zweimal existieren; nur die Zähl-Seite ist schmaler (ein Typ statt
     * aller). Delta kommt aus {@see SignalTrendService::delta()} statt aus `uebersicht()` —
     * bei der dichten Reihe (E1) sind „zwei jüngste Zeilen dieser Metrik" und „zwei jüngste
     * Läufe" dasselbe Paar, und die schmale Variante ist eine Query statt drei.
     *
     * Zusätzlich `geerbt`: eine vom Eltern-Team übernommene Entscheidung darf man sehen,
     * aber nicht aus dem Panel löschen (Katalog-Vererbung — überstimmt wird mit einer
     * eigenen Zeile, das Original bleibt unangetastet).
     *
     * @return array<string,mixed>
     */
    public function zustandFuer(Team $team, SignalTyp $typ): array
    {
        $policy = $this->fuer($team, $typ);
        $delta = $this->trend->delta($team, $typ->value, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS);

        $zeile = $this->zeile(
            $typ,
            (int) ($this->signals->offeneNachTyp($team)[$typ->value] ?? 0),
            $policy,
            $delta === null ? null : ['previous' => $delta['previous'], 'delta' => $delta['delta']]
        );

        $zeile['gesetzt'] = $policy !== null;
        $zeile['geerbt'] = $policy !== null && ! $policy->isOwnedBy($team);

        return $zeile;
    }

    /**
     * Typen, deren Einzel-Signale in der Inbox zu einer Zustands-Zeile zusammenfallen.
     * Die Liste filtert die Einzelliste — mit explizit gewähltem Typ-Filter zeigt die
     * UI sie trotzdem (Aufklappen bleibt jederzeit möglich).
     *
     * @return list<string>
     */
    public function aggregierteTypen(Team $team): array
    {
        return array_values(array_map(
            fn (array $z) => $z['type'],
            array_filter($this->zustand($team), fn (array $z) => $z['aggregiert'])
        ));
    }

    /**
     * Drift-Stummschaltung (E3). Einzige Policy-Wirkung auf die Veränderungs-Seite:
     * Schwelle und Akzeptanz-Frist dämpfen den *Bestand*, nicht den Zuwachs.
     */
    public function driftStumm(Team $team, ?SignalTyp $typ): bool
    {
        return $typ !== null && (bool) ($this->fuer($team, $typ)?->muted);
    }

    // ---- intern -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function zeile(SignalTyp $typ, int $count, ?FoodAlchemistSignalPolicy $policy, ?array $delta): array
    {
        $muted = (bool) ($policy?->muted);
        $threshold = $policy?->threshold;
        $aggregiert = $muted || ($threshold !== null && $count > $threshold);

        $state = match (true) {
            $muted => self::STATE_STUMM,
            (bool) $policy?->akzeptanzGueltig() => self::STATE_AKZEPTIERT,
            (bool) $policy?->akzeptanzAbgelaufen() => self::STATE_FRIST_ABGELAUFEN,
            default => self::STATE_ALARM,
        };

        return [
            'type' => $typ->value,
            'label' => $typ->label(),
            'icon' => $typ->icon(),
            'count' => $count,
            'previous' => $delta['previous'] ?? null,
            'delta' => $delta['delta'] ?? null,
            'threshold' => $threshold,
            'muted' => $muted,
            'accepted_until' => $policy?->accepted_until?->toDateString(),
            'note' => $policy?->note,
            'state' => $state,
            'aggregiert' => $aggregiert,
            'hinweis' => $this->hinweis($count, $state, $policy, $delta),
        ];
    }

    private function hinweis(int $count, string $state, ?FoodAlchemistSignalPolicy $policy, ?array $delta): string
    {
        $teile = [$count . ' offen'];
        $teile[] = match ($state) {
            self::STATE_STUMM => 'stummgeschaltet (auch kein Drift-Alarm)',
            self::STATE_AKZEPTIERT => 'bekannt, akzeptiert bis ' . $policy?->accepted_until?->format('d.m.Y'),
            self::STATE_FRIST_ABGELAUFEN => 'Akzeptanz-Frist abgelaufen (' . $policy?->accepted_until?->format('d.m.Y') . ')',
            default => 'offener Befund',
        };
        $d = $delta['delta'] ?? null;
        if ($d !== null && $d !== 0) {
            $teile[] = ($d > 0 ? '+' : '') . $d . ' seit dem letzten Lauf';
        }

        return implode(' · ', $teile);
    }

    /**
     * Delta je Signal-Typ aus der Zeitreihe (E1). Ein Aufruf für alle Typen statt n
     * Einzel-Deltas — die Zustands-Sicht rendert bei jedem Seitenaufruf.
     *
     * @return array<string,array{previous:int|null,delta:int|null}>
     */
    private function deltasJeTyp(Team $team): array
    {
        $u = $this->trend->uebersicht($team, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS);
        $out = [];
        foreach ($u['metriken'] as $m) {
            $out[$m['metric_key']] = ['previous' => $m['previous'], 'delta' => $m['delta']];
        }

        return $out;
    }
}
