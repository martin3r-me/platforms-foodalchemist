<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec 60 · P2: die EINZIGE Lesestelle für die gemessene Harmonie zwischen Aroma-Ankern.
 *
 * Quelle ist Foodpairing Inspire. Die Matrix ist vollständig gemessen: jedes Anker-Paar hat eine
 * Stufe. Gespeichert werden nur Stufe 3 (best match) und Stufe 2 (good match) in
 * `foodalchemist_anchor_harmonie`, jedes Paar in BEIDE Richtungen (Partner-Abfragen bleiben reine
 * Index-Zugriffe). Ein Paar ohne Zeile hat Stufe 1 („kein nennenswerter Bezug") — gemessen,
 * keine Datenlücke.
 *
 * Wer eine Stufe braucht, fragt hier. Kein anderer Code liest die Tabelle direkt.
 */
final class AnkerGraph
{
    public const TABELLE = 'foodalchemist_anchor_harmonie';

    /** Stufen. */
    public const HARMONIERT = 3;

    public const PASST = 2;

    public const NEUTRAL = 1;

    /**
     * Übergangs-Gewichte für die alten Kohäsions-Leser (Spec 58/60: zählen soll nur Stufe 3). Sie
     * entfallen, wenn die Leser in P6 auf die Kombinationslogik umgestellt sind.
     */
    public const GEWICHT = [self::HARMONIERT => 1.0, self::PASST => 0.9];

    /** Stufe eines Paars: 3, 2 oder 1. Ein Anker mit sich selbst gilt als 3. */
    public function stufe(int $a, int $b): int
    {
        if ($a === $b) {
            return self::HARMONIERT;
        }
        $s = DB::table(self::TABELLE)->where('anchor_a_id', $a)->where('anchor_b_id', $b)->value('stufe');

        return $s !== null ? (int) $s : self::NEUTRAL;
    }

    /**
     * Stufen innerhalb eines Anker-Sets, ein Query. Liefert nur Paare mit Stufe ≥ $min, in beiden
     * Richtungen: [a][b] = stufe. Fehlende Paare sind Stufe 1.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, array<int, int>>
     */
    public function stufen(array $ids, int $min = self::PASST): array
    {
        $ids = $this->ids($ids);
        if (count($ids) < 2) {
            return [];
        }
        $out = [];
        foreach (DB::table(self::TABELLE)->whereIn('anchor_a_id', $ids)->whereIn('anchor_b_id', $ids)
            ->where('stufe', '>=', $min)->get(['anchor_a_id', 'anchor_b_id', 'stufe']) as $r) {
            $out[(int) $r->anchor_a_id][(int) $r->anchor_b_id] = (int) $r->stufe;
        }

        return $out;
    }

    /**
     * Gerichtete Kanten von einer Anker-Menge aus (optional nur zu einer Ziel-Menge oder ohne eine
     * Ausschluss-Menge). Jede Zeile: von, zu, stufe.
     *
     * @param  array<int, int|string>  $von
     * @param  array<int, int|string>|null  $zu
     * @param  array<int, int|string>  $ohne  Ziel-Anker, die ausgeschlossen werden
     * @return Collection<int, object{von: int, zu: int, stufe: int}>
     */
    public function kanten(array $von, ?array $zu = null, int $min = self::PASST, array $ohne = []): Collection
    {
        $von = $this->ids($von);
        if ($von === [] || ($zu !== null && $this->ids($zu) === [])) {
            return collect();
        }

        return DB::table(self::TABELLE)
            ->whereIn('anchor_a_id', $von)
            ->when($zu !== null, fn ($q) => $q->whereIn('anchor_b_id', $this->ids($zu)))
            ->when($ohne !== [], fn ($q) => $q->whereNotIn('anchor_b_id', $this->ids($ohne)))
            ->where('stufe', '>=', $min)
            ->get(['anchor_a_id', 'anchor_b_id', 'stufe'])
            ->map(fn ($r) => (object) ['von' => (int) $r->anchor_a_id, 'zu' => (int) $r->anchor_b_id, 'stufe' => (int) $r->stufe]);
    }

    /**
     * Partner eines Ankers, stärkste zuerst, dann alphabetisch nach Slug. Mit Anker-Stammdaten.
     *
     * @return Collection<int, object{id: int, slug: string, display_de: ?string, stufe: int}>
     */
    public function partner(int $anker, int $min = self::PASST, ?int $limit = null): Collection
    {
        return DB::table(self::TABELLE.' AS h')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'h.anchor_b_id')
            ->where('h.anchor_a_id', $anker)->where('h.stufe', '>=', $min)
            ->whereNull('a.deleted_at')
            ->orderByDesc('h.stufe')->orderBy('a.slug')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get(['a.id', 'a.slug', 'a.display_de', 'h.stufe'])
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'slug' => $r->slug, 'display_de' => $r->display_de, 'stufe' => (int) $r->stufe]);
    }

    /**
     * Anzahl Partner je Anker (Grad im Graphen) ab Stufe $min.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public function grad(array $ids, int $min = self::PASST): array
    {
        $ids = $this->ids($ids);
        if ($ids === []) {
            return [];
        }

        return DB::table(self::TABELLE)->whereIn('anchor_a_id', $ids)->where('stufe', '>=', $min)
            ->selectRaw('anchor_a_id, COUNT(*) AS n')->groupBy('anchor_a_id')
            ->pluck('n', 'anchor_a_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Schreibt ein Paar in beide Richtungen (Import, Tests). Stufe 1 löscht das Paar, denn Stufe 1
     * wird nicht gespeichert.
     */
    public function setze(int $a, int $b, int $stufe): void
    {
        if ($a === $b) {
            return;
        }
        if ($stufe < self::PASST) {
            DB::table(self::TABELLE)->where(fn ($q) => $q->where('anchor_a_id', $a)->where('anchor_b_id', $b))
                ->orWhere(fn ($q) => $q->where('anchor_a_id', $b)->where('anchor_b_id', $a))->delete();

            return;
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table(self::TABELLE)->updateOrInsert(['anchor_a_id' => $x, 'anchor_b_id' => $y], ['stufe' => min(3, $stufe)]);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($i) => $i !== null && $i !== ''))));
    }
}
