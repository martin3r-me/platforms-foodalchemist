<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;

/**
 * Messung vorher/nachher für K1 + K4a (Kurator für Dominique, 29.10.2026). NUR LESEND: jede Rechnung läuft in einer Transaktion,
 * die zurückgerollt wird (RezeptProfil::fuer speichert sonst nebenbei). Nach dem Deploy und VOR `foodalchemist:rezept-profile --alle`
 * laufen lassen — „vorher" ist das gespeicherte Profil.
 *
 *   Liefert (K4a)   gespeicherte Eigenschaften vs. frisch gerechnet, Geschmacksachsen mit Stufe ≥ 2
 *   Lücken  (K1)    offene Bedarfe der Kombination ohne Rezept-Deckung vs. mit („Es fehlt" bzw. „Passt als Kontrast")
 */
class RezeptLueckenVergleichCommand extends Command
{
    protected $signature = 'foodalchemist:rezept-luecken-vergleich
        {--rezept=* : nur diese recipe_ids}
        {--limit=0 : höchstens so viele Rezepte (0 = alle mit gespeichertem Profil)}
        {--csv= : optional: Datei für die Rezepte mit Änderung}';

    protected $description = 'Messung (nur lesend): wie viele Rezepte ändern „Liefert" (K4a) und ihre Lücken (K1)';

    private const GESCHMACK = ['suesse', 'salz', 'fett', 'saeure', 'bitter', 'umami', 'schaerfe'];

    public function handle(RezeptProfil $profil, Kombinationslogik $logik): int
    {
        $q = DB::table('foodalchemist_recipe_profile AS p')->join('foodalchemist_recipes AS r', 'r.id', '=', 'p.recipe_id')
            ->whereNull('r.deleted_at')->orderBy('p.recipe_id');
        if ($this->option('rezept') !== []) {
            $q->whereIn('p.recipe_id', array_map('intval', (array) $this->option('rezept')));
        }
        if ((int) $this->option('limit') > 0) {
            $q->limit((int) $this->option('limit'));
        }
        $zeilen = $q->get(['p.recipe_id', 'p.eigenschaften', 'r.name', 'r.is_sales_recipe']);

        $n = ['rezepte' => 0, 'liefert' => 0, 'luecken' => 0];
        $wegLiefert = $neuLiefert = $wegLuecke = [];
        $csv = [];
        foreach ($zeilen as $z) {
            $alt = $this->liefert((array) json_decode((string) $z->eigenschaften, true));
            DB::beginTransaction();
            try {
                $profil->vergiss();
                $neu = $this->liefert($profil->berechne((int) $z->recipe_id)['eigenschaften'] ?? []);
                $rezept = FoodAlchemistRecipe::find($z->recipe_id);
                $ohne = $rezept !== null ? array_column($logik->analysiereBestandteile($logik->bestandteile($rezept))['offene_bedarfe'], 'achse') : [];
                $mit = $rezept !== null ? array_column($logik->analysiere($rezept)['offene_bedarfe'], 'achse') : [];
            } finally {
                DB::rollBack();
                $profil->vergiss();
            }
            $n['rezepte']++;
            $aenderungLiefert = $alt !== $neu;
            $weg = array_values(array_diff($ohne, $mit));
            $n['liefert'] += $aenderungLiefert ? 1 : 0;
            $n['luecken'] += $weg !== [] ? 1 : 0;
            foreach (array_diff($alt, $neu) as $a) {
                $wegLiefert[$a] = ($wegLiefert[$a] ?? 0) + 1;
            }
            foreach (array_diff($neu, $alt) as $a) {
                $neuLiefert[$a] = ($neuLiefert[$a] ?? 0) + 1;
            }
            foreach ($weg as $a) {
                $wegLuecke[$a] = ($wegLuecke[$a] ?? 0) + 1;
            }
            if ($aenderungLiefert || $weg !== []) {
                $csv[] = [$z->recipe_id, $z->name, $z->is_sales_recipe ? 'gericht' : 'basisrezept', implode(',', $alt), implode(',', $neu),
                    implode(',', $ohne), implode(',', $mit)];
            }
        }

        $label = fn (array $x) => implode(', ', array_map(fn ($a, $c) => (Achse::tryFrom($a)?->label() ?? $a) . " {$c}", array_keys($x), $x)) ?: '—';
        $this->table(['', 'Anzahl'], [
            ['Rezepte geprüft', $n['rezepte']],
            ['„Liefert" geändert (K4a, Geschmacksachsen ≥ Stufe 2)', $n['liefert']],
            ['Lücken weggefallen durch Rezept-Deckung (K1)', $n['luecken']],
        ]);
        $this->line('Liefert nicht mehr: ' . $label($wegLiefert));
        $this->line('Liefert neu: ' . $label($neuLiefert));
        $this->line('Lücke gedeckt: ' . $label($wegLuecke));
        if (($pfad = (string) $this->option('csv')) !== '') {
            File::ensureDirectoryExists(dirname($pfad));
            $h = fopen($pfad, 'wb');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['recipe_id', 'name', 'art', 'liefert_vorher', 'liefert_nachher', 'luecken_ohne_rezept', 'luecken_mit_rezept'], ';', '"', '');
            foreach ($csv as $zeile) {
                fputcsv($h, array_map('strval', $zeile), ';', '"', '');
            }
            fclose($h);
            $this->info("CSV: {$pfad}");
        }

        return self::SUCCESS;
    }

    /** @return list<string> Geschmacksachsen mit Stufe ≥ 2, sortiert */
    private function liefert(array $eigenschaften): array
    {
        $out = array_values(array_filter(self::GESCHMACK, fn ($a) => (float) ($eigenschaften[$a]['stufe'] ?? 0) >= 2));
        sort($out);

        return $out;
    }
}
