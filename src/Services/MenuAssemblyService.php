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
 * gewichtet: der Motor minimiert zuerst die Zahl verletzter Vorgaben, dann die Zahl der
 * Slot-Rollen-Brüche (12·S3b), und maximiert erst danach das DB.
 *
 * ## 12·S3b — die Slot-Rolle bindet den Slot (Lesart (b), entschieden 2026-07-28)
 * Der greedy Generator kennt eine Slot-Semantik (passt die Speisen-Hauptgruppe des
 * Gerichts zur Rolle des Slots?) und wertet sie als erstes Ranking-Kriterium; der Solver
 * kannte sie nicht — am echten Gerüst landete damit `[FIN]`-Kuchen im Hauptgang (Bug #651).
 * Gewählte Lesart ist **nicht** „hart" (das hätte am heutigen Bestand die Hälfte des
 * Hauptgangs leer gelassen: Slot will 4 Plätze, 2 passende Kandidaten), sondern eine
 * **weitere lexikografische Ebene vor dem DB** — dieselbe Haltung wie bei den Menü-Quoten:
 * der Slot wird voll, und der Bruch wird ein benannter Befund statt einer Verweigerung.
 *
 * Drei Konsequenzen, die das Verfahren tragen:
 * · **Kein `reject` im Filter.** Die Zulässigkeit (`filterFuerSlot`) bleibt unverändert —
 *   sonst zöge die Regel automatisch auch im Generator, also am Bestandspfad.
 * · **Auflösbarkeit ist eine Eigenschaft des Labels gegen den Bestand**, nicht gegen die
 *   gefilterte Restmenge: gemessen wird gegen den **ganzen** Pool. Sonst verlöre ein Slot
 *   seine Rolle genau dann still, wenn das Preisband die passenden Gerichte wegfiltert —
 *   also im Fall, in dem der Fremdling gemeldet werden MUSS.
 * · **Ein Bruch bleibt sichtbar**: je Slot (`rolle_aufloesbar`/`hg_fremdlinge`), je Gericht
 *   (`passt_zum_slot`) und in `erklaere()` als eigene, lockerbare Vorgabe (`slot_rollen`) —
 *   die Zahl dahinter ist das DB, das die Rollen-Treue kostet.
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
 *
 * ## 12·S2a-3 — die Erklärung
 * `erklaere()` liefert dasselbe Ergebnis plus die Frage dahinter: **welche** Vorgabe bindet,
 * und **wie viel DB** liegt hinter ihrer Lockerung. Ohne sie wäre der Motor eine Black Box —
 * „31 € ist optimal" kann niemand gegenzeichnen, solange nicht sichtbar ist, woran das
 * Optimum hängt. Gebaut als echte Wiederholungsläufe auf demselben Pool, nicht als
 * analytischer Schattenpreis (siehe Docblock dort).
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

    /**
     * Höchstzahl geprüfter Lockerungen in `erklaere()` — jede ist ein vollständiger
     * Solver-Lauf. Wird sie erreicht, sagt das Ergebnis das ausdrücklich
     * (`abgeschnitten`, `lockerbar_gesamt`) statt eine vollständige Liste zu suggerieren.
     */
    public const LOCKERUNGEN_MAX = 12;

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
     *   slot_semantik:array{ebene_aktiv:bool, fremdlinge:int, brueche:list<array>, nicht_aufloesbar:list<string>, hinweis:?string},
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
        $aufgaben = $this->aufgabenFuer($pool, $frame);
        $l = $this->loese($aufgaben, $this->menuQuoten($frame), $this->band($frame));

        return $this->ergebnis($frame, $aufgaben, $l['wahl'], $pool, $gaeste, $l['verfahren'], $l['exakt'], $l['knoten'], $l['deckel'], $l['raum'], true);
    }

    /**
     * Slot-Aufgaben aus dem Pool: je Slot die zulässigen Kandidaten (harte Filter), die
     * Platzzahl, die Slot-Quoten und die Filter-Beschreibung für Leer-Begründungen.
     *
     * `$lockerung` (12·S2a-3) hebt einzelne Vorgaben testweise aus — die Kandidaten-Menge
     * kommt trotzdem aus `filterFuerSlot`, es gibt also keine zweite Zulässigkeits-Wahrheit.
     *
     * **12·S3b:** je Kandidat kommt die Slot-Semantik aus der geteilten Naht
     * (`MenuCandidatePoolService::semantikJeKandidat`) mit — und zwar über den **ganzen**
     * Pool ausgewertet, nicht über die gefilterte Restmenge (Begründung im Klassen-Docblock).
     * Sie geht in die Sortierung **vor** dem DB ein; damit respektiert auch der Pfad
     * `slot_unabhaengig`, der schlicht die ersten n nimmt, die Slot-Rolle. Ist die Ebene
     * gelockert (`slot_semantik`), fällt sie aus der Sortierung heraus — sonst wäre die
     * „gelockerte" Variante in genau diesem Pfad keine.
     *
     * @param  array{regel_ids?: list<int>, slot_preis?: list<int>, slot_semantik?: bool}  $lockerung
     */
    private function aufgabenFuer(Collection $pool, FoodAlchemistPlanningFrame $frame, array $lockerung = []): array
    {
        $ohneRegeln = $lockerung['regel_ids'] ?? [];
        $ohnePreisSlots = $lockerung['slot_preis'] ?? [];
        $semantikAktiv = ($lockerung['slot_semantik'] ?? false) !== true;

        $aufgaben = [];
        foreach ($frame->slots as $frameSlot) {
            $slotLockerung = [
                'regel_ids' => $ohneRegeln,
                'slot_preis' => in_array((int) $frameSlot->id, $ohnePreisSlots, true),
            ];
            $semantik = MenuCandidatePoolService::semantikJeKandidat($pool, $frameSlot);
            $kandidaten = $this->pool->filterFuerSlot($pool, $frame, $frameSlot, $slotLockerung)
                ->map(fn (array $k) => $this->kandidat($k) + ['semantik' => $semantik[(int) $k['id']] ?? 0])
                ->sortBy($semantikAktiv
                    ? [['semantik', 'desc'], ['db', 'desc'], ['name', 'asc'], ['id', 'asc']]
                    : [['db', 'desc'], ['name', 'asc'], ['id', 'asc']])
                ->values()->all();
            $aufgaben[] = [
                'slot' => $frameSlot,
                'n' => max(1, (int) ($frameSlot->target_count ?? 1)),
                'kandidaten' => $kandidaten,
                // Löst das Slot-Label überhaupt auf eine Hauptgruppe im Bestand auf? Nein ⇒
                // die Ebene schweigt für diesen Slot, statt jeden Kandidaten zum Fremdling
                // zu erklären. „Ich kann es nicht sagen" ist eine andere Aussage als „es
                // passt keiner" — und nur die erste ist hier wahr.
                'rolle_aufloesbar' => in_array(1, $semantik, true),
                'best_db' => $kandidaten === [] ? 0.0 : max(0.0, max(array_column($kandidaten, 'db'))),
                'quoten' => $frameSlot->rules->where('rule_type', 'diet_quota')
                    ->reject(fn ($r) => in_array((int) $r->id, $ohneRegeln, true))->values()->all(),
                'filter' => $this->pool->filterBeschreibung($frame, $frameSlot),
            ];
        }

        return $aufgaben;
    }

    /**
     * Menü-weite Diät-Quoten (ohne die gelockerten).
     *
     * @param  list<int>  $ohneRegeln
     */
    private function menuQuoten(FoodAlchemistPlanningFrame $frame, array $ohneRegeln = []): array
    {
        return $frame->rules->whereNull('slot_id')->where('rule_type', 'diet_quota')
            ->reject(fn ($r) => in_array((int) $r->id, $ohneRegeln, true))->values()->all();
    }

    /**
     * Preisband p. P. am Kopf; `$lockerung` nullt gezielt ein Ende.
     *
     * @param  array{band_min?: bool, band_max?: bool}  $lockerung
     * @return array{min: ?float, max: ?float}
     */
    private function band(FoodAlchemistPlanningFrame $frame, array $lockerung = []): array
    {
        return [
            'min' => ($lockerung['band_min'] ?? false) === true || $frame->price_min_pp === null ? null : (float) $frame->price_min_pp,
            'max' => ($lockerung['band_max'] ?? false) === true || $frame->price_max_pp === null ? null : (float) $frame->price_max_pp,
        ];
    }

    /**
     * Die Pfad-Wahl + Suche selbst — ohne Ergebnis-Aufbereitung, damit die Erklärung
     * (12·S2a-3) denselben Motor mit gelockerten Vorgaben erneut fahren kann.
     *
     * `$semantikAktiv=false` hebt die Slot-Rollen-Ebene aus (12·S3b) — dieselbe Mechanik wie
     * jede andere Lockerung in `erklaere()`: derselbe Motor, eine Vorgabe weniger.
     *
     * @return array{wahl: array<int, list<array>>, verfahren: string, exakt: bool, knoten: int, deckel: bool, raum: float}
     */
    private function loese(array $aufgaben, array $menuQuoten, array $band, bool $semantikAktiv = true): array
    {
        $menuWeit = $menuQuoten !== [] || $band['min'] !== null || $band['max'] !== null;
        $slotQuoten = collect($aufgaben)->contains(fn (array $a) => $a['quoten'] !== []);

        $raum = $this->raumSchaetzung($aufgaben);

        $knoten = 0;
        $deckel = false;

        if (! $menuWeit && ! $slotQuoten && $this->disjunkt($aufgaben)) {
            // Slots sind wirklich unabhängig: kein Menü-weiter Constraint, keine Slot-Quote,
            // und kein Gericht kann zwei Slots bedienen → je Slot die besten n ist optimal.
            // „Beste" heißt seit 12·S3b: erst rollen-treu, dann DB — das steckt in der
            // Sortierung aus `aufgabenFuer`, weshalb dieser Pfad hier unverändert bleibt.
            $wahl = $this->slotUnabhaengig($aufgaben);
            $verfahren = 'slot_unabhaengig';
            $exakt = true;
        } else {
            $wahl = $this->greedy($aufgaben, $menuQuoten, $band);
            $verfahren = 'heuristik';
            $exakt = false;

            if ($raum <= self::EXAKT_RAUM_MAX) {
                $bnb = $this->branchAndBound($aufgaben, $menuQuoten, $band, $wahl, $semantikAktiv);
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
                $wahl = $this->localSwap($aufgaben, $wahl, $menuQuoten, $band, $semantikAktiv);
            }
        }

        return ['wahl' => $wahl, 'verfahren' => $verfahren, 'exakt' => $exakt, 'knoten' => $knoten, 'deckel' => $deckel, 'raum' => $raum];
    }

    // ── 12·S2a-3: die Erklärung ─────────────────────────────────────────

    /**
     * Assemblierung **plus** Erklärung: welche Vorgaben binden, und wie viel DB liegt
     * hinter jeder einzelnen.
     *
     * Rückgabe = das Ergebnis von `assembliere()` + Schlüssel `erklaerung`. Ohne diese
     * Schicht ist der Solver eine Black Box: „31 € ist optimal" ist keine Aussage, die
     * jemand gegenzeichnen kann, solange niemand sieht, *woran* das Optimum hängt.
     *
     * ## Wie die Erklärung entsteht — kein Schatten-Modell, sondern echte Läufe
     * Je lockerbarer Vorgabe wird der Motor **noch einmal** gefahren, mit genau dieser
     * einen Vorgabe ausgehoben, auf **demselben** Pool (eine Query-Runde für alles).
     * Bewusst keine analytischen Schattenpreise: das Verfahren ist teils heuristisch und
     * lexikografisch, ein Dual-Wert wäre eine Zahl mit falscher Genauigkeit. Ein zweiter
     * Lauf ist die Wahrheit, die der Motor selbst produziert.
     *
     * Gemessen wird beides mit derselben Elle wie die Basis: DB p. P. über `dbSumme` und
     * Verletzungen über `CoverageService` — es kommt keine dritte Zahl dazu.
     *
     * ## Zwei Grenzen, die ausgewiesen werden statt zu verschwinden
     * · **Untergrenze statt Abstand (V-062):** ist Basis oder Variante heuristisch, ist
     *   das Delta eine *Untergrenze* für den Gewinn der Lockerung, nicht der bewiesene
     *   Abstand zum Optimum. Steht als `delta_ist_untergrenze` an jeder Zeile.
     * · **Zielpreis (V-061):** `target_price_pp` steht **nicht** in der Zielfunktion — der
     *   Motor maximiert DB im erlaubten Band und landet damit systematisch an der
     *   Obergrenze. Die Abweichung wird als eigener Block berichtet, nicht stillschweigend
     *   hingenommen und auch nicht klammheimlich wegoptimiert (das wäre ein anderer
     *   Auftrag: „triff den Preispunkt" statt „beste Marge im Band").
     *
     * @return array<string,mixed> `assembliere()`-Ergebnis + `erklaerung`
     */
    public function erklaere(Team $team, FoodAlchemistPlanningFrame $frame, ?int $gaeste = null): array
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        if ($frame->slots->isEmpty()) {
            throw new RuntimeException('Planungs-Gerüst ohne Slots — erst Dramaturgie/Mengengerüst anlegen (R4.1).');
        }

        $pool = $this->pool->fuerFrame($team, $frame, false, true);

        $basisAufgaben = $this->aufgabenFuer($pool, $frame);
        $basisLoesung = $this->loese($basisAufgaben, $this->menuQuoten($frame), $this->band($frame));
        $basis = $this->ergebnis(
            $frame, $basisAufgaben, $basisLoesung['wahl'], $pool, $gaeste,
            $basisLoesung['verfahren'], $basisLoesung['exakt'], $basisLoesung['knoten'], $basisLoesung['deckel'], $basisLoesung['raum'], true
        );

        ['kandidaten' => $lockerungen, 'nicht_gelockert' => $nichtGelockert] = $this->lockerungsKandidaten($frame, $basisAufgaben);
        $gesamt = count($lockerungen);
        $abgeschnitten = $gesamt > self::LOCKERUNGEN_MAX;
        if ($abgeschnitten) {
            // Muster MAX_DELTA_ITEMS/MAX_RECOMPUTE: kappen ja, still kappen nein. Die
            // Reihenfolge ist deshalb nicht beliebig — Menü-weite Vorgaben zuerst, weil
            // sie alle Slots binden; die Slot-Vorgaben danach in Gerüst-Reihenfolge.
            $lockerungen = array_slice($lockerungen, 0, self::LOCKERUNGEN_MAX);
        }

        $basisKandidaten = $this->kandidatenSumme($basisAufgaben);
        $zeilen = [];
        foreach ($lockerungen as $c) {
            $l = $c['lockerung'];
            $semantikAktiv = ($l['slot_semantik'] ?? false) !== true;
            $aufgaben = $this->aufgabenFuer($pool, $frame, $l);
            $loesung = $this->loese($aufgaben, $this->menuQuoten($frame, $l['regel_ids'] ?? []), $this->band($frame, $l), $semantikAktiv);
            $variante = $this->ergebnis(
                $frame, $aufgaben, $loesung['wahl'], $pool, $gaeste,
                $loesung['verfahren'], $loesung['exakt'], $loesung['knoten'], $loesung['deckel'], $loesung['raum'], $semantikAktiv
            );

            $deltaDb = round($variante['zielfunktion']['db_pp'] - $basis['zielfunktion']['db_pp'], 2);
            $deltaVerl = $variante['verletzungen'] - $basis['verletzungen'];
            $deltaFremd = $variante['slot_semantik']['fremdlinge'] - $basis['slot_semantik']['fremdlinge'];
            $untergrenze = ! $basisLoesung['exakt'] || ! $loesung['exakt'];

            $zeilen[] = [
                'schluessel' => $c['schluessel'],
                'typ' => $c['typ'],
                'ebene' => $c['ebene'],
                'slot_id' => $c['slot_id'],
                'slot_label' => $c['slot_label'],
                'label' => $c['label'],
                'wirkung' => $c['wirkung'],
                // Bindend heißt: das Aushebeln dieser einen Vorgabe verbessert das Ergebnis
                // lexikografisch — mehr DB oder eine Verletzung weniger. Alles andere ist
                // eine Vorgabe, die das Portfolio ohnehin erfüllt (und die man nicht
                // diskutieren muss).
                'bindend' => $deltaDb > 0.005 || $deltaVerl < 0,
                'delta_db_pp' => $deltaDb,
                'delta_db_gaeste' => $gaeste !== null && $gaeste > 0 ? round($deltaDb * $gaeste, 2) : null,
                'delta_verletzungen' => $deltaVerl,
                // 12·S3b: wie viele Slot-Rollen-Brüche die Lockerung kostet (positiv) oder
                // löst (negativ). Bewusst NUR informativ und NICHT in `bindend`: dessen
                // Definition (DB oder Ampel-Verletzung) ist eine bestehende, berichtete
                // Aussage — sie hier lexikografisch nachzuziehen wäre eine stille
                // Verschiebung an einer Auswahl-Nachbarschaft (→ Backlog, nicht Chunk).
                'delta_fremdlinge' => $deltaFremd,
                'kandidaten_delta' => $this->kandidatenSumme($aufgaben) - $basisKandidaten,
                'db_pp_gelockert' => $variante['zielfunktion']['db_pp'],
                'delta_ist_untergrenze' => $untergrenze,
                'warnung' => $c['warnung'],
            ];
        }

        // Sortierung: der größte Hebel zuerst; bei Gleichstand deterministisch über den
        // Schlüssel, damit zwei Läufe dieselbe Liste in derselben Reihenfolge liefern.
        usort($zeilen, fn (array $a, array $b) => [$b['delta_db_pp'], $a['schluessel']] <=> [$a['delta_db_pp'], $b['schluessel']]);

        $ziel = $frame->target_price_pp !== null ? round((float) $frame->target_price_pp, 2) : null;

        $basis['erklaerung'] = [
            'basis' => [
                'verfahren' => $basis['verfahren'],
                'exakt' => $basis['exakt'],
                'db_pp' => $basis['zielfunktion']['db_pp'],
                'vk_pp' => $basis['zielfunktion']['vk_pp'],
                'verletzungen' => $basis['verletzungen'],
                'ampel_gesamt' => $basis['ampel_gesamt'],
            ],
            'constraints' => $zeilen,
            'bindend' => array_values(array_map(
                fn (array $z) => $z['schluessel'],
                array_filter($zeilen, fn (array $z) => $z['bindend'])
            )),
            'nicht_gelockert' => $nichtGelockert,
            'zielpreis' => [
                'ziel_pp' => $ziel,
                'ist_pp' => $basis['zielfunktion']['vk_pp'],
                'abweichung_pp' => $ziel !== null ? round($basis['zielfunktion']['vk_pp'] - $ziel, 2) : null,
                'in_zielfunktion' => false,
                'hinweis' => $ziel === null
                    ? 'Kein Zielpreis p. P. am Gerüst gesetzt.'
                    : 'Der Zielpreis ist KEIN Teil der Zielfunktion: der Motor maximiert DB im erlaubten Band und fährt deshalb an die Obergrenze. „Beste Marge im Band" und „triff den versprochenen Preispunkt" sind zwei Aufträge — die Abweichung wird ausgewiesen, nicht wegoptimiert.',
            ],
            'geprueft' => count($zeilen),
            'lockerbar_gesamt' => $gesamt,
            'abgeschnitten' => $abgeschnitten,
            'hinweis' => $basisLoesung['exakt']
                ? null
                : 'Die Basis ist heuristisch (' . $basis['verfahren'] . ') — jedes Delta ist eine Untergrenze für den Gewinn der Lockerung, nicht der bewiesene Abstand zum Optimum.',
        ];

        return $basis;
    }

    /** Zulässige Kandidaten über alle Slots — die Kennzahl hinter `kandidaten_delta`. */
    private function kandidatenSumme(array $aufgaben): int
    {
        return array_sum(array_map(fn (array $a) => count($a['kandidaten']), $aufgaben));
    }

    /**
     * Welche Vorgaben sind überhaupt lockerbar — und welche ausdrücklich nicht?
     *
     * Die zweite Liste ist keine Nebensache: eine Erklärung, die eine bestehende Vorgabe
     * einfach nicht erwähnt, liest sich wie „geprüft und unkritisch". Deshalb wird jede
     * nicht gelockerte Vorgabe **mit Grund** benannt.
     *
     * @return array{kandidaten: list<array<string,mixed>>, nicht_gelockert: list<string>}
     */
    private function lockerungsKandidaten(FoodAlchemistPlanningFrame $frame, array $aufgaben): array
    {
        $kandidaten = [];
        $nicht = [];

        // 0. Menü-weit und ganz vorn: die Slot-Rollen-Ebene (12·S3b). Sie steht zuerst, weil
        //    sie ALLE Slots bindet und lexikografisch über dem DB liegt — ihre Zahl ist die
        //    Antwort auf „was kostet es, dass im Hauptgang kein Kuchen steht?".
        $aufloesbar = array_values(array_filter($aufgaben, fn (array $a) => ($a['rolle_aufloesbar'] ?? false) === true));
        if ($aufloesbar !== []) {
            $kandidaten[] = $this->lockerungsZeile('slot_rollen', 'slot_rollen', 'menue', null, null,
                'Slot-Rollen — die Speisen-Hauptgruppe muss zum Slot passen (' . count($aufloesbar) . ' von '
                    . count($aufgaben) . ' Slots auflösbar)',
                'Rollen-Ebene aufgehoben: der Motor wählt rein nach DB, ein Dessert im Hauptgang wird zulässig',
                ['slot_semantik' => true]);
        } else {
            $nicht[] = 'Slot-Rollen — kein Slot-Label löst auf eine Speisen-Hauptgruppe im Bestand auf; '
                . 'die Ebene wirkt nicht und ist deshalb auch nicht lockerbar (Auflösung heute über das Label, '
                . 'persistierte Bindung ist 12·S3c).';
        }

        // 1. Menü-weit: das Preisband p. P. — die beiden Enden getrennt, weil sie
        //    verschiedene Fragen sind („zu teuer" vs. „zu billig fürs Versprechen").
        if ($frame->price_max_pp !== null) {
            $kandidaten[] = $this->lockerungsZeile('preisband_max', 'preisband', 'menue', null, null,
                'Preisband p. P. — Obergrenze ' . $this->eur((float) $frame->price_max_pp),
                'Obergrenze aufgehoben: teurere Zusammenstellungen werden zulässig',
                ['band_max' => true]);
        }
        if ($frame->price_min_pp !== null) {
            $kandidaten[] = $this->lockerungsZeile('preisband_min', 'preisband', 'menue', null, null,
                'Preisband p. P. — Untergrenze ' . $this->eur((float) $frame->price_min_pp),
                'Untergrenze aufgehoben: günstigere Zusammenstellungen werden zulässig',
                ['band_min' => true]);
        }

        // 2. Menü-weite Regeln, dann 3. je Slot — die Reihenfolge trägt die Kappung.
        foreach ($frame->rules->whereNull('slot_id')->sortBy('id') as $rule) {
            $z = $this->regelZeile($rule, null);
            $z !== null ? $kandidaten[] = $z : $nicht[] = $this->nichtLockerbar($rule, null);
        }
        foreach ($frame->slots as $slot) {
            if ($slot->price_min !== null || $slot->price_max !== null) {
                $kandidaten[] = $this->lockerungsZeile('slot_preis_' . $slot->id, 'slot_preisband', 'slot',
                    (int) $slot->id, (string) $slot->label,
                    'Slot „' . $slot->label . '“: Preisrahmen ' . ($slot->price_min !== null ? $this->eur((float) $slot->price_min) : '—')
                        . '–' . ($slot->price_max !== null ? $this->eur((float) $slot->price_max) : '—'),
                    'Preisrahmen des Slots aufgehoben: mehr Gerichte werden für diese Position zulässig',
                    ['slot_preis' => [(int) $slot->id]]);
            }
            if (($slot->target_count ?? null) !== null) {
                $nicht[] = 'Slot „' . $slot->label . '“: ' . (int) $slot->target_count . ' Plätze — die Platzzahl ist das Gerüst, keine Vorgabe zum Lockern (weniger Gänge wäre ein anderes Menü, kein besseres).';
            }
            foreach ($slot->rules->sortBy('id') as $rule) {
                $z = $this->regelZeile($rule, $slot);
                $z !== null ? $kandidaten[] = $z : $nicht[] = $this->nichtLockerbar($rule, $slot);
            }
        }

        return ['kandidaten' => $kandidaten, 'nicht_gelockert' => $nicht];
    }

    /** Regel → Lockerungs-Zeile, oder null wenn diese Regel-Art nicht lockerbar ist. */
    private function regelZeile($rule, $slot): ?array
    {
        $prefix = $slot !== null ? 'Slot „' . $slot->label . '“: ' : '';
        $ebene = $slot !== null ? 'slot' : 'menue';
        $slotId = $slot !== null ? (int) $slot->id : null;
        $slotLabel = $slot !== null ? (string) $slot->label : null;
        $schluessel = 'regel_' . $rule->id;
        $l = ['regel_ids' => [(int) $rule->id]];

        return match ($rule->rule_type) {
            'diet_quota' => $this->lockerungsZeile($schluessel, 'diet_quota', $ebene, $slotId, $slotLabel,
                $prefix . 'Diät-Quote ' . $rule->ref_key . ' ' . $rule->operator . ' '
                    . rtrim(rtrim((string) $rule->value_num, '0'), '.') . ($rule->unit === 'percent' ? ' %' : '×'),
                'Quote aufgehoben: die Auswahl ist an diese Diätform nicht mehr gebunden', $l),

            'nogo_ingredient' => $this->lockerungsZeile($schluessel, 'nogo_ingredient', $ebene, $slotId, $slotLabel,
                $prefix . 'No-Go „' . $rule->value_text . '“',
                'No-Go aufgehoben: bisher ausgeschlossene Gerichte werden zulässig', $l),

            'nogo_allergen' => $this->lockerungsZeile($schluessel, 'nogo_allergen', $ebene, $slotId, $slotLabel,
                $prefix . 'No-Go-Allergen ' . $rule->ref_key,
                'Allergen-Ausschluss aufgehoben: Gerichte mit „enthalten"/„spuren" werden zulässig', $l,
                'Diese Lockerung ist eine Gast- und Kennzeichnungsfrage, keine wirtschaftliche — das Delta beziffert sie, es rechtfertigt sie nicht.'),

            default => null,
        };
    }

    /** Grund, warum eine bestehende Vorgabe nicht gelockert wird (nie stillschweigend). */
    private function nichtLockerbar($rule, $slot): string
    {
        $prefix = $slot !== null ? 'Slot „' . $slot->label . '“: ' : '';

        return $prefix . match ($rule->rule_type) {
            'season_coverage' => 'Saison-Abdeckung — hängt am Konzept/Foodbook, nicht an der Gericht-Auswahl; sie wird deshalb schon in der Messung nicht bewertet.',
            'allergen_line' => 'Allergen-Linie („' . $rule->value_text . '“) — Freitext, nicht maschinell messbar, also auch nicht lockerbar.',
            default => 'Regel-Art „' . $rule->rule_type . '“ — dem Motor unbekannt, deshalb weder wirksam noch lockerbar.',
        };
    }

    /** @return array<string,mixed> */
    private function lockerungsZeile(string $schluessel, string $typ, string $ebene, ?int $slotId, ?string $slotLabel, string $label, string $wirkung, array $lockerung, ?string $warnung = null): array
    {
        return [
            'schluessel' => $schluessel, 'typ' => $typ, 'ebene' => $ebene,
            'slot_id' => $slotId, 'slot_label' => $slotLabel,
            'label' => $label, 'wirkung' => $wirkung,
            'lockerung' => $lockerung, 'warnung' => $warnung,
        ];
    }

    private function eur(float $wert): string
    {
        return number_format($wert, 2, ',', '.') . ' €';
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
            // 12·S3b: die Hauptgruppe als Klartext mit, damit ein Rollen-Bruch im Ergebnis
            // benennbar ist („Bienenstich (Dessert) im Slot Hauptgang") und nicht nur zählbar.
            'hg_label' => (string) ($k['hg_label'] ?? ''),
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
     * Zielfunktion lexikografisch: **erst** wenige verletzte Vorgaben, **dann** wenige
     * Slot-Rollen-Brüche (12·S3b), **dann** hohes DB. Beschnitten wird über (a) die untere
     * Schranke der schon unvermeidbaren Verletzungen, (a2) die schon gesetzten Fremdlinge
     * (auch sie können nur wachsen, sind also eine gültige monotone Schranke), (b) die obere
     * DB-Schranke aus den je Slot bestmöglichen Restplätzen und (c) die Anordnung innerhalb
     * eines Slots (Index strikt steigend) — damit ist eine Slot-Auswahl eine Menge und keine
     * Permutation.
     *
     * @param  array<int, list<array>>|null  $seed  Greedy-Lösung als Startschranke
     * @return array{wahl:array<int, list<array>>|null, knoten:int, deckel:bool}
     */
    private function branchAndBound(array $aufgaben, array $menuQuoten, array $band, ?array $seed, bool $semantikAktiv = true): array
    {
        $st = ['knoten' => 0, 'deckel' => false, 'wahl' => null, 'db' => -INF, 'verl' => PHP_INT_MAX, 'fremd' => PHP_INT_MAX, 'semantik' => $semantikAktiv];
        if ($seed !== null) {
            $flach = $this->flach($seed);
            $st['wahl'] = $seed;
            $st['db'] = $this->dbSumme($flach);
            $st['verl'] = $this->verletzungenIntern($flach, $aufgaben, $seed, $menuQuoten, $band);
            $st['fremd'] = $semantikAktiv ? $this->fremdlinge($seed, $aufgaben) : 0;
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
            $fremd = $st['semantik'] ? $this->fremdlinge($aktuell, $aufgaben) : 0;
            if ($this->lexBesser($verl, $fremd, $db, $st['verl'], $st['fremd'], $st['db'])) {
                $st['verl'] = $verl;
                $st['fremd'] = $fremd;
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
        // (a2) Schon gesetzte Rollen-Brüche — ebenfalls monoton (Plätze kommen nur dazu).
        // Greift nur, wenn die Verletzungs-Ebene den Vergleich nicht mehr entscheidet.
        $fremdUnten = $st['semantik'] ? $this->fremdlinge($aktuell, $aufgaben) : 0;
        if ($verlUnten === $st['verl'] && $fremdUnten > $st['fremd']) {
            return;
        }
        // (b) Obere DB-Schranke: pro Restplatz das Beste seines Slots (Eindeutigkeit relaxiert).
        // Nur zulässig, wenn beide vorgelagerten Ebenen gleichstehen — sonst könnte ein Zweig
        // mit weniger Fremdlingen an einer DB-Schranke sterben, obwohl er lexikografisch führt.
        if ($verlUnten === $st['verl'] && $fremdUnten === $st['fremd'] && $db + $this->restSchranke($aufgaben, $si, $wi) <= $st['db']) {
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
    private function localSwap(array $aufgaben, array $wahl, array $menuQuoten, array $band, bool $semantikAktiv = true): array
    {
        $bestDb = $this->dbSumme($this->flach($wahl));
        $bestVerl = $this->verletzungenIntern($this->flach($wahl), $aufgaben, $wahl, $menuQuoten, $band);
        $bestFremd = $semantikAktiv ? $this->fremdlinge($wahl, $aufgaben) : 0;

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
                        $fremd = $semantikAktiv ? $this->fremdlinge($probe, $aufgaben) : 0;
                        $db = $this->dbSumme($flach);
                        if ($this->lexBesser($verl, $fremd, $db, $bestVerl, $bestFremd, $bestDb, 0.0001)) {
                            $wahl = $probe;
                            $bestVerl = $verl;
                            $bestFremd = $fremd;
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
     * 12·S3b — Slot-Rollen-Brüche einer Wahl: gewählte Gerichte, deren Hauptgruppe nicht
     * zur Rolle des Slots passt.
     *
     * Gezählt wird **nur** in Slots, deren Label überhaupt auf eine Hauptgruppe im Bestand
     * auflöst (`rolle_aufloesbar`). Ein Slot „Station Süß" ohne Auflösung liefert sonst
     * lauter Fremdlinge — eine Zahl, die nichts über die Auswahl sagt, aber jede Ebene
     * darunter (das DB) mit einem konstanten Rauschen überlagert.
     *
     * @param  array<int, list<array>>  $wahl
     */
    private function fremdlinge(array $wahl, array $aufgaben): int
    {
        $n = 0;
        foreach ($aufgaben as $si => $a) {
            if (($a['rolle_aufloesbar'] ?? false) !== true) {
                continue;
            }
            foreach (($wahl[$si] ?? []) as $k) {
                if ((int) ($k['semantik'] ?? 0) !== 1) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Die lexikografische Ordnung an EINER Stelle: wenige Verletzungen → wenige Rollen-
     * Brüche → hohes DB. Vorher stand derselbe Vergleich zweimal ausgeschrieben (B&B-Blatt,
     * Local-Swap) und hätte beim Einzug der dritten Ebene zweimal gepflegt werden müssen.
     *
     * `$eps` ist die DB-Toleranz des Aufrufers (der Swap fordert einen echten Gewinn, das
     * B&B-Blatt vergleicht scharf) — die Ebenen darüber sind ganzzahlig und brauchen keine.
     */
    private function lexBesser(int $verl, int $fremd, float $db, int $bVerl, int $bFremd, float $bDb, float $eps = 0.0): bool
    {
        if ($verl !== $bVerl) {
            return $verl < $bVerl;
        }
        if ($fremd !== $bFremd) {
            return $fremd < $bFremd;
        }

        return $db > $bDb + $eps;
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
    private function ergebnis(FoodAlchemistPlanningFrame $frame, array $aufgaben, array $wahl, Collection $pool, ?int $gaeste, string $verfahren, bool $exakt, int $knoten, bool $deckel, float $raum, bool $semantikAktiv = true): array
    {
        $flach = $this->flach($wahl);
        $vkPp = round(array_sum(array_map(fn (array $k) => $k['sales_net'] ?? 0.0, $flach)), 2);
        $ekPp = round(array_sum(array_map(fn (array $k) => $k['ek_portion'] ?? 0.0, $flach)), 4);
        $dbPp = round(array_sum(array_column($flach, 'db')), 2);

        $slots = [];
        $scopes = [];
        $rollenBruch = [];
        $nichtAufloesbar = [];
        foreach ($aufgaben as $si => $a) {
            $gewaehlt = $wahl[$si] ?? [];
            $fehlend = $a['n'] - count($gewaehlt);
            $aufloesbar = ($a['rolle_aufloesbar'] ?? false) === true;
            $fremde = $aufloesbar
                ? array_values(array_filter($gewaehlt, fn (array $k) => (int) ($k['semantik'] ?? 0) !== 1))
                : [];
            if (! $aufloesbar) {
                $nichtAufloesbar[] = (string) $a['slot']->label;
            }
            foreach ($fremde as $k) {
                $rollenBruch[] = [
                    'slot_id' => (int) $a['slot']->id,
                    'slot_label' => (string) $a['slot']->label,
                    'recipe_id' => $k['id'],
                    'name' => $k['name'],
                    'hg_label' => $k['hg_label'],
                ];
            }
            $slots[] = [
                'slot_id' => (int) $a['slot']->id,
                'label' => (string) $a['slot']->label,
                'target_count' => $a['n'],
                // 12·S3b: die Rollen-Sicht des Slots. `rolle_aufloesbar=false` heißt „das Label
                // lässt sich keiner Hauptgruppe zuordnen" — dann ist `hg_fremdlinge` NULL und
                // nicht 0, weil „geprüft und in Ordnung" etwas anderes ist als „nicht prüfbar".
                'rolle_aufloesbar' => $aufloesbar,
                'hg_fremdlinge' => $aufloesbar ? count($fremde) : null,
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
                    'hg_label' => $k['hg_label'] !== '' ? $k['hg_label'] : null,
                    'passt_zum_slot' => $aufloesbar ? ((int) ($k['semantik'] ?? 0) === 1) : null,
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
            // 12·S3b — die Rollen-Ebene, offen ausgewiesen: die Ebene bindet den Slot, aber sie
            // schließt ihn nicht. Bleibt ein Fremdling stehen, ist das die einzige Stelle, an
            // der man es sieht, ohne die Slots durchzugehen — und `ebene_aktiv=false` sagt,
            // dass hier eine LOCKERUNG gerechnet wurde (`erklaere()`), nicht der Normalfall.
            'slot_semantik' => [
                'ebene_aktiv' => $semantikAktiv,
                'fremdlinge' => count($rollenBruch),
                'brueche' => $rollenBruch,
                'nicht_aufloesbar' => $nichtAufloesbar,
                'hinweis' => $nichtAufloesbar === []
                    ? null
                    : 'Für ' . count($nichtAufloesbar) . ' Slot-Label(s) ist keine Speisen-Hauptgruppe im Bestand auflösbar ('
                        . implode(', ', $nichtAufloesbar) . ') — dort ist die Rolle nicht geprüft, nicht erfüllt. '
                        . 'Auflösung über das Label bleibt eine Näherung, bis die Bindung am Slot persistiert ist (12·S3c).',
            ],
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
