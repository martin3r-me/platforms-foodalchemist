<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Sensorik-Auswertung. ZWEI Quellen, klar getrennt:
 *  • Rezept/Gericht: bevorzugt das KI-bewertete GEGARTE Profil (foodalchemist_recipe_geschmack_vektor
 *    /_textur — eine KI liest Zutaten+Zubereitung; rohe Zwiebel ≠ Schmorzwiebel). Liegt keins vor,
 *    FALLBACK = Roh-Aggregat über die Zutaten-GPs (App-Port der Vault-232-Logik) — klar als „roh
 *    geschätzt" markiert. Manueller Eintrag (quelle='manual') gewinnt immer.
 *  • Grundprodukt: eigener Roh-Vektor (das ist für ein GP korrekt — ein GP ist roh).
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
        $stored = DB::table('foodalchemist_recipe_geschmack_vektor')->where('recipe_id', $recipeId)->first();
        if ($stored !== null) {
            $geschmack = [];
            foreach (self::DIMS as $d) {
                $geschmack[$d] = round((float) ($stored->{$d} ?? 0), 2);
            }
            $texRows = DB::table('foodalchemist_recipe_textur AS t')
                ->join('foodalchemist_vocab_textur AS v', 'v.id', '=', 't.textur_vocab_id')
                ->where('t.recipe_id', $recipeId)
                ->selectRaw('v.slug, v.display_de, MAX(t.intensitaet) AS intensitaet')
                ->groupBy('v.slug', 'v.display_de')->get();

            return $this->montage(
                $geschmack, $texRows,
                $stored->quelle === 'manual' ? 'manual' : 'ki',
                $stored->ai_confidence !== null ? (float) $stored->ai_confidence : null,
                $stored->ai_begruendung,
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
        $recipeIds = $concept->slots->pluck('vk_recipe_id')->filter()->unique()->values()->all();

        return $this->auswertung($this->gpIdsFromRecipes($recipeIds), 'roh');
    }

    /** Roh-Aggregat über eine GP-Menge (MAX je Dimension, wie 232) → Montage. */
    private function auswertung(array $gpIds, string $quelle): array
    {
        if ($gpIds === []) {
            return ['leer' => true];
        }
        $sel = implode(', ', array_map(fn ($d) => "MAX($d) AS $d", self::DIMS));
        $row = DB::table('foodalchemist_gp_geschmack_vektor')->whereIn('gp_id', $gpIds)
            ->selectRaw($sel . ', COUNT(*) AS n')->first();
        $geschmack = [];
        foreach (self::DIMS as $d) {
            $geschmack[$d] = round((float) ($row->{$d} ?? 0), 2);
        }
        $texRows = DB::table('foodalchemist_gp_textur AS t')
            ->join('foodalchemist_vocab_textur AS v', 'v.id', '=', 't.textur_vocab_id')
            ->whereIn('t.gp_id', $gpIds)
            ->selectRaw('v.slug, v.display_de, MAX(t.intensitaet) AS intensitaet')
            ->groupBy('v.slug', 'v.display_de')->get();

        return $this->montage($geschmack, $texRows, $quelle, null, null,
            ['mit' => (int) ($row->n ?? 0), 'gesamt' => count($gpIds)]);
    }

    /**
     * Gemeinsame Ergebnis-Montage — Grundgeschmack als reine DIAGNOSE (Dominanz/Lücke/Textur/
     * Monotonie). Kontrast- und Komplettierungs-Vorschläge liefert der Anker-Graph
     * (PairingService, kontrast/klassisch-Kanten) — nicht diese Schicht. Daher kein SKU-Vorschlag hier.
     */
    private function montage(array $geschmack, $texRows, string $quelle, ?float $conf, ?string $begr, ?array $abdeckung = null): array
    {
        $dominant = array_keys(array_filter($geschmack, fn ($v) => $v >= 0.6));
        $luecken = array_keys(array_filter($geschmack, fn ($v) => $v < 0.3));

        $textur = $texRows->sortByDesc('intensitaet')
            ->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->display_de])->values()->all();
        $slugs = array_column($textur, 'slug');
        $weichN = count(array_intersect($slugs, self::WEICH));
        $hatCrunch = count(array_intersect($slugs, self::KNUSPRIG)) > 0;
        $monotonie = ($weichN >= 2 && ! $hatCrunch)
            ? 'Überwiegend weich/cremig, kein knuspriger Kontrast — ein Crunch-Element würde den Teller heben.'
            : null;

        return [
            'leer' => false,
            'quelle' => $quelle,                 // ki | manual | roh | gp
            'confidence' => $conf,
            'begruendung' => $begr,
            'abdeckung' => $abdeckung,           // nur Roh-Pfad (GP-Coverage); KI-Pfad = null
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
     * Skip-if-unchanged über source_hash; manueller Eintrag (quelle='manual') gewinnt.
     *
     * @return array{status: string, geschmack?: array, quelle?: string}
     */
    public function bewerteRezept(int $recipeId, bool $force = false): array
    {
        $recipe = DB::table('foodalchemist_recipes')->where('id', $recipeId)->whereNull('deleted_at')
            ->first(['id', 'name', 'zubereitung', 'beschreibung']);
        if ($recipe === null) {
            return ['status' => 'kein_rezept'];
        }

        $zutaten = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->selectRaw('COALESCE(g.name, sr.name, ri.raw_text) AS name')->pluck('name')
            ->filter()->values()->all();

        $signatur = mb_strtolower(trim($recipe->name) . '|' . implode(',', $zutaten) . '|' . trim((string) $recipe->zubereitung));
        $hash = hash('sha256', $signatur);

        $stored = DB::table('foodalchemist_recipe_geschmack_vektor')->where('recipe_id', $recipeId)->first();
        if ($stored !== null && ! $force) {
            if ($stored->quelle === 'manual') {
                return ['status' => 'manual_geschuetzt'];
            }
            if ($stored->source_hash === $hash) {
                return ['status' => 'unveraendert'];
            }
        }

        $proposal = app(AiGatewayService::class)->propose('recipe.sensorik', [
            'name' => $recipe->name,
            'zutaten_geerdet' => $this->groundingKontext($recipeId),     // Roh-Vektor + Menge(g) + %-Anteil je Zutat
            'zubereitung' => $recipe->zubereitung ?: ($recipe->beschreibung ?: null),
        ], ['target_table' => 'foodalchemist_recipe_geschmack_vektor', 'target_id' => $recipeId]);

        $werte = $proposal->werte;
        $geschmack = $werte['geschmack'] ?? null;
        if (! is_array($geschmack)) {
            return ['status' => 'kein_ergebnis'];   // z. B. Fake-Provider in der Sandbox → nichts schreiben
        }
        $this->speichereRezept($recipeId, $geschmack, $werte['texturen'] ?? [], $hash, $proposal->confidence, $proposal->begruendung);

        return ['status' => 'bewertet', 'geschmack' => $geschmack, 'quelle' => 'ki'];
    }

    /**
     * Grounding-Kontext für die KI: pro Zutat ROH-Profil (GP-Vektor) + Menge (g) + %-Anteil am
     * Gesamtgewicht. Damit kennt die KI die Fakten (was, wie viel) und wendet nur die Zubereitung
     * als Transformation an — statt aus dem Namen zu raten.
     */
    private function groundingKontext(int $recipeId): string
    {
        $rows = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'ri.referenced_recipe_id')
            ->leftJoin('foodalchemist_vocab_einheiten AS e', 'e.id', '=', 'ri.einheit_vocab_id')
            ->leftJoin('foodalchemist_gp_geschmack_vektor AS v', 'v.gp_id', '=', 'ri.gp_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->orderBy('ri.position')
            ->get(['ri.menge', 'e.default_in_g', 'e.default_in_ml',
                DB::raw('COALESCE(g.name, sr.name, ri.raw_text) AS name'),
                'v.suess', 'v.salzig', 'v.sauer', 'v.bitter', 'v.umami', 'v.fettig', 'v.scharf']);

        $gramm = [];
        $total = 0.0;
        foreach ($rows as $i => $r) {
            $g = $r->menge !== null ? (float) $r->menge * (float) ($r->default_in_g ?? $r->default_in_ml ?? 0) : 0.0;
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
            $dims = [];
            foreach (self::DIMS as $d) {
                $val = round((float) ($r->{$d} ?? 0), 2);
                if ($val > 0) {
                    $dims[] = self::DIM_LABEL[$d] . ' ' . $val;
                }
            }
            $roh = $dims !== [] ? ' — roh: ' . implode(' / ', $dims) : '';
            $lines[] = '- ' . $r->name . $mengeTxt . $roh;
        }

        return implode("\n", $lines);
    }

    /** Persistiert ein gegartes Profil (Geschmack upsert + Textur ersetzen, quelle='ai'). */
    private function speichereRezept(int $recipeId, array $geschmack, array $texturen, string $hash, ?float $conf, ?string $begr): void
    {
        $clamp = fn ($x) => max(0.0, min(1.0, round((float) $x, 2)));
        $row = ['recipe_id' => $recipeId, 'quelle' => 'ai', 'source_hash' => $hash,
            'ai_confidence' => $conf, 'ai_begruendung' => $begr, 'updated_at' => now()];
        foreach (self::DIMS as $d) {
            $row[$d] = $clamp($geschmack[$d] ?? 0);
        }
        DB::table('foodalchemist_recipe_geschmack_vektor')->updateOrInsert(
            ['recipe_id' => $recipeId], $row + ['created_at' => now()],
        );

        // Textur: nur KI-Zeilen ersetzen (manuelle bleiben), dann neu setzen
        $vocab = DB::table('foodalchemist_vocab_textur')->pluck('id', 'slug');
        DB::table('foodalchemist_recipe_textur')->where('recipe_id', $recipeId)->where('quelle', 'ai')->delete();
        foreach ($texturen as $t) {
            $slug = $t['slug'] ?? null;
            if ($slug === null || ! $vocab->has($slug)) {
                continue;
            }
            DB::table('foodalchemist_recipe_textur')->updateOrInsert(
                ['recipe_id' => $recipeId, 'textur_vocab_id' => $vocab[$slug]],
                ['intensitaet' => $clamp($t['intensitaet'] ?? 1), 'quelle' => 'ai', 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
