<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;

/**
 * `--alle` (Dominique 11.10., nach Probelauf 2): auch Rollen aus der Umstellung bzw. von der KI neu zuordnen (aus V-21
 * übernommene beilage/garnitur waren teils falsch, z. B. Olivenöl = beilage). Manuell gesetzte Rollen und Rollen ohne
 * bekannte Quelle bleiben unangetastet (role_source).
 *
 * Rollen-Umbau (Dominique 11.10.): Gerichtszeilen ohne Teller-Rolle neu klassifizieren — nach der Umstellung von V-21
 * (komponente/aroma_treiber nicht eindeutig abbildbar → leer, Altwert in role_v21). Je Gericht EIN KI-Call `vk.rollen`
 * (Gesamt-Gericht-Sicht), geschrieben wird NUR in leere Zeilen (Override-First). Resumefähig: ein Gericht ohne leere
 * Zeile fällt aus dem Scope; `--ab-id` setzt nach einem Abbruch fort.
 *
 * Ohne `--apply`: nur Scope zählen und die Kosten schätzen — aus GEMESSENEN vk.rollen-Calls im Call-Log (Mittelwert
 * tokens_in/out). Ohne Messwerte keine geratene Zahl: dann erst einen Probelauf mit `--apply --limit=5`.
 */
class TellerRollenKlassifizierenCommand extends Command
{
    protected $signature = 'foodalchemist:teller-rollen-klassifizieren
        {--team= : Team-ID (Pflicht)}
        {--apply : schreiben; ohne = nur Scope + Kosten-Schätzung}
        {--alle : auch schon gesetzte Rollen neu zuordnen (Quelle migration/ki) — manuelle und unbekannte bleiben}
        {--limit=0 : höchstens so viele Gerichte (0 = alle)}
        {--ab-id=0 : erst ab dieser Rezept-ID (Fortsetzen nach Abbruch)}
        {--sleep=0 : Sekunden Pause zwischen den KI-Calls}';

    protected $description = 'Gerichtszeilen ohne Teller-Rolle per KI (vk.rollen) neu klassifizieren — nur leere Zeilen.';

    public function handle(SpeisenKlassenService $klassen): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('--team=<id> ist Pflicht.');

            return self::FAILURE;
        }
        $nutzer = $team->users()->first();
        if ($nutzer === null) {
            $this->error('Team ohne Nutzer — der KI-Gateway braucht einen angemeldeten Nutzer des Teams.');

            return self::FAILURE;
        }
        Auth::login($nutzer);

        // Spec 84 B3: Auth::login setzt das UI-Team des Nutzers (kann ein anderes Team sein) — gelesen wird in der Sicht von --team
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::lesenIm($team, fn () => $this->ausfuehren($team, $klassen));
    }

    private function ausfuehren(Team $team, SpeisenKlassenService $klassen): int
    {
        $alle = (bool) $this->option('alle');
        $scope = self::scope($team, (int) $this->option('ab-id'), $alle);
        $gerichte = (clone $scope)->count();
        $zeilen = self::zielZeilen($alle)->whereIn('recipe_id', (clone $scope)->select('id'))->count();
        $geschuetzt = DB::table('foodalchemist_recipe_ingredients')->whereIn('recipe_id', (clone $scope)->select('id'))->whereNull('deleted_at')
            ->whereNotNull('role')->where(fn ($q) => $q->whereNull('role_source')->orWhereNotIn('role_source', self::UEBERSCHREIBBAR))->count();
        $this->info("Scope: {$gerichte} Gerichte, {$zeilen} Zeilen " . ($alle ? 'werden neu zugeordnet (leer oder Quelle migration/ki)' : 'ohne Teller-Rolle')
            . " (je Gericht 1 KI-Call). {$geschuetzt} Zeilen bleiben geschützt (manuell oder Quelle unbekannt).");
        $this->schaetzung($gerichte);
        if (! $this->option('apply')) {
            $this->line('DRY-RUN — nichts geschrieben. Ausführen mit --apply (Probe: --apply --limit=5).');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $ids = (clone $scope)->orderBy('id')->when($limit > 0, fn ($q) => $q->limit($limit))->pluck('id');
        $bar = $this->output->createProgressBar($ids->count());
        $bar->start();
        $geschrieben = 0;
        $fehler = 0;
        $ungueltig = [];   // Wert => Anzahl — nie still verwerfen (demo Probelauf 11.10.: KI lieferte V-21)
        $letzte = null;
        foreach ($ids as $id) {
            try {
                $leer = self::zielZeilen($alle)->where('recipe_id', $id)->pluck('id')->map(fn ($v) => (int) $v)->flip();
                $antwort = $klassen->verteileRollen($team, (int) $id, $alle);
                foreach ($antwort['verworfen'] ?? [] as $zeile => $wert) {
                    if ($leer->has((int) $zeile)) {
                        $ungueltig[$wert] = ($ungueltig[$wert] ?? 0) + 1;
                    }
                }
                $nurLeer = array_filter($antwort['rollen'], fn ($rolle, $zeile) => $leer->has((int) $zeile), ARRAY_FILTER_USE_BOTH);
                $geschrieben += $nurLeer === [] ? 0 : $klassen->acceptRollen($team, (int) $id, $nurLeer);
            } catch (\Throwable $e) {
                $fehler++;
                $this->newLine();
                $this->warn("Rezept {$id}: {$e->getMessage()}");
            }
            $letzte = (int) $id;
            $bar->advance();
            if (($s = (int) $this->option('sleep')) > 0) {
                sleep($s);
            }
        }
        $bar->finish();
        $this->newLine();
        $this->info("{$geschrieben} Zeilen klassifiziert, {$fehler} Fehler. Letztes Rezept: {$letzte}"
            . ($letzte !== null ? ' — fortsetzen mit --ab-id=' . ($letzte + 1) : ''));
        if ($ungueltig !== []) {
            arsort($ungueltig);
            $this->error(array_sum($ungueltig) . ' Zeilen mit ungültiger Rolle von der KI (nicht geschrieben, bleiben leer): '
                . implode(', ', array_map(fn ($w, $n) => "{$n}× " . ($w === '' ? '(leer)' : $w), array_keys($ungueltig), $ungueltig))
                . '. Vor dem Weiterlaufen prüfen: liefert ein Kanon-/Regelwerk-Text noch das alte Vokabular?');
        }

        return $fehler > 0 || $ungueltig !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** Quellen, die `--alle` überschreiben darf. Manuell und unbekannt (null bei gesetzter Rolle) nie. */
    public const UEBERSCHREIBBAR = ['migration', 'ki'];

    /** Zeilen, die das Kommando schreiben darf: leer — mit `--alle` auch Rollen aus migration/ki. */
    public static function zielZeilen(bool $alle)
    {
        return DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('role')->when($alle, fn ($w) => $w->orWhereIn('role_source', self::UEBERSCHREIBBAR)));
    }

    /** Gerichte des Teams mit mindestens einer Zeile, die das Kommando schreiben darf. */
    public static function scope(Team $team, int $abId = 0, bool $alle = false)
    {
        return FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('is_sales_recipe', true)
            ->when($abId > 0, fn ($q) => $q->where('id', '>=', $abId))
            ->whereIn('id', self::zielZeilen($alle)->select('recipe_id'));
    }

    /** Kosten aus gemessenen vk.rollen-Calls; ohne genug Messwerte bewusst keine Zahl. */
    private function schaetzung(int $gerichte): void
    {
        $m = DB::table('foodalchemist_ai_call_log')->where('feature', 'vk.rollen')->whereNull('error')
            ->selectRaw('COUNT(*) AS n, AVG(tokens_in) AS tin, AVG(tokens_out) AS tout')->first();
        if ($m === null || (int) $m->n < 5) {
            $this->warn('Kosten-Schätzung: zu wenige gemessene vk.rollen-Calls (' . (int) ($m->n ?? 0) . ') — erst Probelauf --apply --limit=5.');

            return;
        }
        $this->line(sprintf('Kosten-Schätzung (aus %d gemessenen Calls): %d Calls × Ø %d Tokens rein / %d raus ≈ %s Tokens rein, %s raus.',
            (int) $m->n, $gerichte, (int) $m->tin, (int) $m->tout,
            number_format($gerichte * (float) $m->tin, 0, ',', '.'), number_format($gerichte * (float) $m->tout, 0, ',', '.')));
    }
}
