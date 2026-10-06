<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 58 · Paket 2 — Harmonie und Kontrast eines Gerichts (Dominique 2026-10-06).
 *
 * Zwei getrennte Logiken, nebeneinander:
 *  - HARMONIE = teilen sich die Bestandteile Aromen? Einzige Quelle: die Foodpairing-Sterne
 *    zwischen ihren Ankern. 3★ = „harmonieren sehr gut" (echtes Food Pairing, zählt),
 *    2★ = „passt" (Rauschen, wird gezeigt, zählt nicht). Kein Direktpaar → Brücke, wenn ein
 *    DRITTER Bestandteil des Gerichts mit beiden 3★ harmoniert. Keine Molekül-Erklärung
 *    (nicht für alle Anker vorhanden — eine halbe Erklärung ist schlechter als keine).
 *  - KONTRAST = Spannung durch Gegensätze im GESCHMACK (Säure–Fett, süß–salzig …) und in der
 *    TEXTUR (knusprig–weich). Profil je Bestandteil aus {@see SensorikService}: Basisrezept =
 *    gegartes Profil, Grundprodukt = roh + Zubereitungs-Delta. salzig/süß/fettig an LA-Nährwerten
 *    geerdet = „belegt", sonst „geschätzt".
 *
 * Gewicht eines Bestandteils = Rolle × Mengenanteil ({@see PairingService::ROLLEN_GEWICHT}).
 * Die Anker-Auflösung ist DIESELBE wie für Netz und Kohäsion (eine Wahrheit).
 */
class PairingAnalyseService
{
    /** Gegensatz-Paare des Geschmacks — Quelle: PairingService::GESCHMACK_GEGENSATZ (Buch S. 36 + Küchen-Grundlagen). */
    private const GEGENSATZ = [
        ['fettig', 'sauer'], ['fettig', 'scharf'], ['suess', 'bitter'],
        ['suess', 'scharf'], ['suess', 'salzig'], ['suess', 'sauer'], ['umami', 'sauer'],
    ];

    /** Ab dieser Intensität trägt ein Bestandteil eine Geschmacksseite deutlich. */
    private const DEUTLICH = 0.5;

    /** Gemessene Achsen (LA-Nährwerte) — der Rest ist geschätzt. */
    private const MESSBAR = ['salzig', 'suess', 'fettig'];

    private const KNUSPRIG = ['knusprig', 'koernig', 'schnittfest'];

    private const WEICH = ['cremig', 'weich', 'mousse', 'pastoes', 'fluessig', 'gel', 'pueree', 'schaumig', 'saftig'];

    private const ACHSE_WORT = [
        'suess' => 'Süße', 'salzig' => 'Salz', 'sauer' => 'Säure', 'bitter' => 'Bitterkeit',
        'umami' => 'Umami', 'fettig' => 'Fett', 'scharf' => 'Schärfe',
    ];

    private const ROLLE_WORT = [
        'aroma_treiber' => 'Aromaträger', 'komponente' => 'Komponente', 'beilage' => 'Beilage', 'garnitur' => 'Garnitur',
    ];

    public function __construct(
        private PairingService $pairing,
        private SensorikService $sensorik,
    ) {}

    /**
     * Analyse eines Gerichts/Rezepts aus seinen direkten Zutatenzeilen.
     *
     * @return array{komponenten: list<array>, harmonie: array, kontrast: list<array>, luecken: list<array>, zusammenfassung: string}
     */
    public function analyseRezept(int $recipeId): array
    {
        $recipe = FoodAlchemistRecipe::find($recipeId);
        if ($recipe === null) {
            return $this->leer('Rezept nicht gefunden.');
        }

        $zeilen = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->get(['ri.gp_id', 'ri.referenced_recipe_id', 'ri.raw_text', 'ri.quantity', 'ri.quantity_max', 'ri.role',
                'ri.is_optional', 'u.default_in_g', 'u.slug AS unit_slug',
                DB::raw('COALESCE(sr.name, g.name, ri.raw_text) AS name')]);

        // Dieselbe Auflösung wie Netz + Kohäsion: die ersten N Zeilen entsprechen den Zutatenzeilen
        // (gleiche Positions-Sortierung), danach folgt nur noch der Eigen-Zustands-Block.
        $aufgeloest = $this->pairing->resolveRecipeAnchors($recipe);

        $komponenten = [];
        foreach ($zeilen->values() as $i => $z) {
            if ($z->is_optional) {
                continue;
            }
            $anker = $aufgeloest[$i] ?? ['kern' => null, 'via' => 'unresolved', 'prozess' => []];
            $profil = $z->referenced_recipe_id !== null
                ? $this->sensorik->fuerRezept((int) $z->referenced_recipe_id)
                : ($z->gp_id !== null ? $this->sensorik->fuerGp((int) $z->gp_id) : ['leer' => true]);
            $zubereitung = $this->zubereitung((string) $z->name);
            if ($z->gp_id !== null && $zubereitung !== null && ! ($profil['leer'] ?? false)) {
                $profil['geschmack'] = $this->mitDelta($profil['geschmack'], $zubereitung['delta']);
            }

            $komponenten[] = [
                'label' => (string) $z->name,
                'kurz' => $this->kurzname((string) $z->name, $z->referenced_recipe_id !== null),
                'rolle' => $z->role,
                'rolle_label' => self::ROLLE_WORT[$z->role ?? ''] ?? null,
                'roh_gewicht' => $this->gramm($z) * (PairingService::ROLLEN_GEWICHT[$z->role ?? ''] ?? 1.0),
                'anker_ids' => array_values(array_unique(array_map('intval', array_filter(
                    array_merge($anker['kern'] !== null ? [$anker['kern']] : [], $anker['prozess'] ?? []))))),
                'anker_id' => $anker['kern'] !== null ? (int) $anker['kern'] : null,
                'via' => $anker['via'],
                'geschmack' => ($profil['leer'] ?? false) ? null : $profil['geschmack'],
                'gemessen' => array_keys($profil['erdung'] ?? []),
                'profil_quelle' => $profil['source'] ?? null,
                'textur' => array_column($profil['textur'] ?? [], 'slug'),
                'zubereitung' => $zubereitung['label'] ?? null,
            ];
        }

        return $this->analyse($komponenten);
    }

    /**
     * Analyse einer freien Anker-Menge (Composer): nur Harmonie — ohne Rezept gibt es weder
     * Geschmacksprofil noch Rolle/Menge.
     *
     * @param  list<int>  $ankerIds
     */
    public function analyseAnker(array $ankerIds): array
    {
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $ankerIds)->pluck('display_de', 'id');
        $komponenten = [];
        foreach (array_values(array_unique(array_map('intval', $ankerIds))) as $id) {
            $komponenten[] = [
                'label' => (string) ($namen[$id] ?? "#{$id}"), 'kurz' => (string) ($namen[$id] ?? "#{$id}"), 'rolle' => null, 'rolle_label' => null,
                'roh_gewicht' => 1.0, 'anker_ids' => [$id], 'anker_id' => $id, 'via' => 'composer',
                'geschmack' => null, 'gemessen' => [], 'profil_quelle' => null, 'textur' => [], 'zubereitung' => null,
            ];
        }

        return $this->analyse($komponenten);
    }

    // ── Kern ──────────────────────────────────────────────────────────────

    private function analyse(array $komponenten): array
    {
        if ($komponenten === []) {
            return $this->leer('Keine Bestandteile.');
        }
        $summe = array_sum(array_column($komponenten, 'roh_gewicht')) ?: 1.0;
        foreach ($komponenten as &$k) {
            $k['gewicht'] = round($k['roh_gewicht'] / $summe, 3);
            unset($k['roh_gewicht']);
        }
        unset($k);

        $harmonie = $this->harmonie($komponenten);
        $kontrast = $this->kontrast($komponenten);
        $luecken = $this->luecken($komponenten);

        return [
            'komponenten' => $komponenten,
            'harmonie' => $harmonie,
            'kontrast' => $kontrast,
            'luecken' => $luecken,
            'zusammenfassung' => $this->zusammenfassung($harmonie, $kontrast, $luecken),
        ];
    }

    private function harmonie(array $k): array
    {
        $alle = [];
        foreach ($k as $c) {
            foreach ($c['anker_ids'] as $a) {
                $alle[$a] = true;
            }
        }
        $stufe = $this->stufenJePaar(array_keys($alle));
        $paarStufe = function (array $x, array $y) use ($stufe): int {
            $best = 0;
            foreach ($x['anker_ids'] as $a) {
                foreach ($y['anker_ids'] as $b) {
                    if ($a === $b) {
                        return 3;   // derselbe Anker: trivial harmonisch
                    }
                    $best = max($best, $stufe[$a][$b] ?? 0);
                }
            }

            return $best;
        };

        $n = count($k);
        $paare = [];
        $gewSumme = 0.0;
        $gewSehrGut = 0.0;
        $bewertet = 0;
        $gesamt = intdiv($n * ($n - 1), 2);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $w = $k[$i]['gewicht'] * $k[$j]['gewicht'];
                if ($k[$i]['anker_ids'] === [] || $k[$j]['anker_ids'] === []) {
                    $paare[] = ['a' => $i, 'b' => $j, 'stufe' => 'unbekannt', 'ueber' => null, 'gewicht' => round($w, 4),
                        'satz' => $this->satzUnbekannt($k[$i], $k[$j])];

                    continue;
                }
                $bewertet++;
                $gewSumme += $w;
                $s = $paarStufe($k[$i], $k[$j]);
                $ueber = null;
                if ($s === 3) {
                    $art = 'sehr_gut';
                    $gewSehrGut += $w;
                } elseif ($s === 2) {
                    $art = 'passt';
                } else {
                    for ($m = 0; $m < $n && $ueber === null; $m++) {
                        if ($m !== $i && $m !== $j && $k[$m]['anker_ids'] !== []
                            && $paarStufe($k[$i], $k[$m]) === 3 && $paarStufe($k[$j], $k[$m]) === 3) {
                            $ueber = $m;
                        }
                    }
                    $art = $ueber !== null ? 'bruecke' : 'kein_bezug';
                }
                $paare[] = ['a' => $i, 'b' => $j, 'stufe' => $art, 'ueber' => $ueber, 'gewicht' => round($w, 4),
                    'satz' => $this->satzHarmonie($k[$i], $k[$j], $art, $ueber !== null ? $k[$ueber] : null)];
            }
        }

        $wert = $gewSumme > 0 ? (int) round(100 * $gewSehrGut / $gewSumme) : null;

        return [
            'paare' => $paare,
            'zusammenhalt' => [
                'wert' => $wert,
                'stufe' => $wert === null ? null : ($wert >= 50 ? 'gut' : ($wert >= 25 ? 'mittel' : 'schwach')),
                'bewertet' => $bewertet,
                'gesamt' => $gesamt,
                'abdeckung_pct' => $gesamt > 0 ? (int) round(100 * $bewertet / $gesamt) : 0,
            ],
        ];
    }

    /** Foodpairing-Stufe je Anker-Paar (Inspire 2/3, beide Richtungen; fehlend = Stufe 1). */
    private function stufenJePaar(array $ankerIds): array
    {
        if ($ankerIds === []) {
            return [];
        }
        return app(\Platform\FoodAlchemist\Services\Pairing\AnkerGraph::class)->stufen($ankerIds);
    }

    private function kontrast(array $k): array
    {
        $out = [];
        $n = count($k);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$x, $y] = [$k[$i], $k[$j]];
                if ($x['geschmack'] !== null && $y['geschmack'] !== null) {
                    foreach (self::GEGENSATZ as [$p, $q]) {
                        foreach ([[$x, $y, $i, $j], [$y, $x, $j, $i]] as [$traegerP, $traegerQ, $iP, $iQ]) {
                            $vp = (float) ($traegerP['geschmack'][$p] ?? 0);
                            $vq = (float) ($traegerQ['geschmack'][$q] ?? 0);
                            if ($vp >= self::DEUTLICH && $vq >= self::DEUTLICH) {
                                $belegt = in_array($p, $traegerP['gemessen'], true) && in_array($q, $traegerQ['gemessen'], true);
                                $out[] = ['a' => $iP, 'b' => $iQ, 'art' => 'geschmack', 'achse_a' => $p, 'achse_b' => $q,
                                    'staerke' => round($vp * $vq * ($traegerP['gewicht'] + $traegerQ['gewicht']), 4), 'belegt' => $belegt,
                                    'satz' => $this->satzKontrast($traegerP, $p, $traegerQ, $q, $belegt)];
                            }
                        }
                    }
                }
                // Knusprig gegen REIN weich: ein Bestandteil, der selbst schon knusprig ist, ist kein Gegenpol.
                $knusprigX = array_intersect($x['textur'], self::KNUSPRIG) !== [];
                $knusprigY = array_intersect($y['textur'], self::KNUSPRIG) !== [];
                $weichX = ! $knusprigX && array_intersect($x['textur'], self::WEICH) !== [];
                $weichY = ! $knusprigY && array_intersect($y['textur'], self::WEICH) !== [];
                if (($knusprigX && $weichY) || ($knusprigY && $weichX)) {
                    [$kn, $we] = $knusprigX && $weichY ? [$x, $y] : [$y, $x];
                    $out[] = ['a' => $knusprigX && $weichY ? $i : $j, 'b' => $knusprigX && $weichY ? $j : $i, 'art' => 'textur',
                        'achse_a' => 'knusprig', 'achse_b' => 'weich', 'staerke' => round(0.5 * ($kn['gewicht'] + $we['gewicht']), 4), 'belegt' => true,
                        'satz' => 'Spannung: das Knusprige von ' . $this->nenne($kn) . ' gegen das Weiche von ' . $this->nenne($we) . '.'];
                }
            }
        }
        usort($out, fn ($a, $b) => $b['staerke'] <=> $a['staerke']);

        // Je Gegensatz-Art nur das stärkste Beispiel des Gerichts: eine klare Aussage je Spannung
        // statt jedes Paar einzeln (gemessen 58 Treffer bei 10 Bestandteilen).
        $je = [];
        foreach ($out as $c) {
            $schluessel = $c['achse_a'] < $c['achse_b'] ? $c['achse_a'] . '|' . $c['achse_b'] : $c['achse_b'] . '|' . $c['achse_a'];
            $je[$schluessel] ??= $c;
        }

        return array_values($je);
    }

    /** Lücken auf Gericht-Ebene: was dem Teller als Gegenpol fehlt. */
    private function luecken(array $k): array
    {
        $mitProfil = array_values(array_filter($k, fn ($c) => $c['geschmack'] !== null));
        if ($mitProfil === []) {
            return [];
        }
        $teller = [];
        foreach (['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'] as $d) {
            $teller[$d] = max(array_map(fn ($c) => (float) ($c['geschmack'][$d] ?? 0), $mitProfil));
        }
        $out = [];
        if ($teller['fettig'] >= 0.6 && $teller['sauer'] < 0.3) {
            $out[] = ['code' => 'saeure_fehlt', 'text' => 'Viel Fett, aber kaum Säure: ein saures Element würde den Teller frischer machen.'];
        }
        if ($teller['suess'] >= 0.6 && $teller['sauer'] < 0.3 && $teller['bitter'] < 0.3) {
            $out[] = ['code' => 'gegenpol_suesse_fehlt', 'text' => 'Deutliche Süße ohne Gegenpol: etwas Säure oder Bitterkeit würde sie ausbalancieren.'];
        }
        $texturen = array_merge(...array_map(fn ($c) => $c['textur'], $k));
        if (count(array_intersect($texturen, self::WEICH)) >= 2 && array_intersect($texturen, self::KNUSPRIG) === []) {
            $out[] = ['code' => 'knuspriges_fehlt', 'text' => 'Überwiegend weich und cremig: ein knuspriges Element würde Spannung bringen.'];
        }

        return $out;
    }

    // ── Sätze (Küchensprache, gleiche Wortwahl für Anzeige und MCP) ─────────

    private function nenne(array $c): string
    {
        $rolle = ($c['rolle'] ?? null) !== null && $c['rolle'] !== 'komponente' ? ' (' . $c['rolle_label'] . ')' : '';

        return $c['kurz'] . $rolle;
    }

    /**
     * Kurzname für Sätze: Basisrezepte heißen „Kategorie: Name" (Regelwerk Basisrezepte §1) → Teil
     * nach dem Doppelpunkt; Grundprodukte „Name: Zustand" → Teil davor.
     */
    private function kurzname(string $name, bool $istRezept): string
    {
        if (! str_contains($name, ':')) {
            return $name;
        }
        [$vor, $nach] = array_map('trim', explode(':', $name, 2));

        return $istRezept ? ($nach !== '' ? $nach : $vor) : $vor;
    }

    private function satzHarmonie(array $a, array $b, string $art, ?array $ueber): string
    {
        return match ($art) {
            'sehr_gut' => "{$a['kurz']} und {$b['kurz']}: harmonieren sehr gut.",
            'passt' => "{$a['kurz']} und {$b['kurz']}: passen (schwache Foodpairing-Aussage).",
            'bruecke' => "{$a['kurz']} und {$b['kurz']}: kein direktes Pairing, beide harmonieren sehr gut mit {$ueber['kurz']}.",
            default => "{$a['kurz']} und {$b['kurz']}: kein Foodpairing-Bezug.",
        };
    }

    private function satzUnbekannt(array $a, array $b): string
    {
        $offen = array_filter([$a, $b], fn ($c) => $c['anker_ids'] === []);

        return implode(' und ', array_map(fn ($c) => $c['kurz'], $offen)) . ': noch keinem Aroma zugeordnet, keine Aussage möglich.';
    }

    private function satzKontrast(array $tp, string $p, array $tq, string $q, bool $belegt): string
    {
        return 'Spannung: ' . self::ACHSE_WORT[$p] . ' von ' . $this->nenne($tp) . ' gegen '
            . self::ACHSE_WORT[$q] . ' von ' . $this->nenne($tq) . '.' . ($belegt ? '' : ' (geschätzt)');
    }

    private function zusammenfassung(array $harmonie, array $kontrast, array $luecken): string
    {
        $z = $harmonie['zusammenhalt'];
        $teile = [];
        if ($z['wert'] === null) {
            $teile[] = 'Harmonie: keine Aussage, zu wenige Bestandteile einem Aroma zugeordnet.';
        } else {
            $sehrGut = count(array_filter($harmonie['paare'], fn ($p) => $p['stufe'] === 'sehr_gut'));
            $teile[] = "Harmonie {$z['stufe']}: {$sehrGut} von {$z['bewertet']} bewerteten Paaren harmonieren sehr gut"
                . ($z['bewertet'] < $z['gesamt'] ? " ({$z['abdeckung_pct']} % der Paare bewertbar)." : '.');
        }
        $teile[] = $kontrast === [] ? 'Kontrast: keiner erkannt.' : 'Kontrast: ' . count($kontrast) . ' Spannungen.';
        if ($luecken !== []) {
            $teile[] = 'Lücke: ' . implode(' ', array_column($luecken, 'text'));
        }

        return implode(' ', $teile);
    }

    // ── Hilfen ────────────────────────────────────────────────────────────

    /** Zubereitung aus dem Namen erkennen („Möhre: geröstet") → Delta aus vocab_process_sensory_deltas. */
    private function zubereitung(string $name): ?array
    {
        static $deltas = null;
        $deltas ??= DB::table('foodalchemist_vocab_process_sensory_deltas')->get()->keyBy('anchor_slug');
        $fold = mb_strtolower($name);
        foreach ($deltas as $slug => $d) {
            if (in_array($slug, ['frisch', 'gekocht', 'gegart'], true)) {
                continue;   // ändern das Profil nicht nennenswert
            }
            if (preg_match('/\b' . preg_quote(mb_strtolower($slug), '/') . '\b/u', $fold)) {
                return ['label' => $slug, 'delta' => [
                    'suess' => (float) $d->d_suess, 'salzig' => (float) $d->d_salzig, 'sauer' => (float) $d->d_sauer,
                    'bitter' => (float) $d->d_bitter, 'umami' => (float) $d->d_umami, 'fettig' => (float) $d->d_fettig,
                    'scharf' => (float) $d->d_scharf,
                ]];
            }
        }

        return null;
    }

    private function mitDelta(array $g, array $delta): array
    {
        foreach ($delta as $achse => $d) {
            $g[$achse] = round(max(0.0, min(1.0, (float) ($g[$achse] ?? 0) + $d)), 2);
        }

        return $g;
    }

    private function gramm(object $z): float
    {
        $menge = $z->quantity_max !== null ? ((float) $z->quantity + (float) $z->quantity_max) / 2 : (float) $z->quantity;
        if (($z->unit_slug ?? null) === 'qs') {
            return 0.0;
        }

        return $menge * (($z->default_in_g !== null && (float) $z->default_in_g > 0) ? (float) $z->default_in_g : 50.0);
    }

    private function leer(string $hinweis): array
    {
        return ['komponenten' => [], 'harmonie' => ['paare' => [], 'zusammenhalt' => ['wert' => null, 'stufe' => null,
            'bewertet' => 0, 'gesamt' => 0, 'abdeckung_pct' => 0]], 'kontrast' => [], 'luecken' => [], 'zusammenfassung' => $hinweis];
    }
}
