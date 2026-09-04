<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 51 Etappe G — den Bestand auf die Kaskade heben.
 *
 * Ausgangslage: Regenerationszeilen stehen ausschliesslich an GERICHTEN, mit einem
 * FREITEXT-`component_label` und toter `ingredient_id`. Dieselbe Komponente trägt in fünf
 * Gerichten fünf handgetippte Zeilen — genau die Doppelpflege, die dieser Spec abstellt.
 *
 * Was das Kommando tut:
 *   1. `component_label` gegen die Komponentennamen des Gerichts matchen → `ingredient_id` setzen.
 *   2. Tragen ALLE Vorkommen einer Komponente denselben Wert, wandert er als Default ans
 *      Basisrezept und die Gericht-Zeilen fallen weg.
 *   3. Weichen sie ab, bleibt JEDE am Gericht — als Override. Nichts wird stillschweigend
 *      vereinheitlicht.
 *   4. »Gesamt«-Zeilen bleiben unangetastet: sie sind Rang 0 (»das Gericht als Ganzes«), kein
 *      Fehlschlag.
 *   5. Behälter-Skalare am Rezept → `recipe_containers` (warm → regenerieren, kalt → ausgabe).
 *      `container_*_count` wird NICHT übernommen — die Zahl war nie an eine Menge gebunden und
 *      wird ab jetzt gerechnet. Sie landet nur im Report, damit auffällt, wo die alte
 *      Handeingabe stark abweicht.
 *
 * WARUM NICHT »erster Schreiber gewinnt«: das waere eine stille, von der Sortierung abhaengige
 * Entscheidung darueber, welche von fuenf widerspruechlichen Angaben zur Wahrheit wird. Ein
 * Konflikt gehoert einem Menschen vorgelegt, nicht weggerundet.
 *
 * Dry-Run ist der Default. --apply schreibt, --verify zeigt den Stand danach.
 */
class RegenerationHochziehenCommand extends Command
{
    protected $signature = 'foodalchemist:regeneration-hochziehen
        {--team= : nur dieses Team}
        {--apply : Schreiben (sonst Dry-Run)}
        {--verify : Nur den Stand zeigen}';

    protected $description = 'Hebt Gericht-Regenerationen auf die Komponenten (Spec 51) — idempotent, mit Review-Liste';

    private const GESAMT_LABELS = ['gesamt', 'komplett', 'ganzes gericht', 'alles'];

    public function handle(): int
    {
        if ($this->option('verify')) {
            return $this->verify();
        }

        $apply = (bool) $this->option('apply');
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;

        $zeilen = DB::table('foodalchemist_recipe_regenerations AS rr')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'rr.recipe_id')
            ->whereNull('rr.deleted_at')->whereNull('rr.ingredient_id')
            ->where('r.is_sales_recipe', true)
            ->when($team !== null, fn ($q) => $q->where('rr.team_id', $team))
            ->get(['rr.id', 'rr.recipe_id', 'rr.team_id', 'rr.component_label', 'rr.device_vocab_id',
                'rr.temp_c', 'rr.duration_min', 'rr.core_temp_c', 'rr.note', 'r.name AS rezept']);

        $gesamt = [];
        $treffer = [];
        $review = [];

        foreach ($zeilen as $z) {
            if (in_array(mb_strtolower(trim((string) $z->component_label)), self::GESAMT_LABELS, true)) {
                $gesamt[] = $z;                                  // Rang 0 — bleibt, wo es ist

                continue;
            }

            $zutat = $this->findeKomponente((int) $z->recipe_id, (string) $z->component_label);
            if ($zutat === null) {
                $review[] = ['zeile' => $z, 'grund' => 'kein Komponenten-Treffer'];

                continue;
            }
            $treffer[] = ['zeile' => $z, 'zutat' => $zutat];
        }

        // Gruppieren je Basisrezept: nur EINDEUTIGE Werte wandern hoch.
        $jeKomponente = [];
        foreach ($treffer as $t) {
            if ($t['zutat']->referenced_recipe_id === null) {
                $review[] = ['zeile' => $t['zeile'], 'grund' => 'Komponente ist kein Basisrezept (GP oder ungemappt)'];

                continue;
            }
            $jeKomponente[(int) $t['zutat']->referenced_recipe_id][] = $t;
        }

        $hoch = [];
        $bleibtOverride = [];
        foreach ($jeKomponente as $basisId => $gruppe) {
            $signaturen = collect($gruppe)->map(fn ($t) => $this->signatur($t['zeile']))->unique();

            if ($signaturen->count() === 1 && ! $this->basisHatZeile($basisId)) {
                $hoch[$basisId] = $gruppe;

                continue;
            }
            $bleibtOverride = [...$bleibtOverride, ...$gruppe];
            if ($signaturen->count() > 1) {
                $review[] = ['zeile' => $gruppe[0]['zeile'], 'grund' =>
                    $signaturen->count().' widersprüchliche Angaben in '.count($gruppe).' Gerichten — bleiben als Override'];
            }
        }

        $behaelter = $this->behaelterKandidaten($team);

        $this->bericht($gesamt, $hoch, $bleibtOverride, $review, $behaelter, $apply);

        if (! $apply) {
            $this->warn('Dry-Run — mit --apply schreiben.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($hoch, $bleibtOverride, $behaelter) {
            foreach ($hoch as $basisId => $gruppe) {
                $vorlage = $gruppe[0]['zeile'];
                DB::table('foodalchemist_recipe_regenerations')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'team_id' => $vorlage->team_id,
                    'recipe_id' => $basisId,
                    'component_label' => DB::table('foodalchemist_recipes')->where('id', $basisId)->value('name') ?? 'Gesamt',
                    'ingredient_id' => null,                     // »das bin ich«
                    'device_vocab_id' => $vorlage->device_vocab_id,
                    'temp_c' => $vorlage->temp_c, 'duration_min' => $vorlage->duration_min,
                    'core_temp_c' => $vorlage->core_temp_c, 'note' => $vorlage->note,
                    'sort_order' => 0, 'source' => 'migration',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('foodalchemist_recipe_regenerations')
                    ->whereIn('id', collect($gruppe)->pluck('zeile.id'))
                    ->update(['deleted_at' => now()]);
            }

            // Die uneindeutigen bleiben — aber ab jetzt AN IHRER KOMPONENTE, nicht als Freitext.
            foreach ($bleibtOverride as $t) {
                DB::table('foodalchemist_recipe_regenerations')->where('id', $t['zeile']->id)
                    ->update(['ingredient_id' => $t['zutat']->id, 'updated_at' => now()]);
            }

            foreach ($behaelter as $b) {
                DB::table('foodalchemist_recipe_containers')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'team_id' => $b['team_id'], 'recipe_id' => $b['recipe_id'], 'zweck' => $b['zweck'],
                    'container_vocab_id' => $b['container_vocab_id'],
                    'source' => 'migration',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->info('Fertig. Report-Zeilen oben prüfen, danach Recompute laufen lassen.');

        return self::SUCCESS;
    }

    /** Normalisierter Vergleich: Gross/Klein und Mehrfach-Leerzeichen sind kein Unterschied. */
    private function findeKomponente(int $recipeId, string $label): ?object
    {
        $norm = fn (string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
        $ziel = $norm($label);

        $zutaten = DB::table('foodalchemist_recipe_ingredients AS zi')
            ->leftJoin('foodalchemist_recipes AS sr', 'sr.id', '=', 'zi.referenced_recipe_id')
            ->leftJoin('foodalchemist_gps AS gp', 'gp.id', '=', 'zi.gp_id')
            ->where('zi.recipe_id', $recipeId)->whereNull('zi.deleted_at')
            ->get(['zi.id', 'zi.referenced_recipe_id', 'zi.raw_text', 'sr.name AS sub_name', 'gp.name AS gp_name']);

        foreach ($zutaten as $z) {
            foreach ([$z->sub_name, $z->gp_name, $z->raw_text] as $kandidat) {
                if ($kandidat !== null && $norm((string) $kandidat) === $ziel) {
                    return $z;
                }
            }
        }

        return null;
    }

    private function signatur(object $z): string
    {
        return implode('|', [$z->device_vocab_id ?? '-', $z->temp_c ?? '-', $z->duration_min ?? '-', $z->core_temp_c ?? '-']);
    }

    private function basisHatZeile(int $basisId): bool
    {
        return DB::table('foodalchemist_recipe_regenerations')
            ->where('recipe_id', $basisId)->whereNull('ingredient_id')->whereNull('deleted_at')->exists();
    }

    /** @return list<array<string, mixed>> */
    private function behaelterKandidaten(?int $team): array
    {
        $raus = [];
        $zeilen = DB::table('foodalchemist_recipes')
            ->whereNull('deleted_at')
            ->when($team !== null, fn ($q) => $q->where('team_id', $team))
            ->where(fn ($q) => $q->whereNotNull('container_warm_vocab_id')->orWhereNotNull('container_cold_vocab_id'))
            ->get(['id', 'team_id', 'name', 'container_warm_vocab_id', 'container_warm_count',
                'container_cold_vocab_id', 'container_cold_count']);

        foreach ($zeilen as $r) {
            // warm → regenerieren, kalt → ausgabe: eine Temperatur-Achse wird zur Prozess-Achse.
            foreach ([['regenerieren', $r->container_warm_vocab_id, $r->container_warm_count],
                ['ausgabe', $r->container_cold_vocab_id, $r->container_cold_count]] as [$zweck, $id, $anzahl]) {
                if ($id === null) {
                    continue;
                }
                $schonDa = DB::table('foodalchemist_recipe_containers')
                    ->where('recipe_id', $r->id)->where('zweck', $zweck)->whereNull('deleted_at')->exists();
                if ($schonDa) {
                    continue;
                }
                $raus[] = ['recipe_id' => (int) $r->id, 'team_id' => $r->team_id, 'zweck' => $zweck,
                    'container_vocab_id' => (int) $id, 'name' => $r->name, 'alte_anzahl' => $anzahl];
            }
        }

        return $raus;
    }

    private function bericht(array $gesamt, array $hoch, array $override, array $review, array $behaelter, bool $apply): void
    {
        $this->line('');
        $this->info(sprintf(
            '%d Zeilen »Gesamt« (bleiben) · %d Komponenten werden hochgezogen · %d bleiben Override · %d in Review',
            count($gesamt), count($hoch), count($override), count($review)
        ));

        if ($behaelter !== []) {
            $this->line('');
            $this->info(count($behaelter).' Behälter-Skalare → recipe_containers:');
            $this->table(['Rezept', 'Zweck', 'alte Handzahl (wird NICHT übernommen)'],
                array_map(fn ($b) => [$b['name'], $b['zweck'], $b['alte_anzahl'] ?? '—'], array_slice($behaelter, 0, 25)));
        }

        if ($review !== []) {
            $this->line('');
            $this->warn('Review — hier entscheidet ein Mensch:');
            $this->table(['Gericht', 'Label', 'Grund'], array_map(
                fn ($r) => [$r['zeile']->rezept, $r['zeile']->component_label, $r['grund']],
                array_slice($review, 0, 40)
            ));
            if (count($review) > 40) {
                $this->line('… '.(count($review) - 40).' weitere.');
            }
        }
    }

    private function verify(): int
    {
        $anGerichten = DB::table('foodalchemist_recipe_regenerations AS rr')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'rr.recipe_id')
            ->whereNull('rr.deleted_at')->where('r.is_sales_recipe', true);

        $this->table(['Kennzahl', 'Wert'], [
            ['Zeilen an Basisrezepten (Defaults)', DB::table('foodalchemist_recipe_regenerations AS rr')
                ->join('foodalchemist_recipes AS r', 'r.id', '=', 'rr.recipe_id')
                ->whereNull('rr.deleted_at')->where('r.is_sales_recipe', false)->count()],
            ['Zeilen an Gerichten gesamt', (clone $anGerichten)->count()],
            ['davon Overrides (ingredient_id gesetzt)', (clone $anGerichten)->whereNotNull('rr.ingredient_id')->count()],
            ['davon »Gesamt« (ingredient_id NULL)', (clone $anGerichten)->whereNull('rr.ingredient_id')->count()],
            ['Behälter-Zeilen je Zweck', DB::table('foodalchemist_recipe_containers')->whereNull('deleted_at')->count()],
        ]);

        return self::SUCCESS;
    }
}
