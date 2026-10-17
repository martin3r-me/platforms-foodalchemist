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
 *     sonst Gruppen-Klassiker („verträgt Fisch", b ist Fisch)  +    50 000
 *   ★★★ zwischen a und b (Inspire; ★★ zählt nicht, Dominique  +    10 000
 *     15.10.: „2 Sterne ist die Grund-Aroma-Passform")
 *   Rolle: Protein bzw. Nicht-Küche als Zugabe (ROLLE_*)       −    20 000
 *   Stufe der Eigenschaft (2–3)                               ·     1 000
 *   Grundform statt Variante                                  +       500
 *   Verbreitung im Katalog (Anzahl GP-Zuordnungen)            +   bis 499 — Alltagszutat vor Exot
 *     ohne jede GP-Zuordnung ersatzweise Bekanntheit (Anzahl ★★★-Partner, Inspire)
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

    /**
     * Rolle des Lieferanten — Fachregel Dominique 16.10.2026, ohne externe Quelle. Ein Kontrast-Lieferant soll
     * eine ZUGABE sein, keine zweite Hauptkomponente (vorher: Wagyu/Duroc/Foie gras als Fett für Kürbis,
     * Erdbeersaft als Säure für Kartoffel). Rang-Abzug, kein Ausschluss; Inspire-Unterkategorie, gleich oder darunter.
     *   PROTEIN       Abzug nur, wenn der Bedarfsträger selbst KEIN Protein ist (Speck zu Rind, Sardelle zu Lamm bleiben)
     *   NICHT_KUECHE  Abzug bei jedem herzhaften Bedarfsträger, auch Protein — nicht bei SUESS (Erdbeere darf Saft behalten)
     */
    public const ROLLE_PROTEIN = ['Protein/Fleisch', 'Protein/Fisch', 'Protein/Meeresfrüchte', 'Protein/Charcuterie'];

    public const ROLLE_NICHT_KUECHE = ['Getränke/Alkoholfreie Getränke/Säfte', 'Backwaren/Kuchen', 'Dessert', 'Süßwaren'];

    public const ROLLE_SUESS = ['Dessert', 'Süßwaren', 'Backwaren', 'Obst'];

    /** Zwischen Klassiker (≥ 50 000) und ★★★ (10 000): ein Klassiker bleibt vorn, ein ★★★-Partner rutscht unter neutrale Zugaben. */
    public const ROLLE_ABZUG = 20000;

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

        // Gruppen-Klassiker („verträgt Fisch"): schwächer als ein Einzel-Klassiker
        $gruppen = $this->graph->gruppenKlassiker();
        $sub = DB::table('foodalchemist_vocab_pairing_anchors')->pluck('subcategory', 'id')->all();
        $inGruppe = function (int $x, int $y) use ($gruppen, $sub): bool {
            foreach ($gruppen[$x] ?? [] as $g) {
                if (AnkerGraph::inGruppe($sub[$y] ?? null, $g)) {
                    return true;
                }
            }

            return false;
        };

        $verbreitung = DB::table('foodalchemist_gp_anchor_mappings')->whereNull('deleted_at')
            ->selectRaw('anchor_id, COUNT(*) AS n')->groupBy('anchor_id')->pluck('n', 'anchor_id')->all();
        // Ohne GP-Zuordnungen (frischer Server, io 16.10.) wäre die Verbreitung überall 0 und bei Gleichstand
        // entschiede die ID → Exoten vorn (Bintje/Säure: Curuba, Davidson-Pflaume). Ersatz, nur dann:
        // Bekanntheit = Anzahl ★★★-Partner im Inspire-Graph (gängige Zutaten haben viele). Keine neue Regel.
        if ($verbreitung === []) {
            $verbreitung = $this->graph->grad(DB::table('foodalchemist_vocab_pairing_anchors')->pluck('id')->all(), AnkerGraph::HARMONIERT);
        }
        $in = function (?string $pfad, array $liste): bool {
            foreach ($liste as $g) {
                if (AnkerGraph::inGruppe($pfad, $g)) {
                    return true;
                }
            }

            return false;
        };
        $rolleAbzug = function (int $a, int $b) use ($sub, $in): int {
            $traeger = $sub[$a] ?? null;
            if ($in($sub[$b] ?? null, self::ROLLE_PROTEIN) && ! $in($traeger, self::ROLLE_PROTEIN)) {
                return self::ROLLE_ABZUG;
            }
            if ($in($sub[$b] ?? null, self::ROLLE_NICHT_KUECHE) && ! $in($traeger, self::ROLLE_SUESS)) {
                return self::ROLLE_ABZUG;
            }

            return 0;
        };
        $grundform = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('verfahren')
            ->pluck('id')->flip()->all();
        $grundname = DB::table('foodalchemist_vocab_pairing_anchors')
            ->get(['id', 'grundname', 'slug'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => mb_strtolower(trim((string) ($r->grundname ?: $r->slug)))])->all();

        // Spec 84 E-5: Ergebnis-Kanten nicht mehr alle im RAM sammeln (276.750 Zeilen-Arrays, am io-Limit 512 MB), sondern
        // in Stapeln von 1 000 direkt schreiben — in DERSELBEN Transaktion wie das Löschen der alten Kanten, Leser sehen
        // also weiter nie einen halben Stand. Die Transaktion umfasst jetzt auch die Rechenzeit (gemessen ~14 s
        // Graph-Abfragen zusätzlich zu ~70 s Schreiben, die schon bisher in der Transaktion lagen).
        $anzahl = 0;
        $ts = now();
        DB::transaction(function () use ($bedarfe, $lieferanten, $konflikt, $vertraegt, $inGruppe, $verbreitung, $rolleAbzug, $grundform, $grundname, $ts, &$anzahl) {
        DB::table('foodalchemist_anchor_beziehungen')->where('art', Kantenart::Kontrast->value)
            ->where('grundlage', 'bedarf_x_eigenschaft')->delete();
        $stapel = [];
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
                    + (isset($vertraegt[$a][$id]) ? 100000 : ($inGruppe($a, $id) || $inGruppe($id, $a) ? 50000 : 0))
                    + (($harmonie[$id] ?? AnkerGraph::NEUTRAL) >= AnkerGraph::HARMONIERT ? 10000 : 0)
                    + $l['stufe'] * 1000
                    + (isset($grundform[$id]) ? 500 : 0) + min(499, (int) ($verbreitung[$id] ?? 0))
                    - $rolleAbzug($a, $id);
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
                $anzahl++;
                $stapel[] = [
                    'anchor_a_id' => $a, 'anchor_b_id' => $id, 'art' => Kantenart::Kontrast->value,
                    'achse' => $achse->value, 'rang' => $rang, 'grundlage' => 'bedarf_x_eigenschaft',
                    'status' => $geprueft ? WissensStatus::Geprueft->value : WissensStatus::Entwurf->value,
                    'created_at' => $ts, 'updated_at' => $ts,
                ];
                if (count($stapel) >= 1000) {
                    DB::table('foodalchemist_anchor_beziehungen')->insert($stapel);
                    $stapel = [];
                }
            }
        }
        if ($stapel !== []) {
            DB::table('foodalchemist_anchor_beziehungen')->insert($stapel);
        }
        });

        return ['bedarfe' => $bedarfe->count(), 'kanten' => $anzahl];
    }
}
