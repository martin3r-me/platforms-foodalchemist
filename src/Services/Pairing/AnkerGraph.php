<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Enums\WissensStatus;

/**
 * Spec 60 · P2: die EINZIGE Lesestelle für die gemessene Harmonie zwischen Aroma-Ankern.
 *
 * Quelle ist Foodpairing Inspire. Die Matrix ist vollständig gemessen: jedes Anker-Paar hat eine
 * Stufe. Gespeichert werden nur Stufe 3 (best match) und Stufe 2 (good match) in
 * `foodalchemist_anchor_harmonie`, jedes Paar in BEIDE Richtungen (Partner-Abfragen bleiben reine
 * Index-Zugriffe). Ein Paar ohne Zeile hat Stufe 1 („kein nennenswerter Bezug") — gemessen,
 * keine Datenlücke.
 *
 * Wer eine Stufe braucht, fragt hier. Kein anderer Code liest die Tabelle direkt. Dasselbe gilt
 * ab P4 für die Wissens-Beziehungen ({@see beziehungen}).
 */
final class AnkerGraph
{
    public const TABELLE = 'foodalchemist_anchor_harmonie';

    /** Spec 60 · P4: Kontrast/Kombination/Konflikt (gerichtet, mit Status). */
    public const BEZIEHUNGEN = 'foodalchemist_anchor_beziehungen';

    /** Prüfliste + Hinweise aus den Dossiers ({@see hinweise}). */
    public const OFFEN = 'foodalchemist_anchor_wissen_offen';

    public const HINWEIS = 'hinweis';

    /** Präfix strukturierter Gruppen-Ziele in anchor_wissen_offen.name (Hinweis, Gruppen-Klassiker). */
    public const GRUPPE = 'gruppe:';

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
     * Wissens-Beziehungen (Spec 60 · P4) von einer Anker-Menge aus: Kontrast (abgeleitet),
     * Kombination und Konflikt (aus Dossiers). Verworfene zählen nie; stärkster Rang zuerst.
     *
     * @param  array<int, int|string>  $von
     * @param  array<int, int|string>|null  $zu
     * @return Collection<int, object{von: int, zu: int, art: string, achse: string, rang: int, status: string, grundlage: string}>
     */
    public function beziehungen(array $von, ?Kantenart $art = null, ?array $zu = null, bool $nurGeprueft = false): Collection
    {
        $von = $this->ids($von);
        if ($von === [] || ($zu !== null && $this->ids($zu) === [])) {
            return collect();
        }

        return DB::table(self::BEZIEHUNGEN)
            ->whereIn('anchor_a_id', $von)
            ->when($zu !== null, fn ($q) => $q->whereIn('anchor_b_id', $this->ids($zu)))
            ->when($art !== null, fn ($q) => $q->where('art', $art->value))
            ->where('status', '!=', WissensStatus::Verworfen->value)
            ->when($nurGeprueft, fn ($q) => $q->where('status', WissensStatus::Geprueft->value))
            ->orderByDesc('rang')->orderBy('anchor_b_id')
            ->get(['anchor_a_id', 'anchor_b_id', 'art', 'achse', 'rang', 'status', 'grundlage'])
            ->map(fn ($r) => (object) ['von' => (int) $r->anchor_a_id, 'zu' => (int) $r->anchor_b_id, 'art' => $r->art,
                'achse' => (string) $r->achse, 'rang' => (int) $r->rang, 'status' => $r->status, 'grundlage' => $r->grundlage]);
    }

    /**
     * Dossier-Hinweise (Spec 60 · P4, Teil 2): Warnungen aus „Zerstört", die keine Sperre sind —
     * Dosis („ein Spritzer hebt, zu viel überdeckt"), Technik, ganze Gruppen. Das Ziel steht
     * strukturiert in `anchor_wissen_offen.name`: `anker:<slug>` oder `gruppe:<Inspire-Unterkategorie>`.
     * Getroffen wird nur über den Anker bzw. dessen Unterkategorie (gleich oder darunter), nie über
     * Freitext — Zitronengras ist nicht Zitrone. Verworfene zählen nie.
     *
     * @param  array<int, int|string>  $von
     * @param  array<int, int|string>  $zu
     * @return list<object{von: int, zu: int, begriff: string, grund: ?string, status: string}>
     */
    public function hinweise(array $von, array $zu): array
    {
        [$von, $zu] = [$this->ids($von), $this->ids($zu)];
        if ($von === [] || $zu === []) {
            return [];
        }
        $zeilen = DB::table(self::OFFEN)->whereIn('anchor_id', $von)->where('art', self::HINWEIS)
            ->where('status', '!=', WissensStatus::Verworfen->value)->orderBy('id')
            ->get(['anchor_id', 'name', 'kontext', 'grund', 'status']);
        if ($zeilen->isEmpty()) {
            return [];
        }
        $ziele = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $zu)->get(['id', 'slug', 'subcategory']);
        $out = [];
        foreach ($zeilen as $r) {
            [$typ, $wert] = array_pad(explode(':', (string) $r->name, 2), 2, '');
            foreach ($ziele as $z) {
                $treffer = match ($typ) {
                    'anker' => $z->slug === $wert,
                    'gruppe' => self::inGruppe($z->subcategory, $wert),
                    default => false,
                };
                if ($treffer && (int) $z->id !== (int) $r->anchor_id) {
                    $out[] = (object) ['von' => (int) $r->anchor_id, 'zu' => (int) $z->id,
                        'begriff' => (string) ($r->kontext ?? $wert), 'grund' => $r->grund, 'status' => $r->status];
                }
            }
        }

        return $out;
    }

    /** Liegt eine Inspire-Unterkategorie in der Gruppe (gleich oder darunter, nie halber Pfad)? */
    public static function inGruppe(?string $subcategory, string $gruppe): bool
    {
        return $gruppe !== '' && $subcategory !== null
            && ($subcategory === $gruppe || str_starts_with($subcategory, $gruppe.'/'));
    }

    /**
     * Gruppen-Klassiker (Dominique 15.10.): „verträgt Fisch" steht als anchor_wissen_offen art=kombination,
     * name=gruppe:<Unterkategorie>. Je Anker die Gruppen; verworfene zählen nie. Ohne $ids: alle.
     *
     * @param  list<int>|null  $ids
     * @return array<int, list<string>>
     */
    public function gruppenKlassiker(?array $ids = null): array
    {
        $out = [];
        foreach (DB::table(self::OFFEN)->where('art', Kantenart::Kombination->value)->where('name', 'like', self::GRUPPE.'%')
            ->where('status', '!=', WissensStatus::Verworfen->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('anchor_id', $this->ids($ids)))
            ->get(['anchor_id', 'name']) as $r) {
            $out[(int) $r->anchor_id][] = substr((string) $r->name, strlen(self::GRUPPE));
        }

        return $out;
    }

    /**
     * Paare (a aus $von, b aus $zu), zwischen denen ein Gruppen-Klassiker steht — in beide Richtungen
     * (a verträgt b's Gruppe ODER b verträgt a's Gruppe).
     *
     * @param  array<int, int|string>  $von
     * @param  array<int, int|string>  $zu
     * @return array<int, true>  id aus $zu (bzw. $von) => true, je beteiligtem Anker
     */
    public function gruppenKlassikerZwischen(array $von, array $zu): array
    {
        [$von, $zu] = [$this->ids($von), $this->ids($zu)];
        if ($von === [] || $zu === []) {
            return [];
        }
        $gruppen = $this->gruppenKlassiker(array_merge($von, $zu));
        if ($gruppen === []) {
            return [];
        }
        $sub = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_merge($von, $zu))->pluck('subcategory', 'id')->all();
        $out = [];
        foreach ($von as $a) {
            foreach ($zu as $b) {
                if ($a === $b) {
                    continue;
                }
                foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                    foreach ($gruppen[$x] ?? [] as $g) {
                        if (self::inGruppe($sub[$y] ?? null, $g)) {
                            $out[$a] = $out[$b] = true;
                            continue 3;
                        }
                    }
                }
            }
        }

        return $out;
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
