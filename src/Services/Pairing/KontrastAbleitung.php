<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Enums\WissensStatus;

/**
 * Spec 60 · P4: Kontrast-Kanten ableiten — Bedarf × Eigenschaft, nie von Hand.
 *
 * a hat den Bedarf „Achse X" (muss/soll), b liefert X mit Stufe ≥ 2, und zwischen a und b
 * steht kein Konflikt → Kante a→b (art=kontrast, achse=X). Ein Harmonie-Filter greift bewusst
 * NICHT: Kontrast hängt nicht an geteilten Aromen (Kürbis + Reisessig ist aromatisch Stufe 1,
 * aber ein klassischer Kontrast).
 *
 * Rang (höher = besser), in dieser Rangfolge:
 *   Stärke des Bedarfs (muss 2, soll 1)            · 10 000
 *   Stufe der Eigenschaft (2–3)                     ·  1 000
 *   Harmonie-Stufe zwischen a und b (1–3)           ·    100  — wer zusätzlich harmoniert, gewinnt
 *   Grundform statt Variante                        +     50
 *   Verbreitung im Katalog (Anzahl GP-Zuordnungen)  + bis 49 — Alltagszutat vor Exot
 * Je (a, Achse) werden nur die besten {@see MAX_JE_BEDARF} gespeichert.
 *
 * Verworfene Bedarfe/Eigenschaften zählen nicht. Status der Kante: geprüft nur, wenn Bedarf UND
 * Eigenschaft geprüft sind; sonst Entwurf. Der Lauf ersetzt alle abgeleiteten Kanten (die
 * Ableitung ist deterministisch und hat keine Handarbeit, die verloren gehen könnte).
 */
final class KontrastAbleitung
{
    public const MAX_JE_BEDARF = 50;

    public function __construct(private readonly AnkerGraph $graph) {}

    /** @return array{bedarfe: int, kanten: int} */
    public function baue(): array
    {
        $verworfen = WissensStatus::Verworfen->value;
        $bedarfe = DB::table('foodalchemist_anchor_bedarfe')->where('status', '!=', $verworfen)
            ->get(['anchor_id', 'achse', 'staerke', 'status']);
        $lieferanten = [];
        foreach (DB::table('foodalchemist_anchor_eigenschaften')->where('status', '!=', $verworfen)
            ->where('stufe', '>=', 2)->get(['anchor_id', 'achse', 'stufe', 'status']) as $e) {
            $id = (int) $e->anchor_id;
            // je Anker+Achse die stärkste Angabe (dossier oder naehrwert)
            $alt = $lieferanten[$e->achse][$id] ?? null;
            if ($alt === null || (int) $e->stufe > $alt['stufe']) {
                $lieferanten[$e->achse][$id] = ['stufe' => (int) $e->stufe, 'geprueft' => $e->status === WissensStatus::Geprueft->value];
            }
        }
        $konflikt = [];
        foreach (DB::table('foodalchemist_anchor_beziehungen')->where('art', Kantenart::Konflikt->value)
            ->where('status', '!=', $verworfen)->get(['anchor_a_id', 'anchor_b_id']) as $k) {
            $konflikt[(int) $k->anchor_a_id][(int) $k->anchor_b_id] = true;
            $konflikt[(int) $k->anchor_b_id][(int) $k->anchor_a_id] = true;
        }

        $verbreitung = DB::table('foodalchemist_gp_anchor_mappings')->whereNull('deleted_at')
            ->selectRaw('anchor_id, COUNT(*) AS n')->groupBy('anchor_id')->pluck('n', 'anchor_id')->all();
        $grundform = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('verfahren')
            ->pluck('id')->flip()->all();

        $zeilen = [];
        $ts = now();
        foreach ($bedarfe as $b) {
            $achse = Achse::tryFrom((string) $b->achse);
            if ($achse === null || ! $achse->lieferbar()) {
                continue;
            }
            $a = (int) $b->anchor_id;
            $kandidaten = $lieferanten[$achse->value] ?? [];
            unset($kandidaten[$a]);
            if ($kandidaten === []) {
                continue;
            }
            $harmonie = $this->graph->kanten([$a], array_keys($kandidaten))->pluck('stufe', 'zu')->all();
            $rangliste = [];
            foreach ($kandidaten as $id => $l) {
                if (isset($konflikt[$a][$id])) {
                    continue;
                }
                $rang = ($b->staerke === 'muss' ? 2 : 1) * 10000 + $l['stufe'] * 1000
                    + ($harmonie[$id] ?? AnkerGraph::NEUTRAL) * 100
                    + (isset($grundform[$id]) ? 50 : 0) + min(49, (int) ($verbreitung[$id] ?? 0));
                $rangliste[] = [$id, $rang, $l['geprueft'] && $b->status === WissensStatus::Geprueft->value];
            }
            usort($rangliste, fn ($x, $y) => [$y[1], $x[0]] <=> [$x[1], $y[0]]);
            foreach (array_slice($rangliste, 0, self::MAX_JE_BEDARF) as [$id, $rang, $geprueft]) {
                $zeilen[] = [
                    'anchor_a_id' => $a, 'anchor_b_id' => $id, 'art' => Kantenart::Kontrast->value,
                    'achse' => $achse->value, 'rang' => $rang, 'grundlage' => 'bedarf_x_eigenschaft',
                    'status' => $geprueft ? WissensStatus::Geprueft->value : WissensStatus::Entwurf->value,
                    'created_at' => $ts, 'updated_at' => $ts,
                ];
            }
        }

        DB::transaction(function () use ($zeilen) {
            DB::table('foodalchemist_anchor_beziehungen')->where('art', Kantenart::Kontrast->value)
                ->where('grundlage', 'bedarf_x_eigenschaft')->delete();
            foreach (array_chunk($zeilen, 1000) as $chunk) {
                DB::table('foodalchemist_anchor_beziehungen')->insert($chunk);
            }
        });

        return ['bedarfe' => $bedarfe->count(), 'kanten' => count($zeilen)];
    }
}
