<?php

namespace Platform\FoodAlchemist\Services\Regeln;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Spec 81 Teil G — Probelauf: eine (geänderte) Regel gegen den Bestand, Vergleich mit der gespeicherten Fassung.
 * Rein lesend. „Treffer" = die Regel meldet einen Befund, korrigiert etwas oder ordnet zu.
 */
final class RegelProbelauf
{
    private const MAX_ZEILEN = 30000;

    private const MAX_BEISPIELE = 40;

    public function __construct(private RegelMotor $motor)
    {
    }

    /**
     * @return array{bestand: string, geprueft: int, vorher: int, nachher: int, neu_betroffen: int, nicht_mehr: int,
     *     beispiele: list<array{text: string, ergebnis: string, neu: bool}>, pruefung: list<string>}
     */
    public function vergleiche(FoodAlchemistRule $alt, FoodAlchemistRule $neu): array
    {
        [$bestand, $zeilen] = $this->bestand($neu->ziel);
        $vorher = [];
        $nachher = [];
        $beispiele = [];
        foreach ($zeilen as $i => [$text, $kontext]) {
            $a = $this->ergebnis($alt, $text, $kontext);
            $n = $this->ergebnis($neu, $text, $kontext);
            if ($a !== null) {
                $vorher[$i] = true;
            }
            if ($n !== null) {
                $nachher[$i] = true;
                if (count($beispiele) < self::MAX_BEISPIELE) {
                    $beispiele[] = ['text' => $text, 'ergebnis' => $n, 'neu' => $a === null];
                }
            }
        }
        usort($beispiele, static fn ($x, $y) => $y['neu'] <=> $x['neu']);

        return [
            'bestand' => $bestand,
            'geprueft' => count($zeilen),
            'vorher' => count($vorher),
            'nachher' => count($nachher),
            'neu_betroffen' => count(array_diff_key($nachher, $vorher)),
            'nicht_mehr' => count(array_diff_key($vorher, $nachher)),
            'beispiele' => $beispiele,
            'pruefung' => $this->motor->validiere($neu),
        ];
    }

    private function ergebnis(FoodAlchemistRule $r, string $text, array $kontext): ?string
    {
        try {
            if ($r->art === 'zuordnung') {
                /** @var Arten\Zuordnung $z */
                $z = $this->motor->art('zuordnung');
                $e = $z->finde($r, $text, $kontext);

                return $e === null ? null : '→ ' . $e['ziel_name'];
            }
            $art = $this->motor->art($r->art);
            if ($art->kannKorrigieren() && ($k = $art->korrigiere($r, $text, $kontext)) !== null) {
                return '→ ' . $k;
            }
            $b = $art->pruefe($r, $text, $kontext);

            return $b === [] ? null : $b[0]['grund'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function vkNamen(): \Illuminate\Support\Collection
    {
        return DB::table('foodalchemist_recipes')->whereNull('deleted_at')->where('is_sales_recipe', true)->limit(self::MAX_ZEILEN)->pluck('name')->map(fn ($n) => (string) $n);
    }

    /** @return array{0: string, 1: list<array{0: string, 1: array<string, mixed>}>} */
    private function bestand(string $ziel): array
    {
        $gps = fn () => DB::table('foodalchemist_gps')->whereNull('deleted_at')->limit(self::MAX_ZEILEN)
            ->get(['name', 'condition', 'processing', 'form']);
        $attribut = static fn (string $n) => str_contains($n, ':') ? trim(substr($n, strpos($n, ':') + 1)) : '';

        return match ($ziel) {
            'gp.name', 'matching.kandidat' => ['Grundprodukte (Name)', $gps()->map(fn ($g) => [(string) $g->name, ['zustand' => $g->condition,
                'hauptzutat' => trim((string) strstr($g->name . ':', ':', true))]])->all()],
            'gp.verarbeitung' => ['Grundprodukte (Zuschnitt/Verarbeitung)', $gps()->map(fn ($g) => [
                trim($g->processing . ' ' . $g->form . ' ' . $attribut((string) $g->name)), ['zustand' => $g->condition]])->all()],
            'gp.attribut' => ['Grundprodukte (Angaben nach dem Doppelpunkt)', $gps()->map(fn ($g) => [$attribut((string) $g->name), ['zustand' => $g->condition]])->all()],
            'rezept.name' => ['Basisrezepte (Typ-Präfix)', DB::table('foodalchemist_recipes')->whereNull('deleted_at')->where('is_sales_recipe', false)
                ->limit(self::MAX_ZEILEN)->pluck('name')
                ->map(fn ($n) => RezeptTypVokabular::praefix((string) $n))->filter()->map(fn ($p) => [(string) $p, []])->values()->all()],
            'rezept.beschreibung' => ['Basisrezepte (Sätze der Beschreibung)', DB::table('foodalchemist_recipes')->whereNull('deleted_at')
                ->where('is_sales_recipe', false)->whereNotNull('description')->where('description', '!=', '')->limit(self::MAX_ZEILEN)->pluck('description')
                ->map(fn ($d) => [(string) count(array_filter(preg_split('/(?<=[.!?])\s+/u', trim((string) $d)) ?: [], fn ($t) => trim($t) !== '')), []])->all()],
            'rezeptzeile' => ['Rezeptzeilen (Zutatentext)', DB::table('foodalchemist_recipe_ingredients')->whereNotNull('raw_text')
                ->distinct()->limit(self::MAX_ZEILEN)->pluck('raw_text')->map(fn ($t) => [(string) $t, ['prefer_raw' => 'nein']])->all()],
            'vk.name' => ['Verkaufsgerichte (Name)', $this->vkNamen()->map(fn ($n) => [$n, []])->all()],
            'vk.name.hg' => ['Verkaufsgerichte (Kürzel)', $this->vkNamen()->map(fn ($n) => preg_match('/^\[([^\]]+)\]/u', $n, $m) === 1 ? [$m[1], []] : null)->filter()->values()->all()],
            'vk.name.bausteine' => ['Verkaufsgerichte (Bausteine)', $this->vkNamen()->map(fn ($n) => [(string) count(array_filter(array_map('trim',
                explode('|', (string) preg_replace('/^\[[^\]]+\]\s*/u', '', $n))))), []])->all()],
            'rezept.name.grammatur' => ['Rezepte und Gerichte (Name)', DB::table('foodalchemist_recipes')->whereNull('deleted_at')->limit(self::MAX_ZEILEN)
                ->pluck('name')->map(fn ($n) => [(string) $n, []])->all()],
            'rezept.schritt' => ['Zubereitungsschritte', DB::table('foodalchemist_recipe_steps')->whereNotNull('text')->limit(self::MAX_ZEILEN)
                ->pluck('text')->map(fn ($t) => [(string) $t, []])->all()],
            'rezeptzeile.vegan', 'rezeptzeile.vegetarisch' => ['Zeilen ' . ($ziel === 'rezeptzeile.vegan' ? 'veganer' : 'vegetarischer') . ' Rezepte',
                DB::table('foodalchemist_recipe_ingredients as z')->join('foodalchemist_recipes as r', 'r.id', '=', 'z.recipe_id')
                    ->leftJoin('foodalchemist_gps as g', 'g.id', '=', 'z.gp_id')->whereNull('r.deleted_at')
                    ->where($ziel === 'rezeptzeile.vegan' ? 'r.spec_is_vegan' : 'r.spec_is_vegetarian', true)->limit(self::MAX_ZEILEN)
                    ->selectRaw('COALESCE(g.name, z.raw_text) as t')->pluck('t')->filter()->map(fn ($t) => [(string) $t, []])->all()],
            default => ['Kein Bestand für dieses Ziel', []],
        };
    }
}
