<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRolle;
use Platform\FoodAlchemist\Services\RecipeRollenService;
use Platform\FoodAlchemist\Services\Regeln\Arten\Zuordnung;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Rollen-Umbau (Dominique 11.10.): mögliche Teller-Rollen für Bestands-Basisrezepte VORSCHLAGEN — aus der Nutzungs-
 * Historie (Teller-Rollen der Gerichtszeilen, die das Rezept einsetzen; darum NACH foodalchemist:teller-rollen-
 * klassifizieren laufen lassen) und aus dem Typ (Regel basisrezept.typ_rollen). Gespeichert mit Quelle `vorschlag` —
 * kein stilles Festschreiben; ein Mensch bestätigt per recipes.PUT (Quelle manuell). Nur Rezepte ohne Rollen, kein KI-Call.
 */
class BasisrezeptRollenVorschlagenCommand extends Command
{
    protected $signature = 'foodalchemist:basisrezept-rollen-vorschlagen
        {--team= : Team-ID (Pflicht)}
        {--apply : schreiben; ohne = nur zählen und Beispiele zeigen}
        {--max=3 : höchstens so viele Rollen je Rezept}';

    protected $description = 'Schlägt mögliche Teller-Rollen für Basisrezepte ohne Rollen vor (Nutzung + Typ, Quelle vorschlag).';

    public function handle(RecipeRollenService $rollen): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('--team=<id> ist Pflicht.');

            return self::FAILURE;
        }
        $max = max(1, (int) $this->option('max'));
        $rezepte = FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('is_sales_recipe', false)
            ->whereNotIn('id', FoodAlchemistRecipeRolle::query()->select('recipe_id'))->orderBy('id')->get(['id', 'name']);

        $vorschlaege = [];
        foreach ($rezepte->chunk(500) as $teil) {
            $nutzung = DB::table('foodalchemist_recipe_ingredients as z')
                ->join('foodalchemist_recipes as g', 'g.id', '=', 'z.recipe_id')
                ->where('g.is_sales_recipe', true)->whereNull('z.deleted_at')->whereNotNull('z.role')
                ->whereIn('z.referenced_recipe_id', $teil->pluck('id'))
                ->selectRaw('z.referenced_recipe_id AS rid, z.role, COUNT(*) AS n')->groupBy('z.referenced_recipe_id', 'z.role')
                ->orderByDesc('n')->get()->groupBy('rid');
            foreach ($teil as $r) {
                $liste = ($nutzung->get($r->id) ?? collect())->pluck('role')->all();
                foreach (self::ausTyp((string) $r->name) as $t) {
                    if (! in_array($t, $liste, true)) {
                        $liste[] = $t;
                    }
                }
                if ($liste !== []) {
                    $vorschlaege[$r->id] = ['name' => $r->name, 'rollen' => array_slice($liste, 0, $max)];
                }
            }
        }

        $this->info(count($vorschlaege) . ' von ' . $rezepte->count() . ' Basisrezepten ohne Rollen bekommen einen Vorschlag.');
        foreach (array_slice($vorschlaege, 0, 10, true) as $id => $v) {
            $this->line("  #{$id} {$v['name']}: " . implode(', ', $v['rollen']));
        }
        if (! $this->option('apply')) {
            $this->line('DRY-RUN — nichts geschrieben. Schreiben mit --apply (Quelle vorschlag).');

            return self::SUCCESS;
        }
        $n = 0;
        foreach ($vorschlaege as $id => $v) {
            $n += $rollen->setze((int) $id, $v['rollen'], 'vorschlag') !== [] ? 1 : 0;
        }
        $this->info("{$n} Basisrezepte mit Rollen-Vorschlag gespeichert.");

        return self::SUCCESS;
    }

    /** @return list<string> Rollen aus der Typ-Regel für den Typ im Namen („Jus: Rind“ → Jus → sauce, aroma). */
    public static function ausTyp(string $name): array
    {
        $regel = RegelBuch::falls('basisrezept.typ_rollen');
        $praefix = RezeptTypVokabular::praefix($name);
        $typ = $praefix !== null ? (RezeptTypVokabular::finde($praefix) ?? $praefix) : null;
        if ($regel === null || $typ === null) {
            return [];
        }
        $e = app(Zuordnung::class)->finde($regel, $typ);

        return $e !== null ? array_values(array_filter(array_map('trim', explode(',', $e['ziel_name'])))) : [];
    }
}
