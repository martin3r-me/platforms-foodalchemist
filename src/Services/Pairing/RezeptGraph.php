<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Platform\FoodAlchemist\Enums\Kantenart;

/**
 * Spec 60 · P6: Beziehungen zwischen zwei Bestandteilen auf Küchen-Ebene (Basisrezept bzw.
 * einzeln eingesetztes Grundprodukt). Die Anker liegen im Hintergrund: jeder Bestandteil ist ein
 * Profil {@see RezeptProfil} aus Kern-Ankern mit Anteil.
 *
 *  Harmonie   Σ p_i · q_j · h(a_i, b_j) mit h = 1 bei Stufe 3 oder gleichem Anker, sonst 0.
 *             Ergebnis 0–1: welcher Teil der Aromamasse beider Seiten gemessen harmoniert.
 *             Stufe 2 zählt nicht (Entscheidung Dominique) und wird nur als „passt" mitgeführt.
 *  Spannung   ein offener Bedarf der einen Seite, den die andere mit Stufe ≥ 2 liefert.
 *  Konflikt   „Zerstört" zwischen Kern-Ankern (Anteil ≥ 10 %) beider Seiten.
 *  Kombination „Verträgt" (Dossier) zwischen Kern-Ankern beider Seiten.
 */
final class RezeptGraph
{
    /** Ab diesem Anteil zählt ein Anker als Kern (Konflikt/Kombination). */
    public const KERN = 10.0;

    /** @var array<int, true>|null  vorgeladene Seiten ({@see vorladen}); null = jede Frage geht an die DB */
    private ?array $vorA = null;

    /** @var array<int, true> */
    private array $vorB = [];

    /** @var array<int, array<int, int>> */
    private array $vorStufe = [];

    /** @var array<string, list<object>>  art => Beziehungen zwischen den vorgeladenen Seiten */
    private array $vorWissen = [];

    public function __construct(private readonly AnkerGraph $graph) {}

    /**
     * Massen-Vergleich (Vorschläge über den ganzen Basisrezept-Bestand): Harmonie-Kanten und
     * Anker-Wissen zwischen zwei Anker-Mengen EINMAL laden. Danach beantworten harmonie(),
     * konflikte() und kombinationen() jedes Paar aus diesen Mengen ohne Datenbank-Abfrage —
     * gemessen 7,2 s → unter 1 s bei 3.600 Rezepten. {@see vergiss} hebt das wieder auf.
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    public function vorladen(array $a, array $b): void
    {
        $this->vorA = array_fill_keys(array_map('intval', $a), true);
        $this->vorB = array_fill_keys(array_map('intval', $b), true);
        $this->vorStufe = [];
        foreach ($this->graph->kanten(array_keys($this->vorA), array_keys($this->vorB)) as $k) {
            $this->vorStufe[$k->von][$k->zu] = $k->stufe;
            $this->vorStufe[$k->zu][$k->von] = $k->stufe;
        }
        $this->vorWissen = [];
        foreach ($this->graph->beziehungen(array_keys($this->vorA), null, array_keys($this->vorB))
            ->merge($this->graph->beziehungen(array_keys($this->vorB), null, array_keys($this->vorA))) as $k) {
            $this->vorWissen[$k->art][] = $k;
        }
    }

    public function vergiss(): void
    {
        [$this->vorA, $this->vorB, $this->vorStufe, $this->vorWissen] = [null, [], [], []];
    }

    /** Liegen alle Anker-Paare zwischen $p und $q in den vorgeladenen Mengen? */
    private function vorgeladen(array $pa, array $qa): bool
    {
        if ($this->vorA === null) {
            return false;
        }
        $in = fn (array $ids, array $menge) => array_diff_key($ids, $menge) === [];

        return ($in($pa, $this->vorA) && $in($qa, $this->vorB)) || ($in($pa, $this->vorB) && $in($qa, $this->vorA));
    }

    /**
     * @param  array{anker: list<array{anchor_id: int, anteil: float}>}  $p
     * @param  array{anker: list<array{anchor_id: int, anteil: float}>}  $q
     * @return array{wert: float, passt: float, paare: list<array{a: int, b: int, stufe: int, beitrag: float}>}
     */
    public function harmonie(array $p, array $q): array
    {
        $pa = $this->anteile($p);
        $qa = $this->anteile($q);
        if ($pa === [] || $qa === []) {
            return ['wert' => 0.0, 'passt' => 0.0, 'paare' => []];
        }
        $stufen = [];
        if ($this->vorgeladen($pa, $qa)) {
            $stufen = $this->vorStufe;
        } else {
            foreach ($this->graph->kanten(array_keys($pa), array_keys($qa)) as $k) {
                $stufen[$k->von][$k->zu] = $k->stufe;
            }
        }
        $wert = 0.0;
        $passt = 0.0;
        $paare = [];
        foreach ($pa as $a => $x) {
            foreach ($qa as $b => $y) {
                $stufe = $a === $b ? AnkerGraph::HARMONIERT : ($stufen[$a][$b] ?? AnkerGraph::NEUTRAL);
                $beitrag = ($x / 100) * ($y / 100);
                if ($stufe === AnkerGraph::HARMONIERT) {
                    $wert += $beitrag;
                    $paare[] = ['a' => $a, 'b' => $b, 'stufe' => $stufe, 'beitrag' => round($beitrag, 4)];
                } elseif ($stufe === AnkerGraph::PASST) {
                    $passt += $beitrag;
                }
            }
        }
        usort($paare, fn ($m, $n) => $n['beitrag'] <=> $m['beitrag']);

        return ['wert' => round($wert, 4), 'passt' => round($passt, 4), 'paare' => array_slice($paare, 0, 3)];
    }

    /**
     * Bedarfe von $p, die $q deckt (gerichtet).
     *
     * @return list<array{achse: string, staerke: string, von: int, stufe: float, quelle: string}>
     */
    public function spannung(array $p, array $q): array
    {
        $out = [];
        foreach ($p['offene_bedarfe'] ?? [] as $b) {
            $e = $q['eigenschaften'][$b['achse']] ?? null;
            if ($e !== null && (float) $e['stufe'] >= 2) {
                $out[] = $b + ['stufe' => (float) $e['stufe'], 'quelle' => (string) $e['quelle']];
            }
        }

        return $out;
    }

    /** @return list<object{von: int, zu: int, art: string, status: string}> */
    public function konflikte(array $p, array $q): array
    {
        return $this->wissen($p, $q, Kantenart::Konflikt);
    }

    /** @return list<object{von: int, zu: int, art: string, status: string}> */
    public function kombinationen(array $p, array $q): array
    {
        return $this->wissen($p, $q, Kantenart::Kombination);
    }

    /** @return list<object> */
    private function wissen(array $p, array $q, Kantenart $art): array
    {
        $pk = array_keys(array_filter($this->anteile($p), fn ($a) => $a >= self::KERN));
        $qk = array_keys(array_filter($this->anteile($q), fn ($a) => $a >= self::KERN));
        if ($pk === [] || $qk === []) {
            return [];
        }
        if ($this->vorgeladen(array_flip($pk), array_flip($qk))) {
            [$pm, $qm] = [array_flip($pk), array_flip($qk)];

            return collect($this->vorWissen[$art->value] ?? [])
                ->filter(fn ($k) => (isset($pm[$k->von]) && isset($qm[$k->zu])) || (isset($qm[$k->von]) && isset($pm[$k->zu])))
                ->unique(fn ($k) => min($k->von, $k->zu).':'.max($k->von, $k->zu))->values()->all();
        }

        return $this->graph->beziehungen($pk, $art, $qk)
            ->merge($this->graph->beziehungen($qk, $art, $pk))
            ->unique(fn ($k) => min($k->von, $k->zu).':'.max($k->von, $k->zu))->values()->all();
    }

    /** @return array<int, float> */
    private function anteile(array $p): array
    {
        $out = [];
        foreach ($p['anker'] ?? [] as $a) {
            $out[(int) $a['anchor_id']] = (float) $a['anteil'];
        }

        return $out;
    }
}
