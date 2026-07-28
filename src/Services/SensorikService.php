<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Sensorik-Auswertung. ZWEI Quellen, klar getrennt:
 *  • Rezept/Gericht: bevorzugt das KI-bewertete GEGARTE Profil (foodalchemist_recipe_taste_vectors
 *    /_textur — eine KI liest Zutaten+Zubereitung; rohe Zwiebel ≠ Schmorzwiebel). Liegt keins vor,
 *    FALLBACK = Roh-Aggregat über die Zutaten-GPs (App-Port der Vault-232-Logik) — klar als „roh
 *    geschätzt" markiert. Manueller Eintrag (source='manual') gewinnt immer.
 *  • Grundprodukt: eigener Roh-Vektor (das ist für ein GP korrekt — ein GP ist roh).
 *
 * ERDUNG (Salzig/Süß/Fettig): die drei Dimensionen, die auf dem Etikett STEHEN, werden nicht
 * geschätzt, sondern aus den LA-Nährwerten gerechnet (GL-08-Pfad wie GpAggregateService::
 * naehrwerte: Ø über nicht-ausgelistete LAs, Salz = sodium × 0.0025). Gemessen schlägt KI —
 * die KI-Schätzung bleibt nur für das, was kein Label hergibt (sauer/bitter/umami/scharf).
 * Passiert bei JEDEM Lesen, nichts wird gespiegelt: neue Nährwerte wirken sofort, und ein
 * kuratierter/manueller Vektor in der Tabelle wird nie überschrieben, nur überlagert.
 *
 * Logik wie 232: MAX je Geschmacks-Dimension → Dominanz (≥0.6) / Lücke (<0.3); Textur-Monotonie
 * (überwiegend weich, kein Crunch) → Kontrast-Vorschläge aus dem GP-Bestand.
 */
class SensorikService
{
    public const DIMS = ['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'];

    public const DIM_LABEL = [
        'suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter',
        'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf',
    ];

    /** Texturen, die „weich/cremig" zählen (Monotonie-Erkennung). */
    private const WEICH = ['cremig', 'weich', 'mousse', 'pastoes', 'fluessig', 'gel', 'pueree', 'schaumig', 'saftig'];

    private const KNUSPRIG = ['knusprig', 'koernig', 'schnittfest'];

    /**
     * Rolle (Dish-Hauptgruppe) → erwartetes Sensorik-Soll: max über die Dims muss ≥ min sein.
     * Nur Klar-Fälle — wo es kein festes Soll gibt, bleibt der Check „info".
     */
    private const ROLLE_SOLL = [
        'Dessert' => ['dims' => ['suess'], 'min' => 0.5, 'label' => 'Süße'],
        'Käse' => ['dims' => ['fettig', 'salzig', 'umami'], 'min' => 0.4, 'label' => 'herzhaft-fettige Tiefe'],
        'Hauptgang' => ['dims' => ['umami', 'salzig'], 'min' => 0.35, 'label' => 'herzhafte Tiefe'],
        'Suppe & Eintopf' => ['dims' => ['umami', 'salzig'], 'min' => 0.3, 'label' => 'herzhafte Basis'],
        'Vorspeise' => ['dims' => ['sauer', 'scharf'], 'min' => 0.2, 'label' => 'Frische/Säure'],
    ];

    /** Messbare Dimension → Nährwert-Größe (g/100 g Rohmasse) + Anzeige-Label. */
    public const MESSBAR = [
        'salzig' => ['groesse' => 'salt_g', 'label' => 'Salz'],
        'suess' => ['groesse' => 'sugar', 'label' => 'Zucker'],
        'fettig' => ['groesse' => 'fat', 'label' => 'Fett'],
    ];

    /**
     * Stützstellen [g/100 g → Intensität 0–1], dazwischen logarithmisch interpoliert (der
     * Sinneseindruck wächst mit dem Logarithmus der Konzentration, nicht linear — Weber-Fechner).
     * Die Stützstellen sind NICHT frei gewählt, sondern die etablierten Gehalts-Schwellen:
     *  • salzig  0.3 = EU-VO 1924/2006 „natriumarm" (0,12 g Na); 1.5 = FSA-Front-of-Pack „hoch";
     *            15 ≈ Sojasauce (Regel-Seed 241: 0.9); 100 = Salz pur (Regel-Seed: 1.0).
     *  • suess   5 = EU-VO 1924/2006 „zuckerarm" (fest); 22.5 = FSA „hoch"; 100 = Zucker pur (1.0).
     *  • fettig  3 = EU-VO 1924/2006 „fettarm"; 17.5 = FSA „hoch"; 82 ≈ Butter (Regel-Seed: 0.9);
     *            100 = Öl (Regel-Seed: 1.0).
     */
    private const KURVE = [
        'salzig' => [[0.3, 0.20], [1.5, 0.60], [5.0, 0.80], [15.0, 0.90], [100.0, 1.00]],
        'suess' => [[5.0, 0.25], [22.5, 0.60], [50.0, 0.80], [100.0, 1.00]],
        'fettig' => [[3.0, 0.20], [17.5, 0.55], [40.0, 0.75], [82.0, 0.90], [100.0, 1.00]],
    ];

    /**
     * Leer-Label-Guard: beim Necta-Import kamen Artikel ohne Nährwert-Angabe als lauter Nullen
     * an (9.385 Zeilen im Quellbestand). Solche Zeilen dürfen eine Dimension nicht auf 0 ziehen —
     * eine Zeile zählt nur, wenn irgendein Kernwert > 0 ist. (Salz pur: kcal 0, aber sodium > 0.)
     *
     * Aus demselben Grund gilt je Nährstoff: eine exakte 0 ist KEINE Messung, sondern
     * „nicht deklariert" (NULLIF in der Query). Sonst hätte z. B. „Cola: konserviert" mit einem
     * 0-Zucker-Label seine kuratierte Süße 0.9 auf 0.0 verloren. Nach unten korrigiert die Erdung
     * weiterhin — aber nur gegen eine echte, positive Zahl.
     */
    private const LABEL_FELDER = ['energy_kcal', 'sodium', 'fat', 'sugar', 'protein', 'carbs_absorbable'];

    /**
     * Ab dieser Abweichung gemessen↔geschätzt wird der Widerspruch ausgewiesen (LA-Daten prüfen).
     * Asymmetrisch angewendet, weil der Datenfehler im Bestand asymmetrisch ist: ein zu HOHER
     * Label-Wert ist fast immer echt (Salz/Zucker/Fett sind wirklich drin), ein stark zu NIEDRIGER
     * ist das bekannte Import-Muster „nicht deklariert" (Praline mit 0,8 g Zucker/100 g, Margarine
     * mit 0,01 g Fett). Darum: nach oben wird korrigiert und markiert, ein starker Absturz wird
     * NICHT übernommen — die Schätzung bleibt stehen und der Widerspruch wird gemeldet.
     */
    private const KONFLIKT_AB = 0.4;

    /** GP-IDs aus Rezepten (deren Zutaten-GPs + 1 Ebene Sub-Rezepte). */
    private function gpIdsFromRecipes(array $recipeIds): array
    {
        $recipeIds = array_values(array_filter(array_map('intval', $recipeIds)));
        if ($recipeIds === []) {
            return [];
        }
        $ing = DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')
            ->whereIn('recipe_id', $recipeIds)->get(['gp_id', 'referenced_recipe_id']);
        $gpIds = $ing->pluck('gp_id')->filter()->all();
        $subRecipeIds = $ing->pluck('referenced_recipe_id')->filter()->unique()->all();
        if ($subRecipeIds !== []) {
            $sub = DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')
                ->whereIn('recipe_id', $subRecipeIds)->pluck('gp_id')->filter()->all();
            $gpIds = array_merge($gpIds, $sub);
        }

        return array_values(array_unique(array_map('intval', $gpIds)));
    }

    /**
     * Sensorik eines Rezepts/Gerichts: KI-gegartes Profil wenn vorhanden, sonst Roh-Aggregat.
     */
    public function fuerRezept(int $recipeId): array
    {
        $stored = DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $recipeId)->first();
        if ($stored !== null) {
            $geschmack = [];
            foreach (self::DIMS as $d) {
                $geschmack[$d] = round((float) ($stored->{$d} ?? 0), 2);
            }
            $texRows = DB::table('foodalchemist_recipe_textures AS t')
                ->join('foodalchemist_vocab_textures AS v', 'v.id', '=', 't.texture_vocab_id')
                ->where('t.recipe_id', $recipeId)
                ->selectRaw('v.slug, v.display_de, MAX(t.intensity) AS intensitaet')
                ->groupBy('v.slug', 'v.display_de')->get();

            return $this->montage(
                $geschmack, $texRows,
                $stored->source === 'manual' ? 'manual' : 'ki',
                $stored->ai_confidence !== null ? (float) $stored->ai_confidence : null,
                $stored->ai_reasoning,
            );
        }

        return $this->auswertung($this->gpIdsFromRecipes([$recipeId]), 'roh');
    }

    /** Sensorik eines einzelnen Grundprodukts (eigener Roh-Vektor — für ein GP korrekt). */
    public function fuerGp(int $gpId): array
    {
        return $this->auswertung([(int) $gpId], 'gp');
    }

    public function fuerConcept(FoodAlchemistConcept $concept): array
    {
        $recipeIds = $concept->slots->pluck('sales_recipe_id')->filter()->unique()->values()->all();

        return $this->auswertung($this->gpIdsFromRecipes($recipeIds), 'roh');
    }

    // ── B: Gericht als KOMPOSITION (statt Blend) + Rollen-Soll-Check ─────────

    /** Hauptgruppe/Rolle eines Rezepts (Vorspeise/Hauptgang/Dessert …) via Speisen-Klasse. */
    private function mainGroup(int $recipeId): ?string
    {
        return DB::table('foodalchemist_recipes AS r')
            ->leftJoin('foodalchemist_dish_classes AS dc', 'dc.id', '=', 'r.dish_class_id')
            ->leftJoin('foodalchemist_dish_main_groups AS mg', 'mg.id', '=', 'dc.dish_main_group_id')
            ->where('r.id', $recipeId)->value('mg.label');
    }

    /**
     * Rollen-Soll-Check: passt das (Teller-)Profil zur Rolle? (Dessert→süß, Hauptgang→umami …).
     *
     * @return ?array{role:string, status:string, detail:string}
     */
    public function rollenCheck(int $recipeId, array $geschmack): ?array
    {
        $hg = $this->mainGroup($recipeId);
        if ($hg === null) {
            return null;
        }
        $soll = self::ROLLE_SOLL[$hg] ?? null;
        if ($soll === null) {
            return ['role' => $hg, 'status' => 'info', 'detail' => 'Keine feste Sensorik-Erwartung für diese Rolle.'];
        }
        $ist = 0.0;
        foreach ($soll['dims'] as $d) {
            $ist = max($ist, (float) ($geschmack[$d] ?? 0));
        }
        $ok = $ist >= $soll['min'];

        return [
            'role' => $hg,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok
                ? "{$hg}: {$soll['label']} vorhanden (" . number_format($ist, 2, ',', '.') . ').'
                : "{$hg}: {$soll['label']} schwach (" . number_format($ist, 2, ',', '.') . ' < ' . number_format($soll['min'], 2, ',', '.') . ').',
        ];
    }

    /**
     * Gericht = Komposition seiner Komponenten (Sub-Rezepte = gegartes Profil, direkte GPs = roh),
     * NICHT ein gemittelter Blend. Teller-Profil = MAX je Dimension („ist der Geschmack auf dem Teller
     * irgendwo da?") → Dominanz/Lücke + Rollen-Soll-Check.
     */
    public function gerichtKomposition(int $recipeId): array
    {
        $ing = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->get(['ri.gp_id', 'ri.referenced_recipe_id', DB::raw('COALESCE(sr.name, g.name, ri.raw_text) AS name')]);

        $komponenten = [];
        $teller = array_fill_keys(self::DIMS, 0.0);
        foreach ($ing as $z) {
            $p = $z->referenced_recipe_id !== null
                ? $this->fuerRezept((int) $z->referenced_recipe_id)
                : ($z->gp_id !== null ? $this->fuerGp((int) $z->gp_id) : null);
            if ($p === null || ($p['leer'] ?? false)) {
                continue;
            }
            $g = $p['geschmack'];
            foreach (self::DIMS as $d) {
                $teller[$d] = max($teller[$d], (float) ($g[$d] ?? 0));
            }
            $komponenten[] = [
                'name' => $z->name,
                'source' => $p['source'] ?? 'roh',
                'geschmack' => $g,
                'dominant' => array_keys(array_filter($g, fn ($v) => $v >= 0.6)),
            ];
        }

        return [
            'leer' => $komponenten === [],
            'komponenten' => $komponenten,
            'teller' => $teller,
            'dominant' => array_keys(array_filter($teller, fn ($v) => $v >= 0.6)),
            'luecken' => array_keys(array_filter($teller, fn ($v) => $v < 0.3)),
            'rollencheck' => $this->rollenCheck($recipeId, $teller),
        ];
    }

    // ── Erdung: messbare Dimensionen aus den LA-Nährwerten statt aus der KI ──

    /**
     * Gemessene Dimensionen je GP aus den LA-Nährwerten (GL-08-Pfad, identisch zu
     * GpAggregateService::naehrwerte: Ø über nicht-ausgelistete LAs, NULL fällt raus).
     * EINE Query für alle GPs — kein N+1 im Zutaten-Durchlauf.
     *
     * @return array<int, array<string, array{wert: float, basis: string}>> [gp_id][dim]
     */
    public function erdungBulk(array $gpIds): array
    {
        $gpIds = array_values(array_unique(array_filter(array_map('intval', $gpIds))));
        if ($gpIds === []) {
            return [];
        }

        $rows = DB::table('foodalchemist_item_nutritionals AS n')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'n.supplier_item_id')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'n.supplier_item_id')
            ->whereIn('s.gp_id', $gpIds)
            ->where('i.is_discontinued', false)
            ->whereNull('n.deleted_at')->whereNull('s.deleted_at')->whereNull('i.deleted_at')
            ->where(function ($q) {                          // Leer-Label-Guard
                foreach (self::LABEL_FELDER as $f) {
                    $q->orWhere("n.{$f}", '>', 0);
                }
            })
            ->groupBy('s.gp_id')
            // NULLIF(x,0): eine exakte 0 ist in diesem Bestand „nicht deklariert", keine Messung.
            ->selectRaw('s.gp_id, AVG(NULLIF(n.sodium,0)) AS sodium, COUNT(NULLIF(n.sodium,0)) AS n_sodium, '
                . 'AVG(NULLIF(n.fat,0)) AS fat, COUNT(NULLIF(n.fat,0)) AS n_fat, '
                . 'AVG(NULLIF(n.sugar,0)) AS sugar, COUNT(NULLIF(n.sugar,0)) AS n_sugar, '
                . 'AVG(NULLIF(n.carbs_absorbable,0)) AS carbs')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $gehalt = [
                'salt_g' => $r->sodium !== null ? (float) $r->sodium * 0.0025 : null,   // GL-08 §4.2
                'sugar' => $r->sugar !== null ? (float) $r->sugar : null,
                'fat' => $r->fat !== null ? (float) $r->fat : null,
            ];
            // LMIV: „davon Zucker" ist eine Teilmenge der Kohlenhydrate. Ist er größer, ist die
            // Zeile kaputt (106 Fälle im Bestand) → Zucker verwerfen statt falsch erden.
            if ($gehalt['sugar'] !== null && $r->carbs !== null && $gehalt['sugar'] > (float) $r->carbs * 1.05 + 0.5) {
                $gehalt['sugar'] = null;
            }
            $anzahl = ['salt_g' => (int) $r->n_sodium, 'sugar' => (int) $r->n_sugar, 'fat' => (int) $r->n_fat];

            $dims = [];
            foreach (self::MESSBAR as $dim => $m) {
                $g = $gehalt[$m['groesse']];
                if ($g === null || $anzahl[$m['groesse']] < 1) {
                    continue;                                 // kein Messwert ⇒ KI-Schätzung behalten
                }
                $dims[$dim] = [
                    'wert' => $this->kurve($g, self::KURVE[$dim]),
                    'basis' => $m['label'] . ' ' . number_format($g, 1, ',', '.') . ' g/100 g'
                        . ($anzahl[$m['groesse']] > 1 ? ' · Ø ' . $anzahl[$m['groesse']] . ' LA' : ''),
                ];
            }
            if ($dims !== []) {
                $out[(int) $r->gp_id] = $dims;
            }
        }

        return $out;
    }

    /** Gehalt (g/100 g) → Intensität 0–1 über die Stützstellen, log-interpoliert. */
    private function kurve(float $gehalt, array $stuetzstellen): float
    {
        if ($gehalt <= 0) {
            return 0.0;
        }
        $letzte = end($stuetzstellen);
        if ($gehalt >= $letzte[0]) {
            return (float) $letzte[1];
        }
        [$x0, $y0] = [0.0, 0.0];
        foreach ($stuetzstellen as [$x1, $y1]) {
            if ($gehalt > $x1) {
                [$x0, $y0] = [$x1, $y1];

                continue;
            }
            $anteil = $x0 > 0
                ? log10($gehalt / $x0) / log10($x1 / $x0)
                : $gehalt / $x1;                              // unterster Abschnitt: log ab 0 undefiniert

            return round($y0 + ($y1 - $y0) * $anteil, 2);
        }

        return 0.0;
    }

    /**
     * Effektiver Roh-Vektor je GP: gespeicherter Vektor, messbare Dimensionen davon überlagert.
     * `messung[dim]` dokumentiert je erdbarer Achse die Label-Basis, ob sie angewendet wurde und
     * einen etwaigen Widerspruch zur Schätzung.
     *
     * @return array<int, array{
     *     werte: array<string, float>,
     *     messung: array<string, array{basis: string, angewendet: bool, konflikt: ?string}>,
     *     hat_daten: bool
     * }>
     */
    public function vektorenFuerGps(array $gpIds): array
    {
        $gpIds = array_values(array_unique(array_filter(array_map('intval', $gpIds))));
        if ($gpIds === []) {
            return [];
        }
        $gespeichert = DB::table('foodalchemist_gp_taste_vectors')->whereIn('gp_id', $gpIds)
            ->get(array_merge(['gp_id'], self::DIMS))->keyBy('gp_id');
        $gemessen = $this->erdungBulk($gpIds);

        $out = [];
        foreach ($gpIds as $gpId) {
            $row = $gespeichert->get($gpId);
            $werte = [];
            foreach (self::DIMS as $d) {
                $werte[$d] = round((float) ($row->{$d} ?? 0), 2);
            }
            $nf = fn (float $x) => number_format($x, 2, ',', '.');
            $messung = [];
            foreach ($gemessen[$gpId] ?? [] as $dim => $m) {
                $delta = $row !== null ? $m['wert'] - $werte[$dim] : 0.0;

                if ($delta <= -self::KONFLIKT_AB) {           // Absturz ⇒ Label unplausibel, nicht übernehmen
                    $messung[$dim] = [
                        'basis' => $m['basis'], 'angewendet' => false,
                        'konflikt' => 'Label ergäbe nur ' . $nf($m['wert']) . ' — unplausibel, Schätzung '
                            . $nf($werte[$dim]) . ' bleibt stehen',
                    ];

                    continue;
                }

                $messung[$dim] = [
                    'basis' => $m['basis'], 'angewendet' => true,
                    'konflikt' => $delta >= self::KONFLIKT_AB
                        ? 'deutlich über der Schätzung (' . $nf($werte[$dim]) . ') — LA-Zuordnung prüfen'
                        : null,
                ];
                $werte[$dim] = $m['wert'];                    // gemessen schlägt geschätzt
            }
            $out[$gpId] = [
                'werte' => $werte,
                'messung' => $messung,
                'hat_daten' => $row !== null || $messung !== [],
            ];
        }

        return $out;
    }

    /** Roh-Aggregat über eine GP-Menge (MAX je Dimension, wie 232) → Montage. */
    private function auswertung(array $gpIds, string $source): array
    {
        if ($gpIds === []) {
            return ['leer' => true];
        }
        $vektoren = $this->vektorenFuerGps($gpIds);

        // MAX je Dimension ÜBER die effektiven GP-Vektoren (erst erden, dann maxen — sonst würde
        // ein GP ohne Nährwerte gegen den Messwert eines anderen GPs verlieren).
        $geschmack = array_fill_keys(self::DIMS, 0.0);
        $erdung = [];
        $mitDaten = 0;
        foreach ($vektoren as $v) {
            $mitDaten += $v['hat_daten'] ? 1 : 0;
            foreach (self::DIMS as $d) {
                if ($v['werte'][$d] <= $geschmack[$d]) {
                    continue;
                }
                $geschmack[$d] = $v['werte'][$d];
                if (isset($v['messung'][$d])) {
                    $erdung[$d] = $v['messung'][$d];
                } else {
                    unset($erdung[$d]);                       // führender Wert ist geschätzt, nicht gemessen
                }
            }
        }

        $texRows = DB::table('foodalchemist_gp_textures AS t')
            ->join('foodalchemist_vocab_textures AS v', 'v.id', '=', 't.texture_vocab_id')
            ->whereIn('t.gp_id', $gpIds)
            ->selectRaw('v.slug, v.display_de, MAX(t.intensity) AS intensitaet')
            ->groupBy('v.slug', 'v.display_de')->get();

        return $this->montage($geschmack, $texRows, $source, null, null,
            ['mit' => $mitDaten, 'gesamt' => count($vektoren)], $erdung);
    }

    /**
     * Gemeinsame Ergebnis-Montage — Grundgeschmack als reine DIAGNOSE (Dominanz/Lücke/Textur/
     * Monotonie). Kontrast- und Komplettierungs-Vorschläge liefert der Anker-Graph
     * (PairingService, kontrast/klassisch-Kanten) — nicht diese Schicht. Daher kein SKU-Vorschlag hier.
     */
    private function montage(array $geschmack, $texRows, string $source, ?float $conf, ?string $begr, ?array $abdeckung = null, array $erdung = []): array
    {
        $dominant = array_keys(array_filter($geschmack, fn ($v) => $v >= 0.6));
        $luecken = array_keys(array_filter($geschmack, fn ($v) => $v < 0.3));

        $textur = $texRows->sortByDesc('intensity')
            ->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->display_de])->values()->all();
        $slugs = array_column($textur, 'slug');
        $weichN = count(array_intersect($slugs, self::WEICH));
        $hatCrunch = count(array_intersect($slugs, self::KNUSPRIG)) > 0;
        $monotonie = ($weichN >= 2 && ! $hatCrunch)
            ? 'Überwiegend weich/cremig, kein knuspriger Kontrast — ein Crunch-Element würde den Teller heben.'
            : null;

        return [
            'leer' => false,
            'source' => $source,                 // ki | manual | roh | gp
            'confidence' => $conf,
            'reasoning' => $begr,
            'abdeckung' => $abdeckung,           // nur Roh-Pfad (GP-Coverage); KI-Pfad = null
            'erdung' => $erdung,                 // [dim => Basis-Text] — Dimensionen aus LA-Nährwerten GEMESSEN
            'geschmack' => $geschmack,
            'dominant' => $dominant,
            'luecken' => $luecken,
            'textur' => $textur,
            'monotonie' => $monotonie,
            'vorschlaege' => [],                 // Diagnose-Schicht schlägt nichts vor (Kontrast = Anker-Graph)
        ];
    }

    // ── Schreibpfad: KI bewertet das GEGARTE Rezept ──────────────────────────

    /**
     * Bewertet ein Rezept/Gericht sensorisch via KI (gegartes Profil) und speichert es.
     * Skip-if-unchanged über source_hash; manueller Eintrag (source='manual') gewinnt.
     *
     * @return array{status: string, geschmack?: array, source?: string}
     */
    public function bewerteRezept(int $recipeId, bool $force = false): array
    {
        $recipe = DB::table('foodalchemist_recipes')->where('id', $recipeId)->whereNull('deleted_at')
            ->first(['id', 'name', 'preparation', 'description']);
        if ($recipe === null) {
            return ['status' => 'kein_rezept'];
        }

        $zutaten = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->selectRaw('COALESCE(g.name, sr.name, ri.raw_text) AS name')->pluck('name')
            ->filter()->values()->all();

        $signatur = mb_strtolower(trim($recipe->name) . '|' . implode(',', $zutaten) . '|' . trim((string) $recipe->preparation));
        $hash = hash('sha256', $signatur);

        $stored = DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $recipeId)->first();
        if ($stored !== null && ! $force) {
            if ($stored->source === 'manual') {
                return ['status' => 'manual_geschuetzt'];
            }
            if ($stored->source_hash === $hash) {
                return ['status' => 'unveraendert'];
            }
        }

        $proposal = app(AiGatewayService::class)->propose('recipe.sensorik', [
            'name' => $recipe->name,
            'zutaten_geerdet' => $this->groundingKontext($recipeId),     // Roh-Vektor + Menge(g) + %-Anteil je Zutat
            'preparation' => $recipe->preparation ?: ($recipe->description ?: null),
        ], ['target_table' => 'foodalchemist_recipe_taste_vectors', 'target_id' => $recipeId]);

        $werte = $proposal->werte;
        $geschmack = $werte['geschmack'] ?? null;
        if (! is_array($geschmack)) {
            return ['status' => 'kein_ergebnis'];   // z. B. Fake-Provider in der Sandbox → nichts schreiben
        }
        $this->speichereRezept($recipeId, $geschmack, $werte['texturen'] ?? [], $hash, $proposal->confidence, $proposal->reasoning);

        return ['status' => 'bewertet', 'geschmack' => $geschmack, 'source' => 'ki'];
    }

    /**
     * Grounding-Kontext für die KI: pro Zutat ROH-Profil (GP-Vektor) + Menge (g) + %-Anteil am
     * Gesamtgewicht. Damit kennt die KI die Fakten (was, wie viel) und wendet nur die Zubereitung
     * als Transformation an — statt aus dem Namen zu raten. Salzig/Süß/Fettig kommen dabei aus
     * den LA-Nährwerten (gemessen, s. erdungBulk) und sind als „gemessen" ausgewiesen, damit die
     * KI sie nicht gegen eine eigene Schätzung eintauscht.
     */
    private function groundingKontext(int $recipeId): string
    {
        $rows = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->leftJoin('foodalchemist_vocab_units AS e', 'e.id', '=', 'ri.unit_vocab_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->get(['ri.gp_id', 'ri.quantity', 'e.default_in_g', 'e.default_in_ml',
                DB::raw('COALESCE(g.name, sr.name, ri.raw_text) AS name')]);

        $vektoren = $this->vektorenFuerGps($rows->pluck('gp_id')->filter()->all());

        $gramm = [];
        $total = 0.0;
        foreach ($rows as $i => $r) {
            $g = $r->quantity !== null ? (float) $r->quantity * (float) ($r->default_in_g ?? $r->default_in_ml ?? 0) : 0.0;
            $gramm[$i] = $g;
            $total += $g;
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            if (($r->name ?? '') === '') {
                continue;
            }
            $mengeTxt = $gramm[$i] > 0
                ? ' ' . round($gramm[$i]) . 'g' . ($total > 0 ? ' (' . round($gramm[$i] / $total * 100, 1) . '%)' : '')
                : '';
            $v = $vektoren[(int) $r->gp_id] ?? null;
            $dims = [];
            foreach (self::DIMS as $d) {
                $val = round((float) ($v['werte'][$d] ?? 0), 2);
                if ($val > 0) {
                    $gemessen = ($v['messung'][$d]['angewendet'] ?? false)
                        ? ' [gemessen: ' . $v['messung'][$d]['basis'] . ']' : '';
                    $dims[] = self::DIM_LABEL[$d] . ' ' . $val . $gemessen;
                }
            }
            $roh = $dims !== [] ? ' — roh: ' . implode(' / ', $dims) : '';
            $lines[] = '- ' . $r->name . $mengeTxt . $roh;
        }

        return implode("\n", $lines);
    }

    /** Persistiert ein gegartes Profil (Geschmack upsert + Textur ersetzen, source='ai'). */
    private function speichereRezept(int $recipeId, array $geschmack, array $texturen, string $hash, ?float $conf, ?string $begr): void
    {
        $clamp = fn ($x) => max(0.0, min(1.0, round((float) $x, 2)));
        $row = ['recipe_id' => $recipeId, 'source' => 'ai', 'source_hash' => $hash,
            'ai_confidence' => $conf, 'ai_reasoning' => $begr, 'updated_at' => now()];
        foreach (self::DIMS as $d) {
            $row[$d] = $clamp($geschmack[$d] ?? 0);
        }
        DB::table('foodalchemist_recipe_taste_vectors')->updateOrInsert(
            ['recipe_id' => $recipeId], $row + ['created_at' => now()],
        );

        // Textur: nur KI-Zeilen ersetzen (manuelle bleiben), dann neu setzen
        $vocab = DB::table('foodalchemist_vocab_textures')->pluck('id', 'slug');
        DB::table('foodalchemist_recipe_textures')->where('recipe_id', $recipeId)->where('source', 'ai')->delete();
        foreach ($texturen as $t) {
            $slug = $t['slug'] ?? null;
            if ($slug === null || ! $vocab->has($slug)) {
                continue;
            }
            DB::table('foodalchemist_recipe_textures')->updateOrInsert(
                ['recipe_id' => $recipeId, 'texture_vocab_id' => $vocab[$slug]],
                ['intensity' => $clamp($t['intensity'] ?? 1), 'source' => 'ai', 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
