<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\Verfahren;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SensorikService;

/**
 * Spec 60 · P5: Aromenprofil eines Basisrezepts (Idee Dominique 2026-10-06).
 *
 * Ein Basisrezept wird zu einem eigenen Profil aus Kern-Ankern mit prozentualem Anteil:
 *
 *   Aromamasse je Zutat = Gramm × Aroma-Intensität des Ankers × Rollen-Gewicht
 *                         (Gramm nach der T1-Kaskade von Yield/Kosten, {@see RecipeRecomputeService::grammJeZeile})
 *   Unterrezept         = sein eigenes Profil, anteilig nach der eingesetzten Menge (max. 3 Ebenen)
 *   Profil              = Aromamassen je Anker, normiert auf 100 %; Anker < 5 % fallen weg, neu normiert
 *
 * Zubereitung wählt die Inspire-Variante: steht im Zutatentext „geröstet" und führt Inspire
 * „Kürbis, im Ofen geröstet", zählt die Variante (dort gemessen). Das Verfahren aus dem
 * Rezeptnamen („Geröstete Bundmöhren") gilt nur für die mengenmäßig stärkste Zutat.
 *
 * Dazu je Rezept:
 *   abdeckung       Anteil der Rezeptmasse mit Anker (ehrliche Aussagekraft)
 *   eigenschaften   je Achse die Stufe 0–3: aus den Ankern (Stufe × min(1, Anteil/10 %)) und aus
 *                   der Sensorik (Süße, Salz, Fett aus Nährwerten) — die höhere gilt
 *   offene_bedarfe  Bedarfe der Kern-Anker (≥ 10 %), die das Rezept selbst nicht deckt (Stufe < 2)
 *
 * Materialisiert mit Quell-Hash: {@see fuer} rechnet, vergleicht und schreibt nur bei Änderung.
 */
final class RezeptProfil
{
    public const SCHWELLE_ANTEIL = 5.0;

    public const KERN_FUER_BEDARF = 10.0;

    public const VOLL_AB_ANTEIL = 10.0;

    private const REKURSION_MAX = 3;

    /** Sensorik-Dimension → Achse; nur die aus Nährwerten belegten. */
    private const SENSORIK_BELEGT = ['suess' => 'suesse', 'salzig' => 'salz', 'fettig' => 'fett'];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly PairingService $pairing,
        private readonly SensorikService $sensorik,
        private readonly RecipeRecomputeService $recompute,
    ) {}

    /**
     * Profil eines Rezepts (gespeichert, bei Änderung neu gebaut).
     *
     * @return array{recipe_id: int, anker: list<array{anchor_id: int, anteil: float, verfahren: ?string}>, abdeckung: float, eigenschaften: array<string, array{stufe: float, quelle: string}>, offene_bedarfe: list<array{achse: string, staerke: string, von: int}>, roh: array<int, float>, masse: float}
     */
    public function fuer(int $recipeId, int $tiefe = 1, array $besucht = []): array
    {
        if (isset($this->cache[$recipeId])) {
            return $this->cache[$recipeId];
        }
        $p = $this->rechne($recipeId, $tiefe, $besucht);
        // Abgeschnitten (Zyklus, Tiefe > 3) oder Rezept fehlt: nie speichern, nie cachen —
        // sonst überschriebe ein leeres Teilergebnis das echte Profil.
        if ($p['vollstaendig']) {
            $this->speichere($p);
            $this->cache[$recipeId] = $p;
        }

        return $p;
    }

    /**
     * Profil eines einzeln eingesetzten Grundprodukts bzw. Ankers (Gericht ohne Basisrezept):
     * derselbe Aufbau wie ein Rezept-Profil, mit 100 % des (ggf. Varianten-)Ankers.
     *
     * @return array{anker: list<array{anchor_id: int, anteil: float, verfahren: ?string}>, abdeckung: float, eigenschaften: array<string, array{stufe: float, quelle: string}>, offene_bedarfe: list<array{achse: string, staerke: string, von: int}>}
     */
    public function einzel(int $anker, ?Verfahren $verfahren = null): array
    {
        $anker = $this->variante($anker, $verfahren);
        $anteile = [$anker => 100.0];
        $eigenschaften = [];
        foreach (DB::table('foodalchemist_anchor_eigenschaften')->where('anchor_id', $anker)
            ->where('status', '!=', WissensStatus::Verworfen->value)->get(['achse', 'stufe', 'quelle']) as $e) {
            if ((float) $e->stufe > ($eigenschaften[$e->achse]['stufe'] ?? -1)) {
                $eigenschaften[$e->achse] = ['stufe' => (float) $e->stufe, 'quelle' => (string) $e->quelle];
            }
        }

        return [
            'anker' => [['anchor_id' => $anker, 'anteil' => 100.0, 'verfahren' => $verfahren?->value]],
            'abdeckung' => 100.0,
            'eigenschaften' => $eigenschaften,
            'offene_bedarfe' => $this->offeneBedarfe($anteile, $eigenschaften),
        ];
    }

    /** Für Tests und Neuaufbau: Speicher-Cache leeren. */
    public function vergiss(): void
    {
        $this->cache = [];
    }

    /** @return array<string, mixed> */
    private function rechne(int $recipeId, int $tiefe, array $besucht): array
    {
        $leer = ['recipe_id' => $recipeId, 'anker' => [], 'abdeckung' => 0.0, 'eigenschaften' => [], 'offene_bedarfe' => [], 'roh' => [], 'masse' => 0.0, 'hash_teile' => [], 'vollstaendig' => false];
        if ($tiefe > self::REKURSION_MAX || isset($besucht[$recipeId])) {
            return $leer;
        }
        $besucht[$recipeId] = true;

        $rezept = DB::table('foodalchemist_recipes')->where('id', $recipeId)->first(['id', 'name']);
        $zeilen = FoodAlchemistRecipeIngredient::query()->with(['unit', 'gp', 'referencedRecipe'])
            ->where('recipe_id', $recipeId)->where('is_optional', false)
            ->orderBy('position')->orderBy('id')->get();
        if ($rezept === null) {
            return $leer;
        }
        if ($zeilen->isEmpty()) {
            return ['vollstaendig' => true, 'eigenschaften' => $this->eigenschaften($recipeId, [])] + $leer;
        }

        $gpKerne = $this->pairing->gpKernAnker($zeilen->pluck('gp_id')->filter()->all());
        $neutral = $this->pairing->neutralAnker();
        $namensVerfahren = Verfahren::ausText((string) $rezept->name);
        $gramm = $zeilen->mapWithKeys(fn ($z) => [$z->id => $this->recompute->grammJeZeile($z)]);
        $staerksteZeile = $gramm->sortDesc()->keys()->first();

        $roh = [];          // anchor_id => Aromamasse
        $verfahrenJe = [];  // anchor_id => Verfahren
        $masse = 0.0;
        $masseMitAnker = 0.0;
        $hash = [];
        foreach ($zeilen as $z) {
            $g = (float) $gramm[$z->id];
            $rolle = PairingService::ROLLEN_GEWICHT[$z->role ?? ''] ?? 1.0;
            $masse += $g;
            if ($z->referenced_recipe_id !== null) {
                $sub = $this->fuer((int) $z->referenced_recipe_id, $tiefe + 1, $besucht);
                $hash[] = ['sub', (int) $z->referenced_recipe_id, $g, $rolle, $sub['hash_teile'] ?? []];
                if ($sub['masse'] <= 0 || $sub['roh'] === []) {
                    continue;
                }
                $faktor = $g / $sub['masse'] * $rolle;
                foreach ($sub['roh'] as $aid => $wert) {
                    $roh[$aid] = ($roh[$aid] ?? 0) + $wert * $faktor;
                }
                $masseMitAnker += $g * min(1.0, $sub['abdeckung'] / 100);

                continue;
            }

            $text = (string) ($z->display_name ?: $z->raw_text ?: '');
            $gpName = $z->gp?->name;
            $anker = $z->gp_id !== null
                ? ($gpKerne[$z->gp_id] ?? $this->pairing->ankerIdExakt($gpName))
                : $this->pairing->ankerIdExakt($z->raw_text);
            $anker = $anker !== null ? (int) $anker : null;
            $verfahren = Verfahren::ausText($text) ?? Verfahren::ausText((string) strstr((string) $gpName, ':'))
                ?? ($z->id === $staerksteZeile ? $namensVerfahren : null);
            $hash[] = ['z', $z->gp_id, $z->raw_text, $g, $rolle, $anker, $verfahren?->value];
            if ($anker === null || $anker === $neutral) {
                continue;
            }
            $anker = $this->variante($anker, $verfahren);
            $intensitaet = $this->intensitaet($anker);
            $masseMitAnker += $g;
            if ($intensitaet <= 0) {
                continue;
            }
            $roh[$anker] = ($roh[$anker] ?? 0) + $g * $intensitaet * $rolle;
            $verfahrenJe[$anker] = $verfahren?->value ?? ($verfahrenJe[$anker] ?? null);
        }

        $anteile = $this->normiere($roh);
        $eigenschaften = $this->eigenschaften($recipeId, $anteile);
        $offen = $this->offeneBedarfe($anteile, $eigenschaften);

        return [
            'recipe_id' => $recipeId,
            'anker' => array_map(fn ($aid, $a) => ['anchor_id' => $aid, 'anteil' => $a, 'verfahren' => $verfahrenJe[$aid] ?? null],
                array_keys($anteile), array_values($anteile)),
            'abdeckung' => $masse > 0 ? round(100 * $masseMitAnker / $masse, 2) : 0.0,
            'eigenschaften' => $eigenschaften,
            'offene_bedarfe' => $offen,
            'roh' => $roh,
            'masse' => $masse,
            'hash_teile' => [$hash, $anteile, $eigenschaften, $offen],
            'vollstaendig' => true,
        ];
    }

    /**
     * @param  array<int, float>  $roh
     * @return array<int, float> anchor_id → Anteil in %, absteigend
     */
    private function normiere(array $roh): array
    {
        $summe = array_sum($roh);
        if ($summe <= 0) {
            return [];
        }
        $anteile = array_map(fn ($w) => 100 * $w / $summe, $roh);
        $anteile = array_filter($anteile, fn ($a) => $a >= self::SCHWELLE_ANTEIL);
        $summe = array_sum($anteile);
        $out = array_map(fn ($a) => round(100 * $a / $summe, 2), $anteile);
        arsort($out);

        return $out;
    }

    /**
     * @param  array<int, float>  $anteile
     * @return array<string, array{stufe: float, quelle: string}>
     */
    private function eigenschaften(int $recipeId, array $anteile): array
    {
        $out = [];
        if ($anteile !== []) {
            foreach (DB::table('foodalchemist_anchor_eigenschaften')->whereIn('anchor_id', array_keys($anteile))
                ->where('status', '!=', WissensStatus::Verworfen->value)->get(['anchor_id', 'achse', 'stufe', 'quelle']) as $e) {
                $wert = round((float) $e->stufe * min(1.0, $anteile[(int) $e->anchor_id] / self::VOLL_AB_ANTEIL), 2);
                if ($wert > ($out[$e->achse]['stufe'] ?? -1)) {
                    $out[$e->achse] = ['stufe' => $wert, 'quelle' => (string) $e->quelle];
                }
            }
        }
        $sensorik = $this->sensorik->fuerRezept($recipeId)['geschmack'] ?? [];
        foreach (self::SENSORIK_BELEGT as $dim => $achse) {
            $wert = round(3 * (float) ($sensorik[$dim] ?? 0), 2);
            if ($wert > 0 && $wert > ($out[$achse]['stufe'] ?? -1)) {
                $out[$achse] = ['stufe' => $wert, 'quelle' => 'sensorik'];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<int, float>  $anteile
     * @param  array<string, array{stufe: float, quelle: string}>  $eigenschaften
     * @return list<array{achse: string, staerke: string, von: int}>
     */
    private function offeneBedarfe(array $anteile, array $eigenschaften): array
    {
        $kern = array_keys(array_filter($anteile, fn ($a) => $a >= self::KERN_FUER_BEDARF));
        if ($kern === []) {
            return [];
        }
        $offen = [];
        foreach (DB::table('foodalchemist_anchor_bedarfe')->whereIn('anchor_id', $kern)
            ->where('status', '!=', WissensStatus::Verworfen->value)
            ->orderByRaw("CASE staerke WHEN 'muss' THEN 0 ELSE 1 END")->orderBy('achse')
            ->get(['anchor_id', 'achse', 'staerke']) as $b) {
            if (Achse::tryFrom((string) $b->achse) === null || ($eigenschaften[$b->achse]['stufe'] ?? 0) >= 2) {
                continue;
            }
            // je Achse einmal — der stärkste Bedarf zählt
            if (! isset($offen[$b->achse]) || ($b->staerke === 'muss' && $offen[$b->achse]['staerke'] !== 'muss')) {
                $offen[$b->achse] = ['achse' => (string) $b->achse, 'staerke' => (string) $b->staerke, 'von' => (int) $b->anchor_id];
            }
        }

        return array_values($offen);
    }

    /** Inspire-Variante zum Verfahren, falls vorhanden (gleicher Grundname bzw. Grund-Anker). */
    private function variante(int $anker, ?Verfahren $verfahren): int
    {
        if ($verfahren === null || $verfahren === Verfahren::Roh) {
            return $anker;
        }
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker)->first(['grundname', 'verfahren']);
        if ($a === null || $a->grundname === null || $a->verfahren === $verfahren->value) {
            return $anker;
        }
        $treffer = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('grundname', $a->grundname)->where('verfahren', $verfahren->value)->orderBy('id')->pluck('id');

        return $treffer->count() === 1 ? (int) $treffer->first() : $anker;
    }

    private function intensitaet(int $anker): float
    {
        $wert = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $anker)->value('aroma_intensitaet');

        return $wert !== null ? (float) $wert : 1.0;
    }

    /** @param  array<string, mixed>  $p */
    private function speichere(array $p): void
    {
        $hash = hash('sha256', json_encode($p['hash_teile']));
        $alt = DB::table('foodalchemist_recipe_profile')->where('recipe_id', $p['recipe_id'])->value('quelle_hash');
        if ($alt === $hash) {
            return;
        }
        DB::transaction(function () use ($p, $hash, $alt) {
            $daten = ['abdeckung' => $p['abdeckung'], 'eigenschaften' => json_encode($p['eigenschaften']),
                'offene_bedarfe' => json_encode($p['offene_bedarfe']), 'quelle_hash' => $hash, 'updated_at' => now()];
            if ($alt === null) {
                DB::table('foodalchemist_recipe_profile')->insert($daten + ['recipe_id' => $p['recipe_id'], 'created_at' => now()]);
            } else {
                DB::table('foodalchemist_recipe_profile')->where('recipe_id', $p['recipe_id'])->update($daten);
            }
            DB::table('foodalchemist_recipe_profile_anker')->where('recipe_id', $p['recipe_id'])->delete();
            if ($p['anker'] !== []) {
                DB::table('foodalchemist_recipe_profile_anker')->insert(array_map(
                    fn ($a) => $a + ['recipe_id' => $p['recipe_id']], $p['anker']));
            }
        });
    }
}
