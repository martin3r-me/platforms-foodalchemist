<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use RuntimeException;

/**
 * 12·S2a-2 (R2.4) — der Solver-Motor: Rahmen rein, DB-maximale Zusammenstellung raus.
 *
 * Ersetzt den greedy Schritt aus `ConceptGeneratorService::fuelleBestehendesKonzept`
 * für den wirtschaftlichen Blick. **Read-only** — dieser Service legt nichts an und
 * ändert nichts; Übernahme ist S2b (`assemblierung.POST`, explizit, `status=draft`).
 *
 * ## Drei Wahrheiten, die dieser Motor NICHT selbst mitbringt
 * 1. **Der Kandidaten-Pool** kommt aus `MenuCandidatePoolService` (12·S2a-1) — dieselbe
 *    Menge, aus der Generator und Weg-B-Vorschlag wählen. Der Solver kann nichts
 *    erfinden, weil im Pool nichts Erfundenes steht (DoD „keine Halluzination").
 * 2. **Die Zielfunktion** ist `wirtschaft.db_eur` je Kandidat, also das Zahlenpaar der
 *    Standard-Darreichung — dasselbe, das die W%-Ampel und das L8-Wirtschaftlichkeits-
 *    Glied anzeigen. Ein Solver, der ein anderes DB maximiert als die Anzeige daneben,
 *    ist nicht gegenzeichenbar.
 * 3. **Die Messung** ist `CoverageService::befundeFuer` (R4.2-Ampel) auf einem
 *    hypothetischen Ist. Die Befunde im Ergebnis sind **dieselbe** Messlatte, die
 *    Mensch und KI am gespeicherten Konzept sehen.
 *
 * ## Was hart ist und was nicht — die tragende Entscheidung
 * **Hart** sind nur die Slot-Filter des Pools (No-Go-Zutat, No-Go-Allergen, Slot-
 * Preisband): sie bestimmen, wer überhaupt Kandidat ist, und wirken schon heute im
 * Generator hart. Alles Menü-weite (Diät-Quoten, Preisband p. P.) ist **lexikografisch**
 * gewichtet: der Motor minimiert zuerst die Zahl verletzter Vorgaben, maximiert dann
 * das DB.
 *
 * Begründung: eine „mind. 2× vegan"-Quote, die der Bestand nicht hergibt, darf nicht
 * dazu führen, dass der Solver **nichts** liefert — das wäre eine unbrauchbare Antwort
 * auf eine legitime Frage. Umgekehrt darf eine unerfüllbare Vorgabe auch nicht still
 * verschwinden: sie taucht als roter Befund auf. Genau dieselbe Haltung wie beim leeren
 * Slot (DoD: „bleibt leer + Begründung") statt Abbruch.
 *
 * ## Drei Verfahren, ehrlich benannt
 * · `slot_unabhaengig` — kein Menü-weiter Constraint UND paarweise disjunkte Kandidaten-
 *   Mengen: je Slot die besten n. Exakt, ohne Suche.
 * · `exakt` — Branch-and-Bound über die Plätze (mit Eindeutigkeit über das ganze Menü),
 *   gesät mit der Greedy-Lösung als Schranke. Exakt bzgl. der lexikografischen
 *   Zielfunktion, solange der Knoten-Deckel nicht greift.
 * · `heuristik` — Constraint-aware-Greedy + Local-Swap. Wird gewählt, wenn der Suchraum
 *   a priori zu groß ist ODER der Deckel im B&B gegriffen hat. `exakt=false` sagt das
 *   ausdrücklich — eine Lösung ohne Optimalitäts-Zusicherung wird nie als optimal verkauft.
 */
class MenuAssemblyService
{
    /**
     * Harte Obergrenze besuchter Suchknoten — Netz gegen Pathologien. Greift sie, ist die
     * Lösung nicht mehr als exakt ausweisbar (`deckel_erreicht=true`, `exakt=false`).
     */
    public const KNOTEN_DECKEL = 200000;

    /**
     * A-priori-Grenze des **geschätzten Kombinations-Raums** (Produkt der Slot-Kombinationen
     * ∏ C(|Kandidaten|, n)). Darüber wird gar nicht erst gesucht, sondern direkt heuristisch
     * gelöst.
     *
     * **Warum eine Raum-Schätzung und nicht „Slot-Zahl klein":** am echten Dev-Gerüst
     * (2 Slots, 3 + 4 Plätze, 26 zulässige Kandidaten je Slot) sind das C(26,3)·C(26,4)
     * ≈ 39 Mio. Kombinationen — sieben Plätze sind also eine *kleine Slot-Zahl* und
     * trotzdem hoffnungslos. Eine Grenze auf Plätzen/Kandidaten hätte den B&B gestartet,
     * 200.000 Knoten verbrannt und wäre am Deckel gelandet: 300 ms Rechenzeit für eine
     * Antwort, die die Heuristik direkt gegeben hätte. Die Schätzung nennt den Fall vorher.
     */
    public const EXAKT_RAUM_MAX = 200000;

    /** Runden des Local-Swap im Heuristik-Pfad. */
    public const SWAP_RUNDEN = 3;

    public function __construct(
        private MenuCandidatePoolService $pool,
        private CoverageService $coverage,
    ) {}

    /**
     * DB-maximale Zusammenstellung für ein Planungs-Gerüst.
     *
     * `$gaeste` skaliert nur die Ausgabe (DB gesamt × Gäste) — die Auswahl selbst ist
     * pro-Person-Logik, weil alle Bänder des Gerüsts p. P. definiert sind.
     *
     * @return array{
     *   verfahren:string, exakt:bool, knoten:int, deckel_erreicht:bool, suchraum:?int,
     *   zielfunktion:array{db_gesamt:float, vk_pp:float, ek_pp:float, wareneinsatz_pct:?float, db_pp:float},
     *   gaeste:?int, db_gesamt_gaeste:?float,
     *   slots:list<array>, gerichte:list<array>, unvollstaendig:list<array>,
     *   befunde:list<array>, zusammenfassung:array<string,int>, ampel_gesamt:string,
     *   verletzungen:int, nicht_bewertet:list<string>, pool:array{gesamt:int, mit_db:int}
     * }
     */
    public function assembliere(Team $team, FoodAlchemistPlanningFrame $frame, ?int $gaeste = null): array
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        if ($frame->slots->isEmpty()) {
            throw new RuntimeException('Planungs-Gerüst ohne Slots — erst Dramaturgie/Mengengerüst anlegen (R4.1).');
        }

        $pool = $this->pool->fuerFrame($team, $frame, false, true);

        $aufgaben = [];
        foreach ($frame->slots as $frameSlot) {
            $kandidaten = $this->pool->filterFuerSlot($pool, $frame, $frameSlot)
                ->map(fn (array $k) => $this->kandidat($k))
                ->sortBy([['db', 'desc'], ['name', 'asc'], ['id', 'asc']])
                ->values()->all();
            $aufgaben[] = [
                'slot' => $frameSlot,
                'n' => max(1, (int) ($frameSlot->target_count ?? 1)),
                'kandidaten' => $kandidaten,
                'best_db' => $kandidaten === [] ? 0.0 : max(0.0, max(array_column($kandidaten, 'db'))),
                'quoten' => $frameSlot->rules->where('rule_type', 'diet_quota')->values()->all(),
                'filter' => $this->pool->filterBeschreibung($frame, $frameSlot),
            ];
        }

        $menuQuoten = $frame->rules->whereNull('slot_id')->where('rule_type', 'diet_quota')->values()->all();
        $band = [
            'min' => $frame->price_min_pp !== null ? (float) $frame->price_min_pp : null,
            'max' => $frame->price_max_pp !== null ? (float) $frame->price_max_pp : null,
        ];
        $menuWeit = $menuQuoten !== [] || $band['min'] !== null || $band['max'] !== null;
        $slotQuoten = collect($aufgaben)->contains(fn (array $a) => $a['quoten'] !== []);

        $raum = $this->raumSchaetzung($aufgaben);

        $knoten = 0;
        $deckel = false;

        if (! $menuWeit && ! $slotQuoten && $this->disjunkt($aufgaben)) {
            // Slots sind wirklich unabhängig: kein Menü-weiter Constraint, keine Slot-Quote,
            // und kein Gericht kann zwei Slots bedienen → je Slot die besten n ist optimal.
            $wahl = $this->slotUnabhaengig($aufgaben);
            $verfahren = 'slot_unabhaengig';
            $exakt = true;
        } else {
            $wahl = $this->greedy($aufgaben, $menuQuoten, $band);
            $verfahren = 'heuristik';
            $exakt = false;

            if ($raum <= self::EXAKT_RAUM_MAX) {
                $bnb = $this->branchAndBound($aufgaben, $menuQuoten, $band, $wahl);
                $knoten = $bnb['knoten'];
                $deckel = $bnb['deckel'];
                if ($bnb['wahl'] !== null) {
                    $wahl = $bnb['wahl'];
                }
                if (! $deckel) {
                    $verfahren = 'exakt';
                    $exakt = true;
                }
            }
            if (! $exakt) {
                $wahl = $this->localSwap($aufgaben, $wahl, $menuQuoten, $band);
            }
        }

        return $this->ergebnis($frame, $aufgaben, $wahl, $pool, $gaeste, $verfahren, $exakt, $knoten, $deckel, $raum);
    }

    /**
     * Geschätzter Kombinations-Raum: ∏ über die Slots von C(|Kandidaten|, n) — die
     * Eindeutigkeit übers Menü ignoriert (sie verkleinert den Raum nur, also bleibt die
     * Schätzung auf der sicheren Seite). Rechnet mit früher Kappung, damit große Gerüste
     * keine Fakultäts-Überläufe erzeugen: sobald `EXAKT_RAUM_MAX` überschritten ist, zählt
     * nur noch „zu groß".
     */
    private function raumSchaetzung(array $aufgaben): float
    {
        $raum = 1.0;
        foreach ($aufgaben as $a) {
            $m = count($a['kandidaten']);
            $k = min($a['n'], $m);
            $raum *= $this->binomial($m, $k);
            if ($raum > self::EXAKT_RAUM_MAX) {
                return INF;
            }
        }

        return $raum;
    }

    /** C(n,k), früh gekappt bei `EXAKT_RAUM_MAX` (der genaue Wert interessiert darüber nicht). */
    private function binomial(int $n, int $k): float
    {
        if ($k <= 0 || $k >= $n) {
            return 1.0;
        }
        $k = min($k, $n - $k);
        $c = 1.0;
        for ($i = 1; $i <= $k; $i++) {
            $c = $c * ($n - $k + $i) / $i;
            if ($c > self::EXAKT_RAUM_MAX) {
                return INF;
            }
        }

        return $c;
    }

    // ── Kandidaten-Projektion ───────────────────────────────────────────

    /**
     * Pool-Zeile → Solver-Kandidat. Die Pool-Zeile selbst bleibt erhalten (`zeile`), weil
     * sie exakt die Shape von `CoverageService::gerichtZeile` hat und damit direkt als Ist
     * in die Messung geht — keine zweite Projektion, kein zweiter Preisbegriff.
     *
     * `db` ist 0.0, wenn die Wirtschafts-Achse unvollständig ist (kein VK oder kein EK):
     * der Kandidat fliegt NICHT raus (das wäre eine stille Portfolio-Verengung), trägt aber
     * nichts zum Ziel bei und wird im Ergebnis als Lücke benannt.
     */
    private function kandidat(array $k): array
    {
        $w = $k['wirtschaft'] ?? null;

        return [
            'id' => (int) $k['id'],
            'name' => (string) $k['name'],
            'diet_form' => $k['diet_form'],
            'sales_net' => $k['sales_net'] !== null ? (float) $k['sales_net'] : null,
            'db' => ($w['db_eur'] ?? null) !== null ? (float) $w['db_eur'] : 0.0,
            'ek_portion' => ($w['ek_portion'] ?? null) !== null ? (float) $w['ek_portion'] : null,
            'vollstaendig' => (bool) ($w['vollstaendig'] ?? false),
            'preis_quelle' => $k['preis_quelle'] ?? null,
            'zeile' => $k,
        ];
    }

    /** Können zwei Slots dasselbe Gericht bedienen? Dann sind sie nicht unabhängig. */
    private function disjunkt(array $aufgaben): bool
    {
        $gesehen = [];
        foreach ($aufgaben as $a) {
            foreach ($a['kandidaten'] as $k) {
                if (isset($gesehen[$k['id']])) {
                    return false;
                }
                $gesehen[$k['id']] = true;
            }
        }

        return true;
    }

    // ── Verfahren 1: unabhängige Slots ──────────────────────────────────

    /** @return array<int, list<array>> slot-Index → gewählte Kandidaten */
    private function slotUnabhaengig(array $aufgaben): array
    {
        $wahl = [];
        foreach ($aufgaben as $si => $a) {
            $wahl[$si] = array_slice($a['kandidaten'], 0, $a['n']);
        }

        return $wahl;
    }

    // ── Verfahren 2: Branch-and-Bound (exakt) ───────────────────────────

    /**
     * Exakte Suche über alle Plätze, mit Eindeutigkeit über das GANZE Menü (ein Gericht
     * kommt nie zweimal vor — dieselbe Regel wie im Generator).
     *
     * Zielfunktion lexikografisch: **erst** wenige verletzte Vorgaben, **dann** hohes DB.
     * Beschnitten wird über (a) die untere Schranke der schon unvermeidbaren Verletzungen,
     * (b) die obere DB-Schranke aus den je Slot bestmöglichen Restplätzen und (c) die
     * Anordnung innerhalb eines Slots (Index strikt steigend) — damit ist eine Slot-Auswahl
     * eine Menge und keine Permutation.
     *
     * @param  array<int, list<array>>|null  $seed  Greedy-Lösung als Startschranke
     * @return array{wahl:array<int, list<array>>|null, knoten:int, deckel:bool}
     */
    private function branchAndBound(array $aufgaben, array $menuQuoten, array $band, ?array $seed): array
    {
        $st = ['knoten' => 0, 'deckel' => false, 'wahl' => null, 'db' => -INF, 'verl' => PHP_INT_MAX];
        if ($seed !== null) {
            $flach = $this->flach($seed);
            $st['wahl'] = $seed;
            $st['db'] = $this->dbSumme($flach);
            $st['verl'] = $this->verletzungenIntern($flach, $aufgaben, $seed, $menuQuoten, $band);
        }

        $this->bnbSchritt($aufgaben, 0, 0, 0, [], [], 0.0, 0.0, $st, $menuQuoten, $band);

        return ['wahl' => $st['wahl'], 'knoten' => $st['knoten'], 'deckel' => $st['deckel']];
    }

    /**
     * @param  array<int,bool>  $benutzt  recipe-id → true
     * @param  array<int, list<array>>  $aktuell
     * @param  array<string,mixed>  $st
     */
    private function bnbSchritt(array $aufgaben, int $si, int $wi, int $startIdx, array $benutzt, array $aktuell, float $db, float $vk, array &$st, array $menuQuoten, array $band): void
    {
        if ($st['deckel']) {
            return;
        }
        if (++$st['knoten'] > self::KNOTEN_DECKEL) {
            $st['deckel'] = true;

            return;
        }

        // Blatt: alle Slots durch → volle Bewertung
        if ($si >= count($aufgaben)) {
            $flach = $this->flach($aktuell);
            $verl = $this->verletzungenIntern($flach, $aufgaben, $aktuell, $menuQuoten, $band);
            if ($verl < $st['verl'] || ($verl === $st['verl'] && $db > $st['db'])) {
                $st['verl'] = $verl;
                $st['db'] = $db;
                $st['wahl'] = $aktuell;
            }

            return;
        }

        // (a) Untere Schranke der unvermeidbaren Verletzungen — monoton wachsend
        $verlUnten = $this->verletzungenUnten($aktuell, $aufgaben, $menuQuoten, $band, $vk);
        if ($verlUnten > $st['verl']) {
            return;
        }
        // (b) Obere DB-Schranke: pro Restplatz das Beste seines Slots (Eindeutigkeit relaxiert)
        if ($verlUnten === $st['verl'] && $db + $this->restSchranke($aufgaben, $si, $wi) <= $st['db']) {
            return;
        }

        $a = $aufgaben[$si];

        // Slot fertig → weiter zum nächsten
        if ($wi >= $a['n']) {
            $this->bnbSchritt($aufgaben, $si + 1, 0, 0, $benutzt, $aktuell, $db, $vk, $st, $menuQuoten, $band);

            return;
        }

        $gesetzt = false;
        for ($idx = $startIdx; $idx < count($a['kandidaten']); $idx++) {
            $k = $a['kandidaten'][$idx];
            if (isset($benutzt[$k['id']])) {
                continue;
            }
            $gesetzt = true;
            $benutztNeu = $benutzt + [$k['id'] => true];
            $aktuellNeu = $aktuell;
            $aktuellNeu[$si][] = $k;
            $this->bnbSchritt(
                $aufgaben, $si, $wi + 1, $idx + 1, $benutztNeu, $aktuellNeu,
                $db + $k['db'], $vk + ($k['sales_net'] ?? 0.0), $st, $menuQuoten, $band
            );
        }

        // Kein zulässiger Kandidat mehr für diesen Platz: der Platz bleibt LEER und der Slot
        // ist zu Ende. Ein Platz darf nur mangels Alternative leer bleiben, nie als Wahl —
        // sonst könnte der Solver Prozent-Quoten über den Nenner schönrechnen. Deshalb die
        // zweite Bedingung: erschöpft ist nur der aufsteigende Index-Lauf, nicht der Slot —
        // liegt irgendwo darunter noch ein freier Kandidat, gibt es einen Zweig mit vollem
        // Slot, und dieser Teilzweig wäre eine künstlich kleinere Menge.
        if (! $gesetzt && ! $this->freiVorhanden($a['kandidaten'], $benutzt)) {
            $aktuellNeu = $aktuell;
            $aktuellNeu[$si] = $aktuell[$si] ?? [];
            $this->bnbSchritt($aufgaben, $si + 1, 0, 0, $benutzt, $aktuellNeu, $db, $vk, $st, $menuQuoten, $band);
        }
    }

    /** Gibt es in dieser Kandidaten-Liste überhaupt noch einen unbenutzten? */
    private function freiVorhanden(array $kandidaten, array $benutzt): bool
    {
        foreach ($kandidaten as $k) {
            if (! isset($benutzt[$k['id']])) {
                return true;
            }
        }

        return false;
    }

    /** Obere DB-Schranke der Restplätze: je Restplatz das Beste seines Slots (≥ 0). */
    private function restSchranke(array $aufgaben, int $si, int $wi): float
    {
        $rest = ($aufgaben[$si]['n'] - $wi) * $aufgaben[$si]['best_db'];
        for ($j = $si + 1; $j < count($aufgaben); $j++) {
            $rest += $aufgaben[$j]['n'] * $aufgaben[$j]['best_db'];
        }

        return $rest;
    }

    // ── Verfahren 3: Greedy + Local-Swap ────────────────────────────────

    /**
     * Constraint-aware-Greedy: Slot für Slot, innerhalb des Slots zuerst die Plätze, die
     * eine offene `min`/`exact`-Quote schließen (Slot-Quote vor Menü-Quote), dann das
     * beste DB. Kandidaten, die eine `max`-Quote oder das Preis-Maximum sprengen würden,
     * werden zurückgestellt, solange es Alternativen gibt.
     *
     * @return array<int, list<array>>
     */
    private function greedy(array $aufgaben, array $menuQuoten, array $band): array
    {
        $wahl = [];
        $benutzt = [];
        $vk = 0.0;

        foreach ($aufgaben as $si => $a) {
            $wahl[$si] = [];
            for ($p = 0; $p < $a['n']; $p++) {
                $offen = $this->offeneBedarfe($a['quoten'], $wahl[$si])
                    + $this->offeneBedarfe($menuQuoten, $this->flach($wahl));

                $frei = array_values(array_filter($a['kandidaten'], fn (array $k) => ! isset($benutzt[$k['id']])));
                if ($frei === []) {
                    break;
                }

                $treffer = null;
                // 1. Runde: Bedarf schließen, ohne ein Maximum zu sprengen
                foreach ($frei as $k) {
                    if (($offen[$k['diet_form']] ?? 0) > 0 && ! $this->sprengt($k, $wahl, $si, $aufgaben, $menuQuoten, $band, $vk)) {
                        $treffer = $k;
                        break;
                    }
                }
                // 2. Runde: bestes DB, ohne ein Maximum zu sprengen
                if ($treffer === null) {
                    foreach ($frei as $k) {
                        if (! $this->sprengt($k, $wahl, $si, $aufgaben, $menuQuoten, $band, $vk)) {
                            $treffer = $k;
                            break;
                        }
                    }
                }
                // 3. Runde: es geht nicht ohne Verletzung — dann das beste DB (ehrlich, statt Platz leer)
                $treffer ??= $frei[0];

                $wahl[$si][] = $treffer;
                $benutzt[$treffer['id']] = true;
                $vk += $treffer['sales_net'] ?? 0.0;
            }
        }

        return $wahl;
    }

    /**
     * Würde dieser Kandidat eine `max`-Quote (Slot oder Menü) oder das Preis-Maximum p. P.
     * sprengen? Nur die „nicht mehr"-Familie ist hier prüfbar — `min` lässt sich durch das
     * Hinzufügen nie verletzen.
     */
    private function sprengt(array $k, array $wahl, int $si, array $aufgaben, array $menuQuoten, array $band, float $vk): bool
    {
        if ($band['max'] !== null && $vk + ($k['sales_net'] ?? 0.0) > $band['max'] + 0.001) {
            return true;
        }
        foreach ([$aufgaben[$si]['quoten'], $menuQuoten] as $i => $regeln) {
            $menge = $i === 0 ? ($wahl[$si] ?? []) : $this->flach($wahl);
            foreach ($regeln as $q) {
                if ($q->operator !== 'max' || $q->unit !== 'count' || $q->ref_key !== $k['diet_form']) {
                    continue;
                }
                $treffer = count(array_filter($menge, fn (array $x) => $x['diet_form'] === $k['diet_form']));
                if ($treffer + 1 > (float) $q->value_num) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Offene `min`/`exact`-Bedarfe je diet_form (nur `count` — Prozent-Bedarf hängt am
     * Nenner und ist erst am Ende bestimmt).
     *
     * @param  list<array>  $menge
     * @return array<string,int>
     */
    private function offeneBedarfe(array $quoten, array $menge): array
    {
        $out = [];
        foreach ($quoten as $q) {
            if ($q->unit !== 'count' || $q->operator === 'max') {
                continue;
            }
            $treffer = count(array_filter($menge, fn (array $x) => $x['diet_form'] === $q->ref_key));
            $fehlt = (int) ceil((float) $q->value_num) - $treffer;
            if ($fehlt > 0) {
                $out[$q->ref_key] = max($out[$q->ref_key] ?? 0, $fehlt);
            }
        }

        return $out;
    }

    /**
     * Local-Swap auf der Greedy-Lösung: tausche einen gewählten Platz gegen einen freien
     * Kandidaten desselben Slots, wenn das lexikografisch besser ist (weniger Verletzungen,
     * bei Gleichstand mehr DB). Deterministische Reihenfolge, feste Rundenzahl — der Pfad
     * ist eine Heuristik und behauptet keine Optimalität.
     *
     * @param  array<int, list<array>>  $wahl
     * @return array<int, list<array>>
     */
    private function localSwap(array $aufgaben, array $wahl, array $menuQuoten, array $band): array
    {
        $bestDb = $this->dbSumme($this->flach($wahl));
        $bestVerl = $this->verletzungenIntern($this->flach($wahl), $aufgaben, $wahl, $menuQuoten, $band);

        for ($runde = 0; $runde < self::SWAP_RUNDEN; $runde++) {
            $verbessert = false;
            foreach ($aufgaben as $si => $a) {
                foreach (($wahl[$si] ?? []) as $pi => $alt) {
                    $benutzt = [];
                    foreach ($this->flach($wahl) as $x) {
                        $benutzt[$x['id']] = true;
                    }
                    foreach ($a['kandidaten'] as $neu) {
                        if (isset($benutzt[$neu['id']])) {
                            continue;
                        }
                        $probe = $wahl;
                        $probe[$si][$pi] = $neu;
                        $flach = $this->flach($probe);
                        $verl = $this->verletzungenIntern($flach, $aufgaben, $probe, $menuQuoten, $band);
                        $db = $this->dbSumme($flach);
                        if ($verl < $bestVerl || ($verl === $bestVerl && $db > $bestDb + 0.0001)) {
                            $wahl = $probe;
                            $bestVerl = $verl;
                            $bestDb = $db;
                            $verbessert = true;
                            break;
                        }
                    }
                }
            }
            if (! $verbessert) {
                break;
            }
        }

        return $wahl;
    }

    // ── Bewertung ───────────────────────────────────────────────────────

    /** @return list<array> */
    private function flach(array $wahl): array
    {
        $out = [];
        foreach ($wahl as $liste) {
            foreach ($liste as $k) {
                $out[] = $k;
            }
        }

        return $out;
    }

    private function dbSumme(array $flach): float
    {
        return round(array_sum(array_column($flach, 'db')), 4);
    }

    /**
     * Interne Verletzungs-Zahl für die Suchordnung (schnell, ohne Befund-Arrays).
     *
     * **Bewusst nicht die berichtete Zahl:** berichtet wird, was `CoverageService`
     * misst — die Ampel, die Mensch und KI sehen. Diese Zahl hier ordnet nur die Suche
     * (sie läuft bis zu 200.000 Mal). Damit beide nicht auseinanderlaufen, prüft
     * `MenuAssemblyTest` für einen konstruierten Fall, dass Suchordnung und Ampel
     * dieselben Vorgaben als verletzt sehen.
     *
     * Gezählt wird je verletzte Vorgabe 1: jede Diät-Quote (Slot + Menü) und die beiden
     * Enden des Preisbandes p. P. getrennt.
     */
    private function verletzungenIntern(array $flach, array $aufgaben, array $wahl, array $menuQuoten, array $band): int
    {
        $n = 0;
        $vk = array_sum(array_map(fn (array $k) => $k['sales_net'] ?? 0.0, $flach));
        if ($band['max'] !== null && $vk > $band['max'] + 0.001) {
            $n++;
        }
        if ($band['min'] !== null && $vk < $band['min'] - 0.001) {
            $n++;
        }
        $n += $this->quotenVerletzt($menuQuoten, $flach);
        foreach ($aufgaben as $si => $a) {
            $n += $this->quotenVerletzt($a['quoten'], $wahl[$si] ?? []);
        }

        return $n;
    }

    /**
     * Untere Schranke der Verletzungen an einem inneren Knoten: nur was durch weiteres
     * Hinzufügen NICHT mehr heilbar ist (Preis-Maximum überschritten, `max`-Count-Quote
     * überschritten). Monoton — damit als B&B-Schranke zulässig.
     */
    private function verletzungenUnten(array $wahl, array $aufgaben, array $menuQuoten, array $band, float $vk): int
    {
        $n = 0;
        if ($band['max'] !== null && $vk > $band['max'] + 0.001) {
            $n++;
        }
        $flach = $this->flach($wahl);
        foreach ($menuQuoten as $q) {
            if ($q->operator === 'max' && $q->unit === 'count'
                && count(array_filter($flach, fn (array $x) => $x['diet_form'] === $q->ref_key)) > (float) $q->value_num) {
                $n++;
            }
        }
        foreach ($aufgaben as $si => $a) {
            foreach ($a['quoten'] as $q) {
                if ($q->operator === 'max' && $q->unit === 'count'
                    && count(array_filter($wahl[$si] ?? [], fn (array $x) => $x['diet_form'] === $q->ref_key)) > (float) $q->value_num) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Verletzte Diät-Quoten in einer Menge — Formeln gespiegelt aus
     * `CoverageService::pruefeRegel` (count vs. percent, min/max/exact), damit
     * Suchordnung und Ampel dieselbe Rechnung anwenden.
     *
     * @param  list<array>  $menge
     */
    private function quotenVerletzt(array $quoten, array $menge): int
    {
        $n = 0;
        $gesamt = count($menge);
        foreach ($quoten as $q) {
            $treffer = count(array_filter($menge, fn (array $x) => $x['diet_form'] === $q->ref_key));
            $ist = $q->unit === 'percent'
                ? ($gesamt > 0 ? round($treffer / $gesamt * 100, 1) : 0.0)
                : (float) $treffer;
            $soll = (float) $q->value_num;
            $ok = match ($q->operator) {
                'max' => $ist <= $soll,
                'exact' => abs($ist - $soll) < 0.001,
                default => $ist >= $soll,
            };
            if (! $ok) {
                $n++;
            }
        }

        return $n;
    }

    // ── Ergebnis ────────────────────────────────────────────────────────

    /**
     * Ergebnis-Aufbereitung inklusive Messung. Das hypothetische Ist wird in der Shape
     * von `CoverageService::istConcept` gebaut — die Pool-Zeile IST bereits die
     * Gericht-Zeile, es gibt also keine Umrechnung und keinen zweiten Preisbegriff.
     */
    private function ergebnis(FoodAlchemistPlanningFrame $frame, array $aufgaben, array $wahl, Collection $pool, ?int $gaeste, string $verfahren, bool $exakt, int $knoten, bool $deckel, float $raum): array
    {
        $flach = $this->flach($wahl);
        $vkPp = round(array_sum(array_map(fn (array $k) => $k['sales_net'] ?? 0.0, $flach)), 2);
        $ekPp = round(array_sum(array_map(fn (array $k) => $k['ek_portion'] ?? 0.0, $flach)), 4);
        $dbPp = round(array_sum(array_column($flach, 'db')), 2);

        $slots = [];
        $scopes = [];
        foreach ($aufgaben as $si => $a) {
            $gewaehlt = $wahl[$si] ?? [];
            $fehlend = $a['n'] - count($gewaehlt);
            $slots[] = [
                'slot_id' => (int) $a['slot']->id,
                'label' => (string) $a['slot']->label,
                'target_count' => $a['n'],
                'status' => $gewaehlt === [] ? 'leer' : ($fehlend > 0 ? 'teilbefuellt' : 'befuellt'),
                'begruendung' => $gewaehlt === []
                    ? 'Kein VK-Gericht erfüllt die Vorgaben (' . $a['filter'] . ') — Slot bleibt leer.'
                    : ($fehlend > 0 ? "{$fehlend} von {$a['n']} Plätzen unbefüllbar (" . $a['filter'] . ')' : null),
                'kandidaten_zulaessig' => count($a['kandidaten']),
                'gerichte' => array_map(fn (array $k) => [
                    'id' => $k['id'], 'name' => $k['name'], 'diet_form' => $k['diet_form'],
                    'sales_net' => $k['sales_net'], 'ek_portion' => $k['ek_portion'],
                    'db_eur' => $k['vollstaendig'] ? round($k['db'], 2) : null,
                    'preis_quelle' => $k['preis_quelle'],
                ], $gewaehlt),
            ];
            // Auch ein LEERER Slot bekommt seinen Scope (leere Menge) — sonst meldete die
            // Messung „kein Ist-Bezug" statt „0 Gerichte, n fehlen". Der Slot existiert in
            // dieser Zusammenstellung, er ist nur unbefüllbar.
            $key = mb_strtolower(trim((string) $a['slot']->label));
            if ($key !== '') {
                $scopes[$key] = ($scopes[$key] ?? collect())->merge(collect(array_column($gewaehlt, 'zeile')));
            }
        }

        $ist = [
            'gerichte' => collect(array_column($flach, 'zeile'))->unique('id')->values(),
            'scopes' => $scopes,
            // Preis p. P. = Σ VK der gewählten Gerichte. Das ist `preisCockpit` mit einer
            // Portion je Platz — die Assemblierung kennt (noch) keine Mengen je Position.
            'preis_pp' => $vkPp > 0 ? $vkPp : null,
            'saison_ids' => [],
            'kapitel' => [],
        ];

        // Saison ist owner-weit und hängt NICHT an der Gericht-Auswahl — eine
        // season_coverage-Regel gegen eine Zusammenstellung zu messen wäre ein falsches
        // Rot. Sie wird benannt statt bewertet.
        $alle = $this->coverage->befundeFuer($frame, $ist);
        $befunde = array_values(array_filter($alle, fn (array $b) => $b['dimension'] !== 'saison'));
        $nichtBewertet = array_map(
            fn (array $b) => $b['label'] . ' — Saison hängt am Konzept/Foodbook, nicht an der Gericht-Auswahl',
            array_values(array_filter($alle, fn (array $b) => $b['dimension'] === 'saison'))
        );
        $rollup = $this->coverage->ampelZusammenfassung($befunde);

        return [
            'verfahren' => $verfahren,
            'exakt' => $exakt,
            'knoten' => $knoten,
            'deckel_erreicht' => $deckel,
            // Die Zahl, an der der Pfad entschieden wurde — damit „warum heuristisch?"
            // beantwortbar ist, ohne den Service zu lesen. INF = über der Grenze gekappt.
            'suchraum' => is_finite($raum) ? (int) round($raum) : null,
            'zielfunktion' => [
                'db_gesamt' => $dbPp,
                'db_pp' => $dbPp,
                'vk_pp' => $vkPp,
                'ek_pp' => $ekPp,
                'wareneinsatz_pct' => $vkPp > 0 ? round($ekPp / $vkPp * 100, 1) : null,
            ],
            'gaeste' => $gaeste,
            'db_gesamt_gaeste' => $gaeste !== null && $gaeste > 0 ? round($dbPp * $gaeste, 2) : null,
            'slots' => $slots,
            'gerichte' => array_map(fn (array $k) => [
                'id' => $k['id'], 'name' => $k['name'], 'diet_form' => $k['diet_form'],
                'sales_net' => $k['sales_net'], 'db_eur' => $k['vollstaendig'] ? round($k['db'], 2) : null,
            ], $flach),
            // Gewählte Gerichte ohne belastbare Wirtschafts-Achse: sie tragen 0 € zum Ziel
            // bei, was das Ergebnis systematisch zu schlecht aussehen lässt — deshalb benannt
            // statt weggelassen (die Lücke ist ein Datenbefund, kein Solver-Ergebnis).
            'unvollstaendig' => array_values(array_map(
                fn (array $k) => ['id' => $k['id'], 'name' => $k['name'], 'preis_quelle' => $k['preis_quelle']],
                array_filter($flach, fn (array $k) => ! $k['vollstaendig'])
            )),
            'befunde' => $befunde,
            'zusammenfassung' => $rollup['zusammenfassung'],
            'ampel_gesamt' => $rollup['ampel_gesamt'],
            'verletzungen' => $rollup['zusammenfassung']['verletzt'] ?? 0,
            'nicht_bewertet' => $nichtBewertet,
            'pool' => [
                'gesamt' => $pool->count(),
                'mit_db' => $pool->filter(fn (array $k) => ($k['wirtschaft']['vollstaendig'] ?? false) === true)->count(),
            ],
        ];
    }
}
