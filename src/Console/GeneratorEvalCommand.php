<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * Phase 4 (Kohärenz-Gate): Golden-Gerichte-Eval — beweist die Gate-Wirkung END-TO-END
 * gegen den ECHTEN Provider (Muster {@see MatcherEvalCommand}), statt sie anekdotisch
 * am Rahmeis-Fall zu behaupten.
 *
 * Zwei Kennzahlen aus EINEM gegateten Lauf je Gericht (kein Gate-Toggle in Produktion
 * nötig — das Gate liefert `wired` UND `entdrahtet`, daraus lässt sich der Zustand OHNE
 * Gate rekonstruieren):
 *  - Fremdkörper OHNE Gate = verbotenes Muster in (wired ∪ entdrahtet)
 *  - Fremdkörper MIT Gate  = verbotenes Muster NOCH VERDRAHTET (wired) → Ziel 0
 *  - False-Positive-Rate (saubere Gerichte) = irgendeine Zutat fälschlich entdrahtet
 *    → Ziel 0 (Legitimes verschonen — die Make-or-Break-Metrik).
 *
 * {@see evaluate()} ist rein/testbar (GeneratorEvalTest, provider- und DB-los). handle()
 * braucht einen ECHTEN Provider (--team, lokaler Key) — mit 'fake' ist das Ergebnis
 * bedeutungslos (der Kontext-Echo-Provider erfindet strukturell kein Rezept).
 */
class GeneratorEvalCommand extends Command
{
    protected $signature = 'foodalchemist:generator-eval
        {--team= : reale team_id (Bestand + Sichtbarkeit hängen daran)}
        {--fixture= : Pfad zum Golden-Set (Default tests/Fixtures/GeneratorGoldenDishes.php)}
        {--details : jedes Gericht einzeln mit wired/entdrahtet ausgeben}';

    protected $description = 'Phase 4: Golden-Gerichte → Fremdkörper-Rate (ohne/mit Gate) + False-Positive-Rate, gegen echten Provider';

    public function handle(RecipeGeneratorService $generator): int
    {
        $teamId = $this->option('team');
        if ($teamId === null || $teamId === '') {
            $this->error('--team=<id> ist Pflicht (Bestand + Sichtbarkeit hängen daran).');

            return self::INVALID;
        }
        $team = Team::find((int) $teamId);
        if ($team === null) {
            $this->error("Team {$teamId} nicht gefunden.");

            return self::INVALID;
        }

        $fixture = (string) ($this->option('fixture') ?: dirname(__DIR__, 2) . '/tests/Fixtures/GeneratorGoldenDishes.php');
        if (! is_file($fixture)) {
            $this->error("Golden-Set nicht gefunden: {$fixture}");

            return self::INVALID;
        }
        /** @var list<array> $golden */
        $golden = require $fixture;

        if ((string) config('foodalchemist.ai.provider') === 'fake') {
            $this->warn('⚠ AI-Provider = fake → Ergebnis bedeutungslos. FOODALCHEMIST_AI_PROVIDER + Key setzen (lokal oder demo).');
        }

        $results = [];
        foreach ($golden as $d) {
            $name = (string) ($d['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $this->info("Generiere: {$name} …");
            try {
                $gen = $generator->generiere($team, (string) ($d['description'] ?? ''), (array) ($d['parameter'] ?? []), vkModus: false);
            } catch (\Throwable $e) {
                $this->warn("  ⚠ {$name}: {$e->getMessage()}");
                $results[$name] = ['wired' => [], 'entdrahtet' => [], 'fehler' => true];

                continue;
            }

            $recipe = $gen['recipe']->load(['ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name']);
            $wired = $recipe->ingredients
                ->filter(fn ($z) => $z->gp_id !== null || $z->referenced_recipe_id !== null)
                ->map(fn ($z) => (string) ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->raw_text))
                ->values()->all();
            // Entdrahtet = vom Gate gelöste Fremdkörper (offene[].kritiker trägt den verdrahteten Namen).
            $entdrahtet = collect($gen['offene'] ?? [])
                ->filter(fn ($o) => isset($o['kritiker']))
                ->map(fn ($o) => (string) ($o['kritiker']['name'] ?? $o['text'] ?? ''))
                ->values()->all();

            $results[$name] = ['wired' => $wired, 'entdrahtet' => $entdrahtet];
        }

        $report = $this->evaluate($golden, $results);

        $this->table(['Kennzahl', 'Wert'], [
            ['Risiko-Gerichte', (string) $report['n_risk']],
            ['Fremdkörper OHNE Gate', $report['fremdkoerper_ohne_gate'] . ' (' . $this->pct($report['rate_ohne_gate']) . ')'],
            ['Fremdkörper MIT Gate', $report['fremdkoerper_mit_gate'] . ' (' . $this->pct($report['rate_mit_gate']) . ')'],
            ['Saubere Gerichte', (string) $report['n_clean']],
            ['False-Positives', $report['fp_dishes'] . ' (' . $this->pct($report['fp_rate']) . ')'],
        ]);

        if ($report['rate_mit_gate'] === 0.0 && $report['fp_rate'] === 0.0) {
            $this->info('✓ Gate-Ziel erreicht: Fremdkörper-Rate 0 UND 0 False-Positives.');
        } else {
            $this->warn('⚠ Ziel nicht erreicht — mit --details die Einzelgerichte prüfen.');
        }

        if ($this->option('details')) {
            $this->details($golden, $results);
        }

        return self::SUCCESS;
    }

    /**
     * Reine Auswertung (testbar): pro Gericht {wired, entdrahtet}.
     *
     * @param  list<array>  $golden
     * @param  array<string, array{wired:list<string>, entdrahtet:list<string>}>  $results
     * @return array{n_risk:int, n_clean:int, fremdkoerper_ohne_gate:int, fremdkoerper_mit_gate:int, rate_ohne_gate:?float, rate_mit_gate:?float, fp_dishes:int, fp_rate:?float}
     */
    public function evaluate(array $golden, array $results): array
    {
        $risk = array_values(array_filter($golden, static fn ($d) => ($d['class'] ?? '') === 'risk'));
        $clean = array_values(array_filter($golden, static fn ($d) => ($d['class'] ?? '') === 'clean'));

        $ohneGate = 0;
        $mitGate = 0;
        foreach ($risk as $d) {
            $r = $results[(string) $d['name']] ?? ['wired' => [], 'entdrahtet' => []];
            $forbid = (array) ($d['forbid'] ?? []);
            if ($this->anyForbidden(array_merge($r['wired'] ?? [], $r['entdrahtet'] ?? []), $forbid)) {
                $ohneGate++;
            }
            if ($this->anyForbidden($r['wired'] ?? [], $forbid)) {
                $mitGate++;
            }
        }

        $fp = 0;
        foreach ($clean as $d) {
            $r = $results[(string) $d['name']] ?? ['wired' => [], 'entdrahtet' => []];
            if (count($r['entdrahtet'] ?? []) > 0) {
                $fp++;
            }
        }

        $nRisk = count($risk);
        $nClean = count($clean);

        return [
            'n_risk' => $nRisk,
            'n_clean' => $nClean,
            'fremdkoerper_ohne_gate' => $ohneGate,
            'fremdkoerper_mit_gate' => $mitGate,
            'rate_ohne_gate' => $nRisk > 0 ? (float) ($ohneGate / $nRisk) : null,
            'rate_mit_gate' => $nRisk > 0 ? (float) ($mitGate / $nRisk) : null,
            'fp_dishes' => $fp,
            'fp_rate' => $nClean > 0 ? (float) ($fp / $nClean) : null,
        ];
    }

    /**
     * Verbotenes Muster in der Namensliste? SUBSTRING nach Umlaut-Faltung — die
     * Muster im Golden-Set sind bewusst distinktive Süß-/Dessert-Wörter (kein bloßes
     * „eis", das in „Fleisch" steckt).
     *
     * @param  list<string>  $names
     * @param  list<string>  $forbid
     */
    public function anyForbidden(array $names, array $forbid): bool
    {
        foreach ($names as $n) {
            $h = $this->fold((string) $n);
            if ($h === '') {
                continue;
            }
            foreach ($forbid as $p) {
                $pf = $this->fold((string) $p);
                if ($pf !== '' && str_contains($h, $pf)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : round($v * 100) . '%';
    }

    /**
     * @param  list<array>  $golden
     * @param  array<string, array{wired:list<string>, entdrahtet:list<string>}>  $results
     */
    private function details(array $golden, array $results): void
    {
        $this->line('');
        $this->line('Einzelgerichte:');
        foreach ($golden as $d) {
            $name = (string) ($d['name'] ?? '');
            $r = $results[$name] ?? ['wired' => [], 'entdrahtet' => []];
            $this->line(sprintf('  [%s] %s', $d['class'] ?? '?', $name));
            $this->line('      wired: ' . implode(' · ', $r['wired'] ?? []));
            if (($r['entdrahtet'] ?? []) !== []) {
                $this->line('      entdrahtet: ' . implode(' · ', $r['entdrahtet']));
            }
        }
    }
}
