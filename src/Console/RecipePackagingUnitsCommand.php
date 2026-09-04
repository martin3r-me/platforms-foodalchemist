<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * D (Dominique, 2026-09-04): Rezeptzeilen, die in einer VERPACKUNGS-Einheit dosieren,
 * auf Masse bringen — „1,5 Päckchen Vanillinzucker" ist keine Rezeptmenge.
 *
 * Warum überhaupt: das Gewicht einer Flasche/Dose hängt am Gebinde des Lieferanten, nicht
 * am Produkt. Solche Einheiten stehen darum nicht im Formen-Katalog (GpFormService::
 * VERPACKUNGS_SLUGS) und können auch nicht per KI am GP geschätzt werden. Ohne Umstellung
 * bleiben sie ungewogen und fallen aus Yield und EK.
 *
 * ZWEI QUELLEN, bewusst: die Gebindegrösse des Lead-Lieferantenartikels (deterministisch,
 * echte Daten) UND eine unabhängige KI-Schätzung der handelsüblichen Packungsmasse.
 * Übernommen wird NUR, wo beide zusammenpassen. Grund steht in der Messung:
 *   · 0,6 Flasche Mirin, VPE 0,4 l  → 240 ml   · beide einig, unstrittig
 *   · 1,5 Päckchen Vanillinzucker, VPE 1 kg → 1,5 kg — die VPE ist korrekt (Lieferbeutel),
 *     gemeint ist das Handels-Päckchen mit ~8 g. Eine Quelle allein irrt hier STILL.
 *   · 0,001 Flasche Mirin → 0,4 ml — ein Eingabefehler, den keine Umrechnung heilt.
 * Uneinigkeit, fehlende Gebindegrösse und unplausible Mengen gehen in die Review-Liste,
 * nicht in die Datenbank.
 *
 * Default = dry-run. `--apply` schreibt nur die einigen Zeilen und legt eine Undo-Datei
 * (alte Menge + Einheit je Zeile) neben den Report; `--revert=<datei>` spielt sie zurück.
 */
class RecipePackagingUnitsCommand extends Command
{
    /** Ab dieser relativen Abweichung gelten die zwei Quellen als uneinig. */
    private const TOLERANZ = 0.25;

    /** Unter dieser Verpackungs-Menge ist die Zeile ein Eingabefehler, keine Dosierung. */
    private const MIN_MENGE = 0.01;

    protected $signature = 'foodalchemist:recipe-packaging-units
        {--team= : Team-ID (Pflicht)}
        {--apply : einige Zeilen umstellen; ohne = dry-run}
        {--revert= : Undo-Datei einspielen (macht ein --apply zurück)}
        {--report= : Pfad für den Markdown-Report}';

    protected $description = 'Stellt Rezeptzeilen mit Verpackungs-Einheit auf Masse um (Gebindegrösse + KI, nur bei Einigkeit).';

    public function handle(AiGatewayService $ki, RecipeRecomputeService $recompute): int
    {
        if (($undo = $this->option('revert')) !== null) {
            return $this->revert($undo, $recompute);
        }

        $teamId = (int) $this->option('team');
        $team = $teamId > 0 ? Team::find($teamId) : null;
        if ($team === null) {
            $this->error('--team=<id> ist Pflicht.');

            return self::FAILURE;
        }
        // Ohne eingeloggten Nutzer scheitert die Kuratier-Prüfung (Curate::canCurate liest
        // Auth::user()) und der KI-Gateway verliert seinen Team-Kontext samt Kill-Switch.
        // Muster wie WissenRecallProbeCommand: einen Nutzer des Ziel-Teams anmelden.
        $nutzer = $team->users()->first();
        if ($nutzer === null) {
            $this->error("Team {$teamId} hat keinen Nutzer — ohne einen greift das D1-Gate nicht.");

            return self::FAILURE;
        }
        \Illuminate\Support\Facades\Auth::login($nutzer);
        if ($nutzer->currentTeamRelation?->id !== $team->id) {
            $this->warn('Hinweis: aktives Team des Nutzers ist nicht '.$team->id.' — Kill-Switch/Wissen greifen ggf. anders.');
        }

        $apply = (bool) $this->option('apply');

        $g = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->first();
        $ml = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'ml')->first();
        if ($g === null || $ml === null) {
            $this->error('Einheiten „g" und „ml" fehlen im Vokabular — ohne Ziel-Einheit keine Umstellung.');

            return self::FAILURE;
        }

        $zeilen = $this->betroffene($team);
        $this->info(($apply ? 'SCHREIBE' : 'DRY-RUN').' — '.$zeilen->count().' Rezeptzeilen mit Verpackungs-Einheit');

        $plan = [];
        $bar = $this->output->createProgressBar($zeilen->count());
        $bar->start();
        foreach ($zeilen as $z) {
            $plan[] = $this->beurteile($z, $ki);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $einig = array_values(array_filter($plan, fn ($p) => $p['status'] === 'einig'));
        $review = array_values(array_filter($plan, fn ($p) => $p['status'] !== 'einig'));
        $this->info(count($einig).' Zeilen einig (übernehmbar) · '.count($review).' Zeilen Review');

        $undoDatei = null;
        if ($apply && $einig !== []) {
            $undoDatei = ($this->option('report') ?? storage_path('app/verpackungseinheiten')).'.undo.json';
            $undoZeilen = [];
            DB::transaction(function () use ($einig, $g, $ml, &$undoZeilen) {
                foreach ($einig as $p) {
                    $zutat = FoodAlchemistRecipeIngredient::find($p['ingredient_id']);
                    if ($zutat === null) {
                        continue;
                    }
                    $undoZeilen[] = ['id' => $zutat->id, 'quantity' => (string) $zutat->quantity,
                        'unit_vocab_id' => (int) $zutat->unit_vocab_id];
                    $zutat->update([
                        'quantity' => round($p['masse'], 2),
                        'unit_vocab_id' => $p['einheit'] === 'ml' ? $ml->id : $g->id,
                    ]);
                }
            });
            @file_put_contents($undoDatei, json_encode($undoZeilen, JSON_PRETTY_PRINT));
            foreach (array_unique(array_column($einig, 'recipe_id')) as $rid) {
                $recompute->recomputeAndPropagate((int) $rid);
            }
            $this->info('umgestellt: '.count($undoZeilen).' Zeilen · Undo: '.$undoDatei);
        }

        if (($pfad = $this->option('report')) !== null) {
            $this->schreibeReport($pfad, $apply, $einig, $review, $undoDatei);
            $this->line('Report: '.$pfad);
        }

        return self::SUCCESS;
    }

    /**
     * Beurteilt EINE Zeile. Die Gebindegrösse rechnet deterministisch, die KI liefert die
     * zweite Meinung; „einig" heisst, beide liegen innerhalb der Toleranz beieinander.
     *
     * @return array<string, mixed>
     */
    private function beurteile(object $z, AiGatewayService $ki): array
    {
        $basis = [
            'ingredient_id' => (int) $z->ingredient_id, 'recipe_id' => (int) $z->recipe_id,
            'rezept' => $z->rezept, 'gp' => $z->gp, 'slug' => $z->slug,
            'menge' => (float) $z->quantity,
        ];

        if ((float) $z->quantity < self::MIN_MENGE) {
            return $basis + ['status' => 'unplausibel', 'masse' => null, 'einheit' => null,
                'vpe' => null, 'ki' => null,
                'grund' => 'Menge < '.self::MIN_MENGE.' Verpackungen — Eingabefehler, keine Umrechnung'];
        }

        // Quelle 1: Gebinde des Lead-LA (kg → g, l → ml)
        $vpe = null;
        $zielEinheit = 'g';
        if ($z->la_qty !== null && (float) $z->la_qty > 0 && in_array($z->la_unit, ['kg', 'l'], true)) {
            $vpe = (float) $z->la_qty * 1000;
            $zielEinheit = $z->la_unit === 'l' ? 'ml' : 'g';
        }

        // Quelle 2: handelsübliche Packungsmasse laut KI
        $kiMasse = null;
        $kiGrund = null;
        try {
            $v = $ki->propose('recipe.verpackungsmasse', [
                'zutat' => $z->gp, 'menge' => (float) $z->quantity, 'verpackung' => $z->slug,
            ]);
            $kiMasse = ($v->werte['masse_je_verpackung'] ?? null) !== null ? (float) $v->werte['masse_je_verpackung'] : null;
            $kiGrund = $v->werte['begruendung'] ?? null;
            if (($v->werte['einheit'] ?? null) === 'ml' && $vpe === null) {
                $zielEinheit = 'ml';
            }
        } catch (\Throwable $e) {
            $kiGrund = 'KI-Fehler: '.mb_substr($e->getMessage(), 0, 80);
        }

        if ($vpe === null && $kiMasse === null) {
            return $basis + ['status' => 'ohne_quelle', 'masse' => null, 'einheit' => null,
                'vpe' => null, 'ki' => null, 'grund' => 'keine Gebindegrösse am Lead-LA und keine KI-Schätzung'];
        }
        if ($vpe === null || $kiMasse === null) {
            $einzel = $vpe ?? $kiMasse;

            return $basis + ['status' => 'einzelquelle', 'masse' => $einzel * (float) $z->quantity,
                'einheit' => $zielEinheit, 'vpe' => $vpe, 'ki' => $kiMasse,
                'grund' => ($vpe === null ? 'nur KI-Schätzung' : 'nur Gebindegrösse').' — vor Übernahme prüfen'
                    .($kiGrund !== null ? ' · '.$kiGrund : '')];
        }

        $abweichung = abs($vpe - $kiMasse) / max($vpe, $kiMasse);
        if ($abweichung > self::TOLERANZ) {
            return $basis + ['status' => 'uneinig', 'masse' => null, 'einheit' => $zielEinheit,
                'vpe' => $vpe, 'ki' => $kiMasse,
                'grund' => 'Gebinde '.round($vpe).' vs. KI '.round($kiMasse).' '.$zielEinheit
                    .' ('.round($abweichung * 100).' % auseinander)'.($kiGrund !== null ? ' · '.$kiGrund : '')];
        }

        // Einig: die Gebindegrösse gewinnt als Zahl (echte Daten schlagen die Schätzung),
        // die KI hat sie nur bestätigt.
        return $basis + ['status' => 'einig', 'masse' => $vpe * (float) $z->quantity,
            'einheit' => $zielEinheit, 'vpe' => $vpe, 'ki' => $kiMasse,
            'grund' => 'Gebinde und KI einig (±'.round($abweichung * 100).' %)'];
    }

    private function betroffene(Team $team): \Illuminate\Support\Collection
    {
        return DB::table('foodalchemist_recipe_ingredients as ri')
            ->join('foodalchemist_vocab_units as u', 'u.id', '=', 'ri.unit_vocab_id')
            ->join('foodalchemist_gps as g', 'g.id', '=', 'ri.gp_id')
            ->join('foodalchemist_recipes as r', 'r.id', '=', 'ri.recipe_id')
            ->leftJoin('foodalchemist_supplier_items as la', 'la.id', '=', 'g.lead_la_supplier_item_id')
            ->whereNull('ri.deleted_at')->whereNull('g.deleted_at')->whereNull('r.deleted_at')
            ->where('r.team_id', $team->id)                        // D1: nur eigene Rezepte anfassen
            ->whereIn('u.slug', GpFormService::VERPACKUNGS_SLUGS)
            ->select('ri.id as ingredient_id', 'ri.recipe_id', 'ri.quantity', 'r.name as rezept',
                'g.name as gp', 'u.slug', 'la.qty as la_qty', 'la.unit_code as la_unit')
            ->orderBy('r.name')
            ->get();
    }

    private function revert(string $datei, RecipeRecomputeService $recompute): int
    {
        $zeilen = json_decode((string) @file_get_contents($datei), true);
        if (! is_array($zeilen) || $zeilen === []) {
            $this->error('Undo-Datei nicht lesbar oder leer: '.$datei);

            return self::FAILURE;
        }
        $rezepte = [];
        DB::transaction(function () use ($zeilen, &$rezepte) {
            foreach ($zeilen as $z) {
                $zutat = FoodAlchemistRecipeIngredient::find($z['id'] ?? 0);
                if ($zutat === null) {
                    continue;
                }
                $rezepte[] = (int) $zutat->recipe_id;
                $zutat->update(['quantity' => $z['quantity'], 'unit_vocab_id' => $z['unit_vocab_id']]);
            }
        });
        foreach (array_unique($rezepte) as $rid) {
            $recompute->recomputeAndPropagate($rid);
        }
        $this->info('zurückgespielt: '.count($zeilen).' Zeilen');

        return self::SUCCESS;
    }

    private function schreibeReport(string $pfad, bool $apply, array $einig, array $review, ?string $undo): void
    {
        $md = '# Verpackungs-Einheiten → Masse — '.date('Y-m-d H:i')."\n\n"
            .'Modus: '.($apply ? 'APPLY' : 'DRY-RUN').' · einig: '.count($einig).' · Review: '.count($review)."\n"
            .($undo !== null ? "Undo: `{$undo}` (zurück mit `--revert=<datei>`)\n" : '')
            ."\nÜbernommen wird nur, wo Gebindegrösse des Lead-Lieferantenartikels UND KI-Schätzung\n"
            ."zusammenpassen. Eine Quelle allein irrt still: die VPE sagt beim Vanillinzucker 1 kg\n"
            ."(Lieferbeutel), gemeint ist das Handels-Päckchen mit ~8 g.\n\n"
            ."## Review — NICHT umgestellt\n\n| Rezept | Zutat | Menge | Einheit | Gebinde | KI | Grund |\n|---|---|---|---|---|---|---|\n";
        foreach ($review as $p) {
            $md .= "| {$p['rezept']} | {$p['gp']} | {$p['menge']} | {$p['slug']} | "
                .($p['vpe'] !== null ? round($p['vpe']).' ' : '—').' | '
                .($p['ki'] !== null ? round($p['ki']).' ' : '—')." | {$p['grund']} |\n";
        }
        $md .= "\n## Einig — ".($apply ? 'umgestellt' : 'übernehmbar')."\n\n| Rezept | Zutat | vorher | nachher | Grund |\n|---|---|---|---|---|\n";
        foreach ($einig as $p) {
            $md .= "| {$p['rezept']} | {$p['gp']} | {$p['menge']} {$p['slug']} | "
                .round($p['masse'], 2).' '.$p['einheit']." | {$p['grund']} |\n";
        }
        @file_put_contents($pfad, $md);
    }
}
