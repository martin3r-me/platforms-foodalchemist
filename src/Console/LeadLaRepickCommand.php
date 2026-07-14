<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;

/**
 * Etappe 1 / P2 — Lead-LA-Repick (chirurgisch).
 *
 * Für approved-GPs (requires_la), deren AKTUELLER Lead-LA nicht auf einen gültigen
 * Preis auflöst, wird per LeadLaService::pickLeadLa die V-27-Rangliste neu gezogen.
 * NUR wenn der neue Lead auf einen Preis auflöst, wird er gesetzt („fixbar", die 107).
 * GPs ohne jeden bepreisten LA bleiben unberührt und werden als „Park" gezählt (die 398,
 * echte Lücken → separater Sourcing-Schritt). GPs, deren Lead bereits auflöst, werden
 * NICHT angefasst (kein Churn der ohnehin sauberen Leads).
 *
 * Default = dry-run. --apply schreibt (den GLOBALEN gps.lead_la_supplier_item_id).
 * ⚠️ Vor --apply ein Backup der Master-DB ziehen (Kaskaden-Mutation).
 */
class LeadLaRepickCommand extends Command
{
    protected $signature = 'foodalchemist:lead-la-repick
        {--team= : Team-ID (Katalog-Besitzer; default: alle Teams)}
        {--used-only : nur in Rezepten genutzte GPs (höchster ROI)}
        {--limit= : max. Anzahl GPs (Test/Teillauf)}
        {--apply : Leads schreiben; ohne = dry-run (nur zählen)}';

    protected $description = 'P2: setzt den Lead-LA neu für approved-GPs, deren Lead nicht auf einen Preis auflöst (chirurgisch).';

    public function handle(LeadLaService $lead, PriceService $preise): int
    {
        $apply = (bool) $this->option('apply');
        $usedOnly = (bool) $this->option('used-only');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen (vorher Backup!).');
        }

        foreach ($teams as $team) {
            $q = FoodAlchemistGp::visibleToTeam($team)
                ->where('status', 'approved')->where('requires_la', true);
            if ($usedOnly) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('foodalchemist_recipe_ingredients as ri')
                    ->whereColumn('ri.gp_id', 'foodalchemist_gps.id'));
            }
            if ($limit !== null) {
                $q->limit($limit);
            }

            $ok = 0;      // Lead löst bereits auf → unangetastet
            $fix = 0;     // Lead neu gesetzt (bzw. würde) → löst jetzt auf
            $park = 0;    // kein bepreister LA → echte Lücke
            $betroffen = 0;

            $q->orderBy('id')->chunkById($limit !== null ? $limit : 200, function ($gps) use (&$ok, &$fix, &$park, &$betroffen, $lead, $preise, $team, $apply) {
                foreach ($gps as $gp) {
                    $betroffen++;
                    if ($this->loestAuf($gp->lead_la_supplier_item_id, $preise)) {
                        $ok++;

                        continue;
                    }
                    $neu = $lead->pickLeadLa($gp, $team);
                    if ($neu !== null && $neu !== $gp->lead_la_supplier_item_id && $this->loestAuf($neu, $preise)) {
                        $fix++;
                        if ($apply) {
                            $gp->update(['lead_la_supplier_item_id' => $neu]);
                        }
                    } else {
                        $park++;
                    }
                }
            }, 'id');

            $verb = $apply ? 'gesetzt' : 'fixbar (dry-run)';
            $this->info("Team {$team->id} ({$team->name}) — {$betroffen} approved-GPs geprüft:");
            $this->table(
                ['Kategorie', 'Anzahl'],
                [
                    ['Lead löst bereits auf (unangetastet)', $ok],
                    ["Lead neu {$verb}", $fix],
                    ['Park — kein bepreister LA (echte Lücke → Sourcing)', $park],
                ]
            );
        }

        return self::SUCCESS;
    }

    /** True, wenn der LA einen aktiven Preis > 0 hat (kanonisch via PriceService). */
    private function loestAuf(?int $laId, PriceService $preise): bool
    {
        if ($laId === null) {
            return false;
        }
        $p = $preise->activeFor($laId);

        return $p !== null && (float) $p->price > 0;
    }
}
