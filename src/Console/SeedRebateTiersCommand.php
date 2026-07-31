<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\RebateService;

/**
 * Einkauf E1 — reale Rückvergütungs-Staffeln von 7 Hauptlieferanten in ein Team seeden.
 *
 * Quelle: der Lieferantenvergleich des Kollegen (tools/template.html, DEFAULT_REBATE_TIERS).
 * Diese Staffeln sind echte, verhandelte Konditionen — deshalb als Startbestand hinterlegt
 * (nicht erfunden). Lieferanten werden über einen normalisierten Namens-Kern gematcht
 * (Umlaut-/Rechtsform-/Standort-tolerant, z. B. „Chefs Culinar West Weeze"), da FA-Namen
 * länger und variantenreich sind. Trifft ein Kern mehrere FA-Zeilen desselben Vendors,
 * bekommen alle dieselbe Staffel.
 *
 * Idempotent (saveTiers = Replace-Set, saveConfig = Upsert). Default = dry-run.
 * team-scoped Overlay (kein customer_id — Kunden-Dimension = eigene Session).
 */
class SeedRebateTiersCommand extends Command
{
    protected $signature = 'foodalchemist:seed-rebate-tiers
        {--team= : Team-ID, in das die Staffeln gelegt werden (default: erstes Team)}
        {--apply : schreiben; ohne = dry-run (nur Treffer zeigen)}';

    protected $description = 'E1: hinterlegt die realen Rückvergütungs-Staffeln von 7 Hauptlieferanten (Kollegen-Tool) in einem Team.';

    /**
     * needle = normalisierter Namens-Kern; tiers = [Schwelle €, %]; selected = 0-basierter
     * Index der angenommenen Stufe (aus dem Kollegen-Tool übernommen).
     *
     * @var list<array{needle:string,label:string,tiers:list<array{0:float,1:float}>,selected:int}>
     */
    private const DEFS = [
        ['needle' => 'albgold', 'label' => 'ALB-GOLD Teigwaren', 'selected' => 2, 'tiers' => [
            [67671.43, 5.0], [101507.14, 10.0], [135342.86, 11.5],
        ]],
        ['needle' => 'chefsculinar', 'label' => 'Chefs Culinar', 'selected' => 7, 'tiers' => [
            [1750000, 10.0], [2000000, 10.25], [2250000, 10.5], [2500000, 10.75],
            [2750000, 11.0], [3000000, 11.25], [3250000, 11.5], [3500000, 11.75],
        ]],
        ['needle' => 'heinerweiss', 'label' => 'Heiner Weiß', 'selected' => 2, 'tiers' => [
            [200000, 2.5], [250000, 3.5], [300000, 4.4],
        ]],
        ['needle' => 'bergischlander', 'label' => 'Bergischländer', 'selected' => 0, 'tiers' => [
            [250000, 4.5],
        ]],
        ['needle' => 'henningbroscheit', 'label' => 'Henning Broscheit', 'selected' => 6, 'tiers' => [
            [0, 5.0], [450000, 6.0], [550000, 7.0], [650000, 7.5], [750000, 8.0], [850000, 8.5], [950000, 9.0],
        ]],
        ['needle' => 'elbfrost', 'label' => 'Elbfrost', 'selected' => 1, 'tiers' => [
            [300000, 3.0], [500000, 4.0],
        ]],
        ['needle' => 'hanos', 'label' => 'Hanos', 'selected' => 6, 'tiers' => [
            [0, 2.0], [200000, 3.0], [300000, 3.5], [400000, 4.0], [500000, 4.5], [600000, 5.0], [700000, 5.5],
        ]],
    ];

    public function handle(RebateService $rebate): int
    {
        $apply = (bool) $this->option('apply');

        $team = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->first()
            : Team::query()->orderBy('id')->first();

        if ($team === null) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }
        $this->info("Ziel-Team: {$team->id} ({$team->name})");

        // Alle für das Team sichtbaren Lieferanten einmal laden, dann normalisiert matchen.
        $suppliers = FoodAlchemistSupplier::visibleToTeam($team)->get(['id', 'name']);

        $rows = [];
        foreach (self::DEFS as $def) {
            $matches = $suppliers->filter(
                fn ($s) => str_contains($this->normalize((string) $s->name), $def['needle'])
            )->values();

            if ($matches->isEmpty()) {
                $rows[] = [$def['label'], '—', 'KEIN TREFFER', count($def['tiers']) . ' Stufen', '—'];

                continue;
            }

            foreach ($matches as $s) {
                if ($apply) {
                    $created = $rebate->saveTiers($team, (int) $s->id, array_map(
                        fn ($t) => ['threshold_eur' => $t[0], 'percent' => $t[1]],
                        $def['tiers']
                    ));
                    $selTier = $created->get($def['selected']);
                    $rebate->saveConfig($team, (int) $s->id, [
                        'active' => true,
                        'selected_tier_id' => $selTier?->id,
                    ]);
                    $selPct = $selTier !== null ? (float) $selTier->percent : null;
                } else {
                    $selPct = $def['tiers'][$def['selected']][1] ?? null;
                }

                $rows[] = [
                    $def['label'],
                    (int) $s->id . ' · ' . $s->name,
                    $apply ? 'gesetzt' : 'Treffer',
                    count($def['tiers']) . ' Stufen',
                    $selPct !== null ? number_format($selPct, 2) . ' %' : '—',
                ];
            }
        }

        $this->table(['Vendor', 'FA-Lieferant', 'Status', 'Staffel', 'Angenommene Stufe'], $rows);

        return self::SUCCESS;
    }

    /** Lowercase, Umlaute/ß auflösen, alles Nicht-Alphanumerische entfernen. */
    private function normalize(string $name): string
    {
        $s = mb_strtolower($name);
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);

        return (string) preg_replace('/[^a-z0-9]/', '', $s);
    }
}
