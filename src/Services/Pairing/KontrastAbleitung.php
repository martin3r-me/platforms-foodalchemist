<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\BedarfDeckung;
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
 * Rang (höher = besser), in dieser Rangfolge (Dominique 15.10.: Eignung vor reiner Stärke — vorher
 * stand beim Kürbis Wagyu als Fett-Partner oben, nur weil es maximal Fett liefert):
 *   Stärke des Bedarfs (muss 2, soll 1)                       · 1 000 000
 *   b steht im „Verträgt" von a oder umgekehrt (Dossier)      +   100 000
 *   Harmonie-Stufe zwischen a und b (1–3, Inspire)            ·    10 000
 *   Stufe der Eigenschaft (2–3)                               ·     1 000
 *   Grundform statt Variante                                  +       500
 *   Verbreitung im Katalog (Anzahl GP-Zuordnungen)            +   bis 499 — Alltagszutat vor Exot
 * Vielfalt: je (a, Achse) höchstens ein Partner je Grundname (sonst Espresso, Filterkaffee, Kona …
 * hintereinander); danach werden nur die besten {@see MAX_JE_BEDARF} gespeichert.
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
        // Zubereitungs-Bedarf („Erdnuss braucht Röstung") hat keinen Partner → keine Kontrast-Kante
        $bedarfe = DB::table('foodalchemist_anchor_bedarfe')->where('status', '!=', $verworfen)
            ->where('deckung', '!=', BedarfDeckung::Zubereitung->value)
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

        // „Verträgt" aus dem Dossier gilt in beide Richtungen (Kürbis verträgt Salbei = Salbei passt zu Kürbis)
        $vertraegt = [];
        foreach (DB::table('foodalchemist_anchor_beziehungen')->where('art', Kantenart::Kombination->value)
            ->where('status', '!=', $verworfen)->get(['anchor_a_id', 'anchor_b_id']) as $k) {
            $vertraegt[(int) $k->anchor_a_id][(int) $k->anchor_b_id] = true;
            $vertraegt[(int) $k->anchor_b_id][(int) $k->anchor_a_id] = true;
        }

        $verbreitung = DB::table('foodalchemist_gp_anchor_mappings')->whereNull('deleted_at')
            ->selectRaw('anchor_id, COUNT(*) AS n')->groupBy('anchor_id')->pluck('n', 'anchor_id')->all();
        $grundform = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('verfahren')
            ->pluck('id')->flip()->all();
        $grundname = DB::table('foodalchemist_vocab_pairing_anchors')
            ->get(['id', 'grundname', 'slug'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => mb_strtolower(trim((string) ($r->grundname ?: $r->slug)))])->all();

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
                $rang = ($b->staerke === 'muss' ? 2 : 1) * 1000000
                    + (isset($vertraegt[$a][$id]) ? 100000 : 0)
                    + ($harmonie[$id] ?? AnkerGraph::NEUTRAL) * 10000
                    + $l['stufe'] * 1000
                    + (isset($grundform[$id]) ? 500 : 0) + min(499, (int) ($verbreitung[$id] ?? 0));
                $rangliste[] = [$id, $rang, $l['geprueft'] && $b->status === WissensStatus::Geprueft->value];
            }
            usort($rangliste, fn ($x, $y) => [$y[1], $x[0]] <=> [$x[1], $y[0]]);
            $gesehen = [];
            $rangliste = array_values(array_filter($rangliste, function ($r) use (&$gesehen, $grundname) {
                $g = $grundname[$r[0]] ?? (string) $r[0];
                if (isset($gesehen[$g])) {
                    return false;
                }
                $gesehen[$g] = true;

                return true;
            }));
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
