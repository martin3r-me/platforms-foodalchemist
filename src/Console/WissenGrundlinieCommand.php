<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52 · A1 — die GRUNDLINIE, gemessen am tatsächlich versendeten Modell-Aufruf.
 *
 * Warum das vor jedem Fix kommt: ohne Vorher-Zahlen reparieren wir Tabellen statt Fehler und
 * wissen hinterher nicht, ob es besser wurde. Und die interessante Zahl steht nicht im
 * Generator-Aufruf, sondern in den Aufrufen DANACH — pro Rezept-Erstellung laufen drei bis
 * vier (`recipe.generator` → `conformance.check` → `*.ueberarbeiten` → `*.review`), und der
 * Kontext-Inspektor der Oberfläche zeigt nur den ersten.
 *
 * Der Bericht nennt je Aufruf die Töpfe der Messsonde (`kanon`/`bound`/`retrieval`/`task`/
 * `kontext`/`huelle`/`dropped`) und die Wissens-Kanäle. Drei Dinge stehen ausdrücklich drin,
 * weil sie leicht überlesen werden:
 *
 *   · **`dropped > 0`** — Wissen wurde gebaut und dann gekappt. Bis Spec 52/B3 geben nur zwei
 *     von vierzehn Aufrufern diese Zahl überhaupt weiter, `dropped: 0` heisst also je nach
 *     Feature „nichts verloren" ODER „nicht gemessen". Der Bericht sagt, welches von beidem.
 *   · **`kanon = 0` und `bound = 0`** zugleich — dann trug dieser Aufruf kein verbindliches
 *     Wissen. Das ist der Zustand, den Befund B3/I5 vermutet, und hier wird er zur Zahl.
 *   · **fehlende Aufrufe** — die Klammer ist heute `target_table`/`target_id`, und die setzt
 *     nur, wer daran gedacht hat. Eine fehlende Zeile ist deshalb kein Beweis für „kein
 *     Aufruf". Mit der Lauf-ID (C1) verschwindet dieser Vorbehalt.
 *
 * Rein lesend, kein Modell-Aufruf, keine Kosten.
 */
class WissenGrundlinieCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-grundlinie
        {--team=6 : Team, dessen Rezepte gemessen werden}
        {--recipes= : Komma-Liste von Rezept-IDs (ohne Angabe: die letzten mit KI-Herkunft)}
        {--limit=6 : wie viele Rezepte ohne --recipes}
        {--json : maschinenlesbar, für den Vorher/Nachher-Vergleich}';

    protected $description = 'Spec 52/A1: Grundlinien-Messung — welches Wissen kam je Modell-Aufruf einer Rezept-Erstellung an';

    public function handle(RecipeKiKontextService $kontext): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Kein Team #'.$this->option('team').'.');

            return self::FAILURE;
        }

        $rezepte = $this->rezepte($team);
        if ($rezepte === []) {
            $this->warn('Keine Rezepte gefunden — mit --recipes=ID,ID gezielt vorgeben.');

            return self::SUCCESS;
        }

        $bericht = [];
        foreach ($rezepte as $r) {
            $calls = $kontext->alleCallsFuerRezept($r);
            $bericht[] = [
                'recipe_id' => (int) $r->id,
                'name' => (string) $r->name,
                'ist_gericht' => (bool) $r->is_sales_recipe,
                'created_via' => $r->created_via !== null ? (string) $r->created_via : null,
                'calls' => $calls,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($bericht as $b) {
            $this->newLine();
            $this->info(sprintf(
                '#%d %s%s — %d Modell-Aufruf(e) am Rezept',
                $b['recipe_id'], $b['name'], $b['ist_gericht'] ? ' [Gericht]' : '', count($b['calls']),
            ));

            if ($b['calls'] === []) {
                $this->line('  (kein Aufruf ans Rezept gehängt — heisst NICHT, dass keiner lief; die Klammer');
                $this->line('   `target_table`/`target_id` setzt nur, wer daran gedacht hat. → Lauf-ID, Spec 52/C1)');

                continue;
            }

            $this->table(
                ['Feature', 'kanon', 'bound', 'retrieval', 'task', 'kontext', 'dropped', 'Tk in', 'Dossiers', 'Bewertung'],
                array_map(function (array $c) {
                    $g = $c['groessen'];
                    if ($g === null) {
                        return [$c['feature'], '–', '–', '–', '–', '–', '–', '–', '–', 'Sonde hat nichts'];
                    }
                    $dossiers = array_sum(array_map(fn ($v) => is_array($v) ? count($v) : 0, $c['kanaele']))
                        ?: count($c['wissen_slugs']);

                    return [
                        $c['feature'],
                        number_format($g['kanon'], 0, ',', '.'),
                        number_format($g['bound'], 0, ',', '.'),
                        number_format($g['retrieval'], 0, ',', '.'),
                        number_format($g['task'], 0, ',', '.'),
                        number_format($g['kontext'], 0, ',', '.'),
                        $g['dropped'] > 0 ? number_format($g['dropped'], 0, ',', '.') : '0',
                        number_format($g['tokens_in'], 0, ',', '.'),
                        (string) $dossiers,
                        $this->bewertung($c['feature'], $g, $dossiers),
                    ];
                }, $b['calls'])
            );
        }

        $this->newLine();
        $this->zusammenfassung($bericht);

        return self::SUCCESS;
    }

    /**
     * Die Bewertung ist der eigentliche Wert des Berichts: eine Zahl allein sagt nicht, ob sie
     * gut ist. `dropped: 0` ist je nach Feature eine Aussage oder eine Nicht-Messung — das darf
     * der Bericht nicht verwischen (Befund I3).
     *
     * @param array<string, int> $g
     */
    private function bewertung(string $feature, array $g, int $dossiers): string
    {
        $teile = [];

        if ($g['kanon'] === 0 && $g['bound'] === 0) {
            $teile[] = 'KEIN verbindliches Wissen';
        }
        if ($g['dropped'] > 0) {
            $teile[] = 'GEKAPPT';
        } elseif (! in_array($feature, ['recipe.generator', 'vk.generator'], true)) {
            // `knowledge_dropped_chars` gibt nur RecipeGeneratorService/RecipeOneShotService
            // weiter. Bei allen anderen Features ist die 0 kein Befund, sondern eine Leerstelle.
            $teile[] = 'dropped nicht gemessen';
        }
        if ($dossiers === 0) {
            $teile[] = 'kein Dossier protokolliert';
        }

        return $teile === [] ? 'ok' : implode(' · ', $teile);
    }

    /** @param list<array<string, mixed>> $bericht */
    private function zusammenfassung(array $bericht): void
    {
        $alleCalls = array_merge(...array_map(fn ($b) => $b['calls'], $bericht)) ?: [];
        $features = [];
        $ohneVerbindlich = 0;
        $gekappt = 0;

        foreach ($alleCalls as $c) {
            $features[$c['feature']] = ($features[$c['feature']] ?? 0) + 1;
            $g = $c['groessen'];
            if ($g === null) {
                continue;
            }
            if ($g['kanon'] === 0 && $g['bound'] === 0) {
                $ohneVerbindlich++;
            }
            if ($g['dropped'] > 0) {
                $gekappt++;
            }
        }

        ksort($features);
        $this->info(sprintf(
            'Grundlinie: %d Rezept(e) · %d Aufruf(e) · %d ohne verbindliches Wissen · %d mit Kappung',
            count($bericht), count($alleCalls), $ohneVerbindlich, $gekappt,
        ));
        foreach ($features as $f => $n) {
            $this->line(sprintf('  %-28s %d×', $f, $n));
        }
        $this->newLine();
        $this->line('Diese Tabelle ist die Referenz für Etappe B und C. Zwei Vorbehalte gehören dazu:');
        $this->line('  · fehlt ein Feature ganz, kann der Aufruf gelaufen sein, ohne ans Rezept gehängt zu werden;');
        $this->line('  · `dropped` ist ausserhalb der Generatoren heute nicht verdrahtet (Spec 52/B3).');
    }

    /** @return list<FoodAlchemistRecipe> */
    private function rezepte(Team $team): array
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($this->option('recipes') ?? '')),
        )));

        $q = FoodAlchemistRecipe::query();
        TeamScope::applyVisible($q, 'team_id', $team);

        if ($ids !== []) {
            return $q->whereIn('id', $ids)->orderBy('id')->get()->all();
        }

        return $q->whereNotNull('created_via')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()->all();
    }
}
