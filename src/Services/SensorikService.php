<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Sensorik-Auswertung. ZWEI Quellen, klar getrennt:
 *  • Rezept/Gericht: bevorzugt das KI-bewertete GEGARTE Profil (foodalchemist_recipe_taste_vectors
 *    /_textur — eine KI liest Zutaten+Zubereitung; rohe Zwiebel ≠ Schmorzwiebel). Liegt keins vor,
 *    FALLBACK = Roh-Aggregat über die Zutaten-GPs (App-Port der Vault-232-Logik) — klar als „roh
 *    geschätzt" markiert. Manueller Eintrag (source='manual') gewinnt immer.
 *  • Grundprodukt: eigener Roh-Vektor (das ist für ein GP korrekt — ein GP ist roh).
 *
 * WAHRNEHMUNG (seit 29.10.2026): ein Profil beschreibt, wie das Produkt SCHMECKT, nicht wie viel drin ist. Salzig/Süß/Fettig
 * stehen als Nährwerte (g/100 g, aus den LA, Salz = sodium × 0.0025) daneben, als Fakt und Grundlage. Die Kurve
 * ({@see self::erdungBulk()}) liefert nur noch den Konzentrationswert ohne Maskierung: als Hinweis und — ohne
 * gespeichertes Profil — als vorläufigen Wert. Die KI schätzt alle 7 Achsen; {@see self::begrenze()} fängt nach der
 * Antwort klare Ausreißer ab (FSA „hoch" / EU „arm"). Gemessen überschreibt beim Lesen nichts mehr.
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
     * Plausibilitätsgrenzen für die KI-Wahrnehmung (Dominique 29.10.2026). Dieselben belegten Schwellen wie {@see self::KURVE}:
     *  • unter  = Gehalt „hoch" (FSA-Front-of-Pack) ⇒ die Achse ist mindestens so stark;
     *  • ober   = Gehalt „arm" (EU-VO 1924/2006) ⇒ die Achse ist höchstens so stark.
     * Süße hat keine Obergrenze: Süßstoffe schmecken süß, ohne Zucker zu enthalten (Cola Zero).
     * Schlägt die Grenze zu, ersetzt der Code den KI-Wert und schreibt den Eingriff in die Begründung.
     */
    public const GRENZEN = [
        'salzig' => ['unter' => ['ab' => 1.5, 'min' => 0.30], 'ober' => ['unter' => 0.3, 'max' => 0.20]],
        'fettig' => ['unter' => ['ab' => 17.5, 'min' => 0.30], 'ober' => ['unter' => 3.0, 'max' => 0.20]],
        'suess' => ['unter' => ['ab' => 22.5, 'min' => 0.30], 'ober' => null],
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
     * Ab dieser Abweichung Wahrnehmung↔Konzentration wird ein Hinweis angezeigt: Überdeckung (Zucker über Salz) ist gewollt,
     * kann aber auch ein Altwert sein (Profile bis zum Neulauf tragen noch den früher eingespielten Messwert).
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
     * Sensorik eines Rezepts/Gerichts: KI-gegartes Profil wenn vorhanden, sonst Roh-Aggregat
     * (mengengewichtetes Mittel der Wahrnehmungsprofile; ohne Mengen MAX, gekennzeichnet).
     * `$mitGrundlage` hängt die Rezept-Nährwerte (Zucker/Salz/Fett je 100 g) als `erdung` an — nur für die Anzeige,
     * weil es Abfragen kostet.
     */
    public function fuerRezept(int $recipeId, bool $mitGrundlage = false): array
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

            $out = $this->montage(
                $geschmack, $texRows,
                $stored->source === 'manual' ? 'manual' : 'ki',
                $stored->ai_confidence !== null ? (float) $stored->ai_confidence : null,
                $stored->ai_reasoning,
            );
        } else {
            $out = $this->rohRezept($recipeId);
        }
        if ($mitGrundlage && ! ($out['leer'] ?? false)) {
            $out['erdung'] = $this->rezeptGrundlage($recipeId);
        }
        // Lücken nur bei Gerichten (Dominique 29.10.2026, Roastbeef): ein Basisrezept ist ein Baustein, ihm „fehlt" kein Geschmack.
        // Die Dominanten bleiben. Am GP gilt seit #606 „schwach" statt „Lücke".
        if (! ($out['leer'] ?? false) && ! (bool) DB::table('foodalchemist_recipes')->where('id', $recipeId)->value('is_sales_recipe')) {
            $out['luecken'] = [];
        }

        return $out;
    }

    /** Hinweis, wenn der Roh-Fallback ohne Mengen nur MAX kann. */
    public const HINWEIS_OHNE_MENGEN = 'grob, ohne Mengen';

    /** Rekursionstiefe für Sub-Rezepte im Roh-Fallback. */
    private int $rohTiefe = 0;

    /**
     * Roh-Fallback für ein Rezept ohne gegartes Profil: mengengewichtetes Mittel der Wahrnehmungsprofile der Zutaten
     * (Sub-Rezepte mit ihrem eigenen Profil). Damit dominiert eine Prise Salz nicht mehr das Rezept (Dominique 29.10.2026).
     * Ohne jede Menge bleibt MAX je Achse, gekennzeichnet.
     */
    private function rohRezept(int $recipeId): array
    {
        $zeilen = array_values(array_filter($this->zutatenMitGramm($recipeId), fn ($z) => $z['gramm'] > 0));
        $gpIds = $this->gpIdsFromRecipes([$recipeId]);
        if ($zeilen === [] || $this->rohTiefe >= 3) {
            $out = $this->auswertung($gpIds, 'roh');
            if (! ($out['leer'] ?? false)) {
                $out['hinweis'] = self::HINWEIS_OHNE_MENGEN;
            }

            return $out;
        }
        $vektoren = $this->vektorenFuerGps(array_column($zeilen, 'gp_id'));
        $summe = array_fill_keys(self::DIMS, 0.0);
        $gewicht = 0.0;
        $mitDaten = 0;
        $gesamt = 0;
        foreach ($zeilen as $z) {
            if ($z['gp_id'] !== null && isset($vektoren[$z['gp_id']])) {
                $werte = $vektoren[$z['gp_id']]['werte'];
                $mitDaten += $vektoren[$z['gp_id']]['hat_daten'] ? 1 : 0;
                $gesamt++;
            } elseif ($z['ref'] !== null) {
                $this->rohTiefe++;
                try {
                    $sub = $this->fuerRezept($z['ref']);
                } finally {
                    $this->rohTiefe--;
                }
                if ($sub['leer'] ?? false) {
                    continue;
                }
                $werte = $sub['geschmack'];
                $gesamt++;
                $mitDaten++;
            } else {
                continue;
            }
            foreach (self::DIMS as $d) {
                $summe[$d] += $z['gramm'] * (float) ($werte[$d] ?? 0);
            }
            $gewicht += $z['gramm'];
        }
        if ($gewicht <= 0) {
            return array_merge($this->auswertung($gpIds, 'roh'), ['hinweis' => self::HINWEIS_OHNE_MENGEN]);
        }
        $geschmack = [];
        foreach (self::DIMS as $d) {
            $geschmack[$d] = round($summe[$d] / $gewicht, 2);
        }

        return $this->montage($geschmack, $gpIds === [] ? collect() : $this->texturZeilen($gpIds), 'roh', null, null,
            ['mit' => $mitDaten, 'gesamt' => $gesamt], []);
    }

    /**
     * Zutaten eines Rezepts mit Menge in Gramm (Menge × Einheit g bzw. ml; 0 = keine Mengenangabe).
     *
     * @return list<array{gp_id: ?int, ref: ?int, name: string, gramm: float}>
     */
    private function zutatenMitGramm(int $recipeId): array
    {
        $rows = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->leftJoin('foodalchemist_vocab_units AS e', 'e.id', '=', 'ri.unit_vocab_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->get(['ri.gp_id', 'ri.referenced_recipe_id', 'ri.quantity', 'e.default_in_g', 'e.default_in_ml',
                DB::raw('COALESCE(g.name, sr.name, ri.raw_text) AS name')]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gp_id' => $r->gp_id !== null ? (int) $r->gp_id : null,
                'ref' => $r->referenced_recipe_id !== null ? (int) $r->referenced_recipe_id : null,
                'name' => (string) ($r->name ?? ''),
                'gramm' => $r->quantity !== null ? (float) $r->quantity * (float) ($r->default_in_g ?? $r->default_in_ml ?? 0) : 0.0,
            ];
        }

        return $out;
    }

    /** Ab diesem Anteil der Masse mit bekanntem Gehalt gelten die Nährwert-Grenzen auch am Rezept. */
    public const GRENZE_MIN_ABDECKUNG = 0.8;

    /**
     * Nährwerte des Rezepts je 100 g Zutatenmasse (Zucker, Salz, Fett) aus Menge × Gehalt der Zutaten (Sub-Rezepte rekursiv).
     * `abdeckung[dim]` = Anteil der Masse, für den ein Gehalt bekannt ist; der Wert ist der Schnitt über die bekannte Masse.
     *
     * @return array{werte: array<string, ?float>, abdeckung: array<string, float>}
     */
    public function rezeptNaehrwerte(int $recipeId, int $tiefe = 0): array
    {
        $zeilen = array_values(array_filter($this->zutatenMitGramm($recipeId), fn ($z) => $z['gramm'] > 0));
        $leer = ['werte' => array_fill_keys(array_keys(self::MESSBAR), null), 'abdeckung' => array_fill_keys(array_keys(self::MESSBAR), 0.0)];
        if ($zeilen === []) {
            return $leer;
        }
        $erdung = $this->erdungBulk(array_filter(array_column($zeilen, 'gp_id')));
        $gesamt = array_sum(array_column($zeilen, 'gramm'));
        $subs = [];
        foreach ($zeilen as $z) {
            if ($z['ref'] !== null && $tiefe < 3) {
                $subs[$z['ref']] ??= $this->rezeptNaehrwerte($z['ref'], $tiefe + 1);
            }
        }
        $out = $leer;
        foreach (array_keys(self::MESSBAR) as $dim) {
            $summe = 0.0;
            $bekannt = 0.0;
            foreach ($zeilen as $z) {
                if ($z['gp_id'] !== null && isset($erdung[$z['gp_id']][$dim])) {
                    $summe += $z['gramm'] * $erdung[$z['gp_id']][$dim]['gehalt'] / 100;
                    $bekannt += $z['gramm'];
                } elseif ($z['ref'] !== null && isset($subs[$z['ref']]) && ($subs[$z['ref']]['werte'][$dim] ?? null) !== null) {
                    $anteil = $subs[$z['ref']]['abdeckung'][$dim];
                    $summe += $z['gramm'] * $anteil * $subs[$z['ref']]['werte'][$dim] / 100;
                    $bekannt += $z['gramm'] * $anteil;
                }
            }
            if ($bekannt > 0) {
                $out['werte'][$dim] = round($summe / $bekannt * 100, 2);
                $out['abdeckung'][$dim] = round($bekannt / $gesamt, 3);
            }
        }

        return $out;
    }

    /**
     * Gehalt je Achse für die Grenzen am Rezept: nur Achsen, deren Gehalt für genug Masse bekannt ist.
     *
     * @return array<string, float>
     */
    private function rezeptGehaltFuerGrenzen(array $naehr): array
    {
        $out = [];
        foreach ($naehr['werte'] as $dim => $wert) {
            if ($wert !== null && $naehr['abdeckung'][$dim] >= self::GRENZE_MIN_ABDECKUNG) {
                $out[$dim] = (float) $wert;
            }
        }

        return $out;
    }

    /**
     * Anzeige-Grundlage am Rezept (gleiche Form wie am GP): „Salz 0,9 g/100 g · aus den Zutaten".
     *
     * @return array<string, array{basis: string, gehalt: float, vorlaeufig: bool, konflikt: ?string}>
     */
    private function rezeptGrundlage(int $recipeId): array
    {
        $naehr = $this->rezeptNaehrwerte($recipeId);
        $out = [];
        foreach ($naehr['werte'] as $dim => $wert) {
            if ($wert === null) {
                continue;
            }
            $anteil = (int) round($naehr['abdeckung'][$dim] * 100);
            $out[$dim] = [
                'basis' => self::MESSBAR[$dim]['label'] . ' ' . number_format($wert, 1, ',', '.') . ' g/100 g · aus den Zutaten',
                'gehalt' => (float) $wert,
                'vorlaeufig' => false,
                'konflikt' => $anteil < (int) (self::GRENZE_MIN_ABDECKUNG * 100) ? "Nur für {$anteil} % der Masse bekannt." : null,
            ];
        }

        return $out;
    }

    /** Sensorik eines einzelnen Grundprodukts (eigener Roh-Vektor — für ein GP korrekt). */
    public function fuerGp(int $gpId): array
    {
        $out = $this->auswertung([(int) $gpId], 'gp');
        // 27.10.2026: Ohne gespeichertes Profil sind sauer/bitter/umami/scharf NICHT bewertet (0 heißt hier „weiß niemand"),
        // die Anzeige sagt dann „Profil fehlt" statt Schwächen zu melden. Quelle sichtbar (KI / Geschwister / alter Import / Hand).
        $t = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gpId)->first(['source', 'ai_confidence']);
        $out['gp_profil'] = [
            'fehlt' => app(GpAromaService::class)->profilFehlt((int) $gpId),
            'quelle' => $t?->source,
            'confidence' => $t?->ai_confidence !== null ? (float) $t->ai_confidence : null,
        ];
        if ($out['gp_profil']['fehlt']) {
            $out['luecken'] = [];                    // unbewertet ist keine Schwäche
        }

        return $out;
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
     * @return array<int, array<string, array{wert: float, gehalt: float, anzahl: int, basis: string}>> [gp_id][dim]
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
                    'wert' => $this->kurve($g, self::KURVE[$dim]),     // Konzentration ohne Maskierung — nur Hinweis, nie das Profil
                    'gehalt' => $g,                                    // g/100 g, der Fakt
                    'anzahl' => $anzahl[$m['groesse']],
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
     * Plausibilitätsgrenzen anwenden (siehe {@see self::GRENZEN}). `$gehalt` = Nährwerte g/100 g je Achse
     * (`salzig` = Salz, `suess` = Zucker, `fettig` = Fett); Achsen ohne Gehalt bleiben unberührt.
     *
     * @param  array<string, float>  $werte
     * @param  array<string, float>  $gehalt
     * @return array{werte: array<string, float>, eingriffe: list<string>}
     */
    public function begrenze(array $werte, array $gehalt): array
    {
        $nf = fn (float $x, int $n = 2) => number_format($x, $n, ',', '.');
        $eingriffe = [];
        foreach (self::GRENZEN as $dim => $g) {
            if (! isset($gehalt[$dim], $werte[$dim])) {
                continue;
            }
            $wert = (float) $werte[$dim];
            $neu = $wert;
            if ($gehalt[$dim] >= $g['unter']['ab'] && $wert < $g['unter']['min']) {
                $neu = $g['unter']['min'];
            } elseif ($g['ober'] !== null && $gehalt[$dim] < $g['ober']['unter'] && $wert > $g['ober']['max']) {
                $neu = $g['ober']['max'];
            }
            if ($neu !== $wert) {
                $werte[$dim] = $neu;
                $eingriffe[] = '[Grenze: ' . strtolower(self::DIM_LABEL[$dim]) . ' ' . $nf($wert) . ' → ' . $nf($neu)
                    . ', ' . self::MESSBAR[$dim]['label'] . ' ' . $nf($gehalt[$dim], 1) . ' g/100 g]';
            }
        }

        return ['werte' => $werte, 'eingriffe' => $eingriffe];
    }

    /**
     * Effektiver Roh-Vektor je GP. Seit 29.10.2026 gilt: das gespeicherte Profil ist die WAHRNEHMUNG und gewinnt.
     * Die Nährwerte bleiben als Grundlage in `messung[dim]` (Fakt, g/100 g) — überlagern tun sie nichts mehr.
     * Nur OHNE Profil steht die Konzentration vorläufig als Wert (`vorlaeufig`), damit der Roh-Fallback der Rezepte
     * für frische GPs nicht auf 0 fällt.
     *
     * @return array<int, array{
     *     werte: array<string, float>,
     *     messung: array<string, array{basis: string, gehalt: float, vorlaeufig: bool, konflikt: ?string}>,
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
            ->get(array_merge(['gp_id', 'source', 'ai_reasoning'], self::DIMS))->keyBy('gp_id');
        $gemessen = $this->erdungBulk($gpIds);

        $out = [];
        foreach ($gpIds as $gpId) {
            $row = $gespeichert->get($gpId);
            $werte = [];
            foreach (self::DIMS as $d) {
                $werte[$d] = round((float) ($row->{$d} ?? 0), 2);
            }
            // Profil vorhanden = ein Eintrag mit mindestens einem Wert (lauter Nullen ist ein Import-Artefakt) oder von Hand gepflegt.
            $hatProfil = $row !== null && ($row->source === 'manual' || array_sum($werte) > 0);
            $nf = fn (float $x) => number_format($x, 2, ',', '.');
            $messung = [];
            foreach ($gemessen[$gpId] ?? [] as $dim => $m) {
                $konflikt = null;
                if ($hatProfil) {
                    if (str_contains((string) $row->ai_reasoning, '[Grenze:')) {
                        $konflikt = 'Die Wahrnehmung wurde auf die Nährwert-Grenze gesetzt (Begründung am Profil).';
                    } elseif (abs($m['wert'] - $werte[$dim]) >= self::KONFLIKT_AB) {
                        $konflikt = 'Wahrnehmung ' . $nf($werte[$dim]) . ' liegt deutlich von der Konzentration (' . $nf($m['wert'])
                            . ') entfernt — Überdeckung durch andere Zutaten oder ein Altwert.';
                    }
                } else {
                    $werte[$dim] = $m['wert'];                    // vorläufig aus Nährwerten
                }
                $messung[$dim] = ['basis' => $m['basis'], 'gehalt' => $m['gehalt'], 'vorlaeufig' => ! $hatProfil, 'konflikt' => $konflikt];
            }
            $out[$gpId] = [
                'werte' => $werte,
                'messung' => $messung,
                'hat_daten' => $row !== null || $messung !== [],
            ];
        }

        return $out;
    }

    /** Roh-Aggregat eines einzelnen GP (MAX = der eine Vektor selbst) → Montage. */
    private function auswertung(array $gpIds, string $source): array
    {
        if ($gpIds === []) {
            return ['leer' => true];
        }
        $vektoren = $this->vektorenFuerGps($gpIds);

        $geschmack = array_fill_keys(self::DIMS, 0.0);
        $erdung = [];
        $mitDaten = 0;
        foreach ($vektoren as $v) {
            $mitDaten += $v['hat_daten'] ? 1 : 0;
            foreach (self::DIMS as $d) {
                $geschmack[$d] = max($geschmack[$d], $v['werte'][$d]);
            }
        }
        if (count($vektoren) === 1) {
            $erdung = reset($vektoren)['messung'];             // Grundlage nur dort, wo sie eindeutig ist
        }

        return $this->montage($geschmack, $this->texturZeilen($gpIds), $source, null, null,
            ['mit' => $mitDaten, 'gesamt' => count($vektoren)], $erdung);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function texturZeilen(array $gpIds)
    {
        return DB::table('foodalchemist_gp_textures AS t')
            ->join('foodalchemist_vocab_textures AS v', 'v.id', '=', 't.texture_vocab_id')
            ->whereIn('t.gp_id', $gpIds)
            ->selectRaw('v.slug, v.display_de, MAX(t.intensity) AS intensitaet')
            ->groupBy('v.slug', 'v.display_de')->get();
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
            'hinweis' => null,                   // z. B. „grob, ohne Mengen" (Roh-Fallback)
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

        $v = $this->schaetzeRezept($recipeId);
        if ($v === null) {
            return ['status' => 'kein_ergebnis'];   // z. B. Fake-Provider in der Sandbox → nichts schreiben
        }
        $this->speichereRezept($recipeId, $v['geschmack'], $v['texturen'], $hash, $v['confidence'], $v['reasoning']);
        // Spec 60: Texturen fließen ins Aromenprofil — Profil + verwendende Rezepte nachziehen (best-effort)
        try {
            app(Pairing\RezeptProfil::class)->nachziehen($recipeId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Aromenprofil nach Sensorik fehlgeschlagen ({$recipeId}): {$e->getMessage()}");
        }

        return ['status' => 'bewertet', 'geschmack' => $v['geschmack'], 'source' => 'ki', 'eingriffe' => $v['eingriffe']];
    }

    /**
     * KI-Bewertung des gegarten Rezepts OHNE zu speichern (Abnahme-Befehl, {@see bewerteRezept}): Kontext mit Wahrnehmung + Nährwerten,
     * danach die Grenzen auf die Rezept-Nährwerte je 100 g.
     *
     * @return array{geschmack: array<string, float>, texturen: array, eingriffe: list<string>, reasoning: ?string, confidence: ?float, naehrwerte: array}|null
     */
    public function schaetzeRezept(int $recipeId): ?array
    {
        $recipe = DB::table('foodalchemist_recipes')->where('id', $recipeId)->whereNull('deleted_at')->first(['id', 'name', 'preparation', 'description']);
        if ($recipe === null) {
            return null;
        }
        $naehr = $this->rezeptNaehrwerte($recipeId);
        $proposal = app(AiGatewayService::class)->propose('recipe.sensorik', [
            'name' => $recipe->name,
            'zutaten_geerdet' => $this->groundingKontext($recipeId, $naehr),     // Wahrnehmung + Nährwerte als Fakt + Menge(g) + %-Anteil je Zutat
            'preparation' => $recipe->preparation ?: ($recipe->description ?: null),
        ], ['target_table' => 'foodalchemist_recipe_taste_vectors', 'target_id' => $recipeId]);
        $geschmack = $proposal->werte['geschmack'] ?? null;
        if (! is_array($geschmack)) {
            return null;
        }
        // Grenzen nach der Antwort (Dominique 29.10.2026): dieselben belegten Schwellen wie am GP, auf die Rezept-Nährwerte je 100 g.
        $begrenzt = $this->begrenze(array_map('floatval', array_intersect_key($geschmack, array_flip(self::DIMS))), $this->rezeptGehaltFuerGrenzen($naehr));
        $begruendung = trim((string) $proposal->reasoning . ' ' . implode(' ', $begrenzt['eingriffe']));

        return ['geschmack' => $begrenzt['werte'] + array_map('floatval', array_intersect_key($geschmack, array_flip(self::DIMS))),
            'texturen' => is_array($proposal->werte['texturen'] ?? null) ? $proposal->werte['texturen'] : [],
            'eingriffe' => $begrenzt['eingriffe'], 'reasoning' => $begruendung !== '' ? $begruendung : null,
            'confidence' => $proposal->confidence, 'naehrwerte' => $naehr];
    }

    /**
     * Grounding-Kontext für die KI: pro Zutat Wahrnehmungsprofil (GP-Profil bzw. gegartes Profil des Sub-Rezepts) + Nährwerte
     * als FAKT + Menge (g) + %-Anteil am Gesamtgewicht, dazu eine Summenzeile mit den Rezept-Nährwerten je 100 g. Die KI bewertet,
     * wie das fertige Gericht SCHMECKT (Überdeckung, Menge, Zubereitung) — die Nährwerte begründen, sie ersetzen nichts.
     *
     * @param  array{werte: array<string, ?float>, abdeckung: array<string, float>}|null  $naehr
     */
    private function groundingKontext(int $recipeId, ?array $naehr = null): string
    {
        $zeilen = $this->zutatenMitGramm($recipeId);
        $vektoren = $this->vektorenFuerGps(array_column($zeilen, 'gp_id'));
        $total = array_sum(array_column($zeilen, 'gramm'));
        $nf = fn (float $x) => number_format($x, 1, ',', '.');

        $lines = [];
        foreach ($zeilen as $z) {
            if ($z['name'] === '') {
                continue;
            }
            $mengeTxt = $z['gramm'] > 0
                ? ' ' . round($z['gramm']) . 'g' . ($total > 0 ? ' (' . round($z['gramm'] / $total * 100, 1) . '%)' : '')
                : '';
            $dims = [];
            $fakten = [];
            $art = 'roh';
            if ($z['gp_id'] !== null) {
                $v = $vektoren[$z['gp_id']] ?? null;
                foreach (self::DIMS as $d) {
                    $val = round((float) ($v['werte'][$d] ?? 0), 2);
                    if ($val > 0) {
                        $dims[] = self::DIM_LABEL[$d] . ' ' . $val . (($v['messung'][$d]['vorlaeufig'] ?? false) ? ' (vorläufig aus Nährwerten)' : '');
                    }
                }
                foreach ($v['messung'] ?? [] as $d => $m) {
                    $fakten[] = self::MESSBAR[$d]['label'] . ' ' . $nf($m['gehalt']) . ' g/100 g';
                }
            } elseif ($z['ref'] !== null) {
                $sub = $this->fuerRezept($z['ref']);
                $art = ($sub['source'] ?? '') === 'roh' ? 'Sub-Rezept, roh geschätzt' : 'Sub-Rezept, gegart';
                foreach (self::DIMS as $d) {
                    $val = round((float) ($sub['geschmack'][$d] ?? 0), 2);
                    if ($val > 0) {
                        $dims[] = self::DIM_LABEL[$d] . ' ' . $val;
                    }
                }
                $sn = $this->rezeptNaehrwerte($z['ref']);
                foreach ($sn['werte'] as $d => $w) {
                    if ($w !== null) {
                        $fakten[] = self::MESSBAR[$d]['label'] . ' ' . $nf($w) . ' g/100 g';
                    }
                }
            }
            $zeile = '- ' . $z['name'] . $mengeTxt;
            if ($dims !== []) {
                $zeile .= " — {$art}: " . implode(' / ', $dims);
            }
            if ($fakten !== []) {
                $zeile .= ' [Nährwert: ' . implode(', ', $fakten) . ']';
            }
            $lines[] = $zeile;
        }
        $naehr ??= $this->rezeptNaehrwerte($recipeId);
        $summe = [];
        foreach ($naehr['werte'] as $d => $w) {
            if ($w !== null) {
                $summe[] = self::MESSBAR[$d]['label'] . ' ' . $nf($w) . ' g/100 g (für ' . (int) round($naehr['abdeckung'][$d] * 100) . ' % der Masse bekannt)';
            }
        }
        if ($summe !== []) {
            $lines[] = 'Summe Rezept je 100 g Zutatenmasse [Nährwert]: ' . implode(' · ', $summe);
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
