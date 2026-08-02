<?php

namespace Platform\FoodAlchemist\Livewire\Signale;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;
use Platform\FoodAlchemist\Services\SignalObjectService;
use Platform\FoodAlchemist\Services\SignalPolicyService;
use Platform\FoodAlchemist\Services\SignalTrendService;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * Spec 21 · Tranche P (Etappe S3a) — Signal-DetailPanel für die rechte Fläche der
 * Signale-Seite. Sie war die einzige der sieben Cockpit-Seiten ohne Panel; die
 * betroffenen Objekte hingen vorher als 50er-Liste unter der Signal-Zeile.
 *
 * Bewusst READ-ONLY: eine Objekt-Sprungliste, kein zweiter Aktions-Ort. Reinschauen zeigt
 * WELCHE Objekte betroffen sind und springt in den passenden Editor — bearbeitet wird dort
 * (oder über „KI erledigen lassen" in der Signal-Zeile für den vollen Satz).
 *
 * Was das Panel zeigt:
 *  1. Betroffene Objekte — volle Liste (bis {@see SignalObjectService::PANEL_LIMIT}),
 *     sortierbar, Klick öffnet Rezept-/VK-Modal bzw. springt in den GP-Katalog/Concepter/
 *     Foodbook. Bei KI-Urteil-Typen mit aufgeklappten Copilot-Befunden.
 *  2. Objekt-zentrische Sicht — „was hat dieses Rezept noch?": alle offenen Signale
 *     am selben Objekt, damit man es EINMAL richtig fixt statt dreimal einzeln.
 *  4. Trend-Sparkline — der Verlauf aus E1 dort, wo man hinschaut. Gelesen wird die
 *     **Signal-Seite** der Reihe (`source=signals`, Schlüssel = Signal-Typ); damit
 *     greift V-010 hier by construction nicht (rohe DQ-Metrik-Keys kommen nie vor).
 *  8. Policy-Regler — Schwelle / „akzeptiert bis" / stumm aus E2 direkt am Signal.
 *     **Der Regler gilt für den TYP, nicht für dieses eine Signal** — das muss die
 *     Fläche sagen, sonst liest man ihn als „diesen Befund akzeptieren".
 *
 * 2026-08 verschlankt: die Ursachen-Kette (S3b-3, „warum ist dieses Objekt betroffen")
 * und der Teil-Bulk-Fix samt Dry-Run-Vorschau (S3b-1) sind raus — das Panel ist jetzt reine
 * Navigation. Fixen läuft über den Editor bzw. „KI erledigen lassen" (voller Satz) in der Zeile.
 */
class DetailPanel extends Component
{
    /** Punkt 4: wie viele Messpunkte die Sparkline zeigt (ältester links). */
    private const SERIE_PUNKTE = 24;

    private const SPARK_W = 100;

    private const SPARK_H = 24;

    public ?int $signalId = null;

    /** Objekt-zentrische Sicht: welches Objekt ist aufgeklappt (Punkt 2). */
    public ?string $objektKind = null;

    public ?int $objektId = null;

    /** Sortierung der Objekt-Liste: 'name' | 'name_desc' | 'art'. */
    public string $sort = 'name';

    /** Punkt 8: Policy-Formular aufgeklappt? Die Regler sind bewusst nicht permanent sichtbar. */
    public bool $policyForm = false;

    /** Punkt 8: Formularwerte (Strings, weil aus Inputs) — leer = Regler nicht gesetzt. */
    public ?string $pThreshold = null;

    public ?string $pAcceptedUntil = null;

    public ?string $pNote = null;

    public bool $pMuted = false;

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function mount(?int $signalId = null): void
    {
        $this->signalId = $signalId;
    }

    #[On('signal-selected')]
    public function zeige(int $id): void
    {
        $this->signalId = $id;
        $this->objektKind = null;
        $this->objektId = null;
        $this->meldung = null;
        $this->fehler = null;
        $this->policyForm = false;   // Formularwerte gehören zum vorigen TYP
        // 2026-08-02: Panel läuft als Modal (der `activity`-Slot kollabierte die Signale-Seite,
        // s. review-queue). Öffnen wie RecipeModal — nach dem Laden `modal.open` feuern.
        $this->dispatch('modal.open', name: 'signal-detail');
    }

    /** Hartes Schließen (Backdrop/Escape/✕) → State räumen, damit das nächste Öffnen frisch startet. */
    #[On('modal.closed')]
    public function beiModalClosed(?string $name = null): void
    {
        if ($name === 'signal-detail') {
            $this->signalId = null;
            $this->objektKind = null;
            $this->objektId = null;
            $this->policyForm = false;
        }
    }

    /**
     * Nach einer Lifecycle-Aktion in der Zeile (erledigt/ignoriert/wieder offen) neu
     * rendern: die Liste am Objekt darf nicht ein bereits geschlossenes Signal zeigen.
     * Die Präsenz des Listeners genügt — render() liest ohnehin frisch.
     */
    #[On('signal-geaendert')]
    public function nachAenderung(): void
    {
        // no-op
    }

    /** Objekt auf-/zuklappen — „was hat dieses Rezept noch?" (Punkt 2). */
    public function objektWaehlen(string $kind, int $id): void
    {
        if ($this->objektKind === $kind && $this->objektId === $id) {
            $this->objektKind = null;
            $this->objektId = null;

            return;
        }
        $this->objektKind = $kind;
        $this->objektId = $id;
    }

    public function setSort(string $s): void
    {
        if (in_array($s, ['name', 'name_desc', 'art'], true)) {
            $this->sort = $s;
        }
    }

    /** Aus der Objekt-Sicht auf ein anderes Signal desselben Objekts springen. */
    public function signalOeffnen(int $id): void
    {
        $this->signalId = $id;
        $this->meldung = null;
        $this->fehler = null;
        $this->policyForm = false;   // s. zeige(): der Regler hängt am Typ, nicht am Signal
    }

    // ── S3b-2: Policy-Regler (Punkt 8) ─────────────────────────────────────

    /**
     * Formular auf-/zuklappen. Beim Öffnen werden die **wirksamen** Werte geladen (eigene
     * oder geerbte Policy) — sonst schreibt „Speichern" eine geerbte Entscheidung
     * versehentlich auf leer zurück.
     */
    public function policyFormUmschalten(SignalPolicyService $policies): void
    {
        $this->meldung = null;
        $this->fehler = null;
        if ($this->policyForm) {
            $this->policyForm = false;

            return;
        }
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return;
        }
        $p = $policies->fuer($team, $sig->type);
        $this->pThreshold = $p?->threshold !== null ? (string) $p->threshold : null;
        $this->pAcceptedUntil = $p?->accepted_until?->toDateString();
        $this->pNote = $p?->note;
        $this->pMuted = (bool) $p?->muted;
        $this->policyForm = true;
    }

    /**
     * Policy für den **Typ** setzen. Leere Felder löschen den jeweiligen Regler bewusst
     * (`null` an den Service), damit „Schwelle raus" ohne Umweg über Löschen geht.
     */
    public function policySpeichern(SignalPolicyService $policies): void
    {
        $this->meldung = null;
        $this->fehler = null;
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return;
        }
        $schwelle = trim((string) $this->pThreshold);
        if ($schwelle !== '' && (! ctype_digit($schwelle) || (int) $schwelle < 0)) {
            $this->fehler = 'Schwelle muss eine Zahl ≥ 0 sein (oder leer bleiben).';

            return;
        }
        try {
            $policies->setzen($team, $sig->type, [
                'threshold' => $schwelle === '' ? null : (int) $schwelle,
                'accepted_until' => trim((string) $this->pAcceptedUntil) === '' ? null : trim((string) $this->pAcceptedUntil),
                'note' => trim((string) $this->pNote) === '' ? null : $this->pNote,
                'muted' => $this->pMuted,
            ]);
        } catch (\Throwable $e) {
            $this->fehler = 'Datum nicht lesbar — bitte als JJJJ-MM-TT angeben.';

            return;
        }
        $this->policyForm = false;
        $this->meldung = 'Regler für „' . $sig->type->label() . '" gespeichert — gilt für alle Signale dieses Typs.';
        $this->dispatch('signal-geaendert');
    }

    /** Eigene Policy entfernen — eine geerbte Eltern-Zeile bleibt wirksam und unangetastet. */
    public function policyEntfernen(SignalPolicyService $policies): void
    {
        $this->meldung = null;
        $this->fehler = null;
        [$team, $sig] = $this->kontext();
        if ($sig === null) {
            return;
        }
        $weg = $policies->loeschen($team, $sig->type);
        $this->policyForm = false;
        $this->meldung = $weg
            ? 'Regler entfernt — der Typ meldet wieder ungedämpft.'
            : 'Es gibt keinen eigenen Regler für diesen Typ (eine geerbte Entscheidung bleibt bestehen).';
        $this->dispatch('signal-geaendert');
    }

    /** @return array{0:?\Platform\Core\Models\Team,1:?FoodAlchemistSignal} */
    private function kontext(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        $sig = $team !== null && $this->signalId !== null
            ? FoodAlchemistSignal::visibleToTeam($team)->find($this->signalId)
            : null;
        if ($sig === null) {
            $this->fehler = 'Signal nicht gefunden.';
        }

        return [$team, $sig];
    }

    public function render(SignalObjectService $objekte, SignalPolicyService $policies, SignalTrendService $trend)
    {
        $team = Auth::user()?->currentTeamRelation;
        $sig = $team !== null && $this->signalId !== null
            ? FoodAlchemistSignal::visibleToTeam($team)->find($this->signalId)
            : null;

        $betroffen = $team !== null && $sig !== null ? $objekte->betroffene($team, $sig) : null;
        if ($betroffen !== null) {
            $betroffen['items'] = $this->sortiere($betroffen['items']);
        }

        // Objekt-Sicht nur für das aufgeklappte Objekt auflösen (ein EXISTS je Metrik).
        $aufgeklappt = $team !== null && $sig !== null && $this->objektKind !== null && $this->objektId !== null;
        $objektSignale = $aufgeklappt
            ? $objekte->signaleAmObjekt($team, $this->objektKind, $this->objektId)
            : [];

        // Punkt 4 + 8: Verlauf und Rausch-Guard hängen am Signal-TYP, nicht am Einzelfall.
        $serie = $team !== null && $sig !== null
            ? $trend->serie($team, $sig->type->value, self::SERIE_PUNKTE, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS)
            : [];

        return view('foodalchemist::livewire.signale.detail-panel', [
            'sig' => $sig,
            'plan' => $sig !== null ? SignalCockpit::planFor($sig) : null,
            // 22·H4b/V-033: die Begründung, wenn es keinen Plan gibt — die Fläche zeigt sie
            // STATT des Plan-Kastens, damit „nichts zu tun" eine Aussage bleibt.
            'ohneWeg' => $sig !== null ? SignalCockpit::ohneWegGrund($sig) : null,
            'betroffen' => $betroffen,
            'objektSignale' => $objektSignale,
            'panelLimit' => SignalObjectService::PANEL_LIMIT,
            'policy' => $team !== null && $sig !== null ? $policies->zustandFuer($team, $sig->type) : null,
            'spark' => $this->sparkline($serie),
        ]);
    }

    /**
     * Punkt 4 — Sparkline-Geometrie (normierte Punkte für ein `<polyline>`).
     *
     * Bewusst hier statt im Blade gerechnet: die Normierung ist Logik und soll im Test
     * greifbar sein. **Unter zwei Messpunkten wird nichts gezeichnet** — eine waagerechte
     * Linie aus einem einzigen Punkt behauptet eine Stabilität, die nie gemessen wurde
     * (und genau vor dieser Aussage soll die Zeitreihe schützen).
     *
     * @param  list<array{measured_at:string,count:int,source:string}>  $serie
     * @return array<string,mixed>|null
     */
    private function sparkline(array $serie): ?array
    {
        if (count($serie) < 2) {
            return null;
        }
        $werte = array_map(fn (array $p) => (int) $p['count'], $serie);
        $min = min($werte);
        $max = max($werte);
        $spanne = $max - $min;
        $n = count($werte) - 1;

        $punkte = [];
        foreach ($werte as $i => $w) {
            $x = round(($i / $n) * self::SPARK_W, 2);
            // Flache Reihe (Spanne 0) läuft mittig — nicht am Rand, sonst liest sie sich als Extrem.
            $y = $spanne === 0
                ? self::SPARK_H / 2
                : round(self::SPARK_H - (($w - $min) / $spanne) * self::SPARK_H, 2);
            $punkte[] = $x . ',' . $y;
        }

        return [
            'points' => implode(' ', $punkte),
            'w' => self::SPARK_W,
            'h' => self::SPARK_H,
            'min' => $min,
            'max' => $max,
            'letzter' => end($werte),
            'punkte' => count($werte),
            'von' => $serie[0]['measured_at'],
            'bis' => $serie[count($serie) - 1]['measured_at'],
        ];
    }

    /**
     * Sortierung in PHP, nicht in SQL: die Liste ist bereits gekappt geladen (die
     * Metrik-Query ordnet alphabetisch), und ein zweiter Query-Pfad je Sortierung
     * wäre eine zweite Wahrheit über dasselbe Prädikat.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function sortiere(array $items): array
    {
        usort($items, function (array $a, array $b) {
            return match ($this->sort) {
                'name_desc' => mb_strtolower((string) $b['name']) <=> mb_strtolower((string) $a['name']),
                // 'art': GP vor Rezept, Verkaufsgericht vor Basisrezept, dann Name.
                'art' => [$a['kind'], ! $a['is_sales_recipe'], mb_strtolower((string) $a['name'])]
                    <=> [$b['kind'], ! $b['is_sales_recipe'], mb_strtolower((string) $b['name'])],
                default => mb_strtolower((string) $a['name']) <=> mb_strtolower((string) $b['name']),
            };
        });

        return $items;
    }
}
