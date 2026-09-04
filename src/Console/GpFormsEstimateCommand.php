<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpFormService;

/**
 * Naturaleinheit-Formen per KI nachziehen (Dominique, 2026-09-04).
 *
 * Anlass: „wenn ich ein Rezept mit Petersilie habe, will ich einen halben Bund nehmen,
 * ohne zu wissen was der wiegt — bei Apfel oder Tomate genauso." Genau dafür gibt es
 * `gp_forms`; nur war die Tabelle leer, wo sie gebraucht wird: 418 Rezeptzeilen über
 * 132 Grundprodukte dosieren in einer Zähl-Einheit, für die am GP kein Gewicht steht.
 *
 * Der Befehl schätzt NICHT blind alles durch, sondern pro Grundprodukt nur die Formen,
 * die dafür gängig sind — das entscheidet der Schätzer selbst (Prompt `gp.zaehl_einheiten`:
 * „NUR real anwendbare Formen … Unsichere weglassen"). Öl, Brühe und Mehl kommen darum
 * mit leerer Liste zurück, und das ist das richtige Ergebnis, kein Fehler.
 * Verpackungs-Einheiten (Flasche/Dose/Karton) sind im erlaubten Set gar nicht enthalten:
 * deren Gewicht hängt am Gebinde des Lieferanten, nicht am Produkt.
 *
 * Override-First (GL-07): manuell gepflegte Formen bleiben unangetastet (siehe
 * {@see GpFormService::estimateKi}). Resumefähig — wer schon ein Gewicht hat, fällt
 * aus dem Scope. Default = dry-run (nur Scope zählen), `--apply` schreibt.
 *
 * Zeilen, für die der Schätzer nichts liefert, landen im Report als Review-Fall.
 * Das ist der wichtigere Teil der Ausgabe: „Aepfel: frisch, Würfel 10 mm" in *Stück*
 * oder „Eigelb: flüssig, pasteurisiert" in *Stück* sind keine fehlenden Gewichte,
 * sondern falsche Einheiten — die soll niemand mit einer Zahl zudecken.
 */
class GpFormsEstimateCommand extends Command
{
    protected $signature = 'foodalchemist:gp-forms-estimate
        {--team= : Team-ID (Pflicht — Kuratierung ist team-gebunden, D1)}
        {--gp= : nur dieses Grundprodukt (Test eines Einzelfalls)}
        {--limit=0 : maximal so viele Grundprodukte (0 = alle)}
        {--sleep=0 : Sekunden Pause zwischen den KI-Aufrufen (Rate-Limit)}
        {--apply : Formen schreiben; ohne = dry-run (nur Scope + Plan)}
        {--report= : Pfad für den Markdown-Report}';

    protected $description = 'Schätzt fehlende Naturaleinheit-Gewichte (Bund/Zweig/Stück/…) je Grundprodukt per KI.';

    public function handle(GpFormService $formen): int
    {
        $teamId = (int) $this->option('team');
        $team = $teamId > 0 ? Team::find($teamId) : null;
        if ($team === null) {
            $this->error('--team=<id> ist Pflicht (Formen-Pflege ist Katalog-Arbeit des Besitzer-Teams).');

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
        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');

        $luecken = $this->luecken($team, $this->option('gp') !== null ? (int) $this->option('gp') : null);
        if ($limit > 0) {
            $luecken = $luecken->take($limit);
        }

        $this->info(($apply ? 'SCHREIBE' : 'DRY-RUN').' — '.$luecken->count().' Grundprodukte mit fehlendem Naturalgewicht');
        $this->line('erlaubte Formen (aus dem Vokabular): '.implode(', ', GpFormService::formSlugs($team)));

        $geschrieben = 0;
        $ohneVorschlag = [];
        $zeilen = [];
        $bar = $this->output->createProgressBar($luecken->count());
        $bar->start();

        foreach ($luecken as $l) {
            $gp = FoodAlchemistGp::find($l->gp_id);
            if ($gp === null) {
                $bar->advance();

                continue;
            }
            if (! $apply) {
                $zeilen[] = ['gp' => $gp->name, 'einheiten' => $l->slugs, 'n_zeilen' => $l->n_zeilen, 'formen' => '—(dry-run)'];
                $bar->advance();

                continue;
            }

            try {
                $n = $formen->estimateKi($team, (int) $gp->id);
            } catch (\Throwable $e) {
                // Ein einzelner Ausfall darf den Lauf nicht abbrechen — resumefähig, also
                // beim nächsten Lauf wieder im Scope.
                $zeilen[] = ['gp' => $gp->name, 'einheiten' => $l->slugs, 'n_zeilen' => $l->n_zeilen,
                    'formen' => 'FEHLER: '.mb_substr($e->getMessage(), 0, 120)];
                $bar->advance();

                continue;
            }
            $geschrieben += $n;
            $gesetzt = DB::table('foodalchemist_gp_forms')->where('gp_id', $gp->id)->whereNull('deleted_at')
                ->pluck('gramm', 'form_slug')->map(fn ($g) => (float) $g)->all();
            $fehlt = array_diff(explode(', ', $l->slugs), array_keys($gesetzt));
            if ($fehlt !== []) {
                $ohneVorschlag[] = ['gp' => $gp->name, 'fehlt' => implode(', ', $fehlt), 'n_zeilen' => $l->n_zeilen];
            }
            $zeilen[] = ['gp' => $gp->name, 'einheiten' => $l->slugs, 'n_zeilen' => $l->n_zeilen,
                'formen' => implode(' · ', array_map(fn ($k, $v) => $k.' '.$v.' g', array_keys($gesetzt), $gesetzt)) ?: '—'];
            $bar->advance();
            if ($sleep > 0) {
                sleep($sleep);
            }
        }
        $bar->finish();
        $this->newLine(2);

        $this->info($apply ? "geschrieben: {$geschrieben} Formen" : 'dry-run — nichts geschrieben');
        if ($ohneVorschlag !== []) {
            $this->warn(count($ohneVorschlag).' Grundprodukte ohne Vorschlag für die BENUTZTE Einheit → Review');
            $this->table(['Grundprodukt', 'Einheit fehlt weiter', 'Rezeptzeilen'],
                array_map(fn ($r) => [$r['gp'], $r['fehlt'], $r['n_zeilen']], array_slice($ohneVorschlag, 0, 25)));
        }

        if (($pfad = $this->option('report')) !== null) {
            $this->schreibeReport($pfad, $apply, $zeilen, $ohneVorschlag, $geschrieben);
            $this->line('Report: '.$pfad);
        }

        return self::SUCCESS;
    }

    /**
     * Grundprodukte, die in Rezepten in einer Zähl-Einheit dosiert werden, für die
     * am GP kein eigenes Gewicht hinterlegt ist. Verpackungs-Einheiten bleiben
     * ausgeschlossen (dort ist ein Produktgewicht fachlich falsch, nicht nur unbekannt).
     */
    private function luecken(Team $team, ?int $nurGp): \Illuminate\Support\Collection
    {
        return DB::table('foodalchemist_recipe_ingredients as ri')
            ->join('foodalchemist_vocab_units as u', 'u.id', '=', 'ri.unit_vocab_id')
            ->join('foodalchemist_gps as g', 'g.id', '=', 'ri.gp_id')
            ->whereNull('ri.deleted_at')->whereNull('g.deleted_at')
            ->where('u.dimension', 'count')
            ->whereIn('u.slug', GpFormService::formSlugs($team))
            ->when($nurGp !== null, fn ($q) => $q->where('g.id', $nurGp))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('foodalchemist_gp_forms as f')
                ->whereColumn('f.gp_id', 'g.id')->whereColumn('f.form_slug', 'u.slug')
                ->whereNull('f.deleted_at'))
            ->groupBy('g.id', 'g.name', 'u.slug')
            ->select('g.id as gp_id', 'g.name', 'u.slug', DB::raw('COUNT(*) as n'))
            ->get()
            // Gruppierung in PHP statt GROUP_CONCAT: dessen ORDER BY/SEPARATOR-Syntax ist
            // MySQL-only und würde jeden SQLite-Testlauf zerlegen (07 §7 engine-agnostisch).
            ->groupBy('gp_id')
            ->map(fn ($g) => (object) [
                'gp_id' => (int) $g->first()->gp_id,
                'name' => $g->first()->name,
                'n_zeilen' => (int) $g->sum('n'),
                'slugs' => $g->pluck('slug')->unique()->sort()->values()->implode(', '),
            ])
            ->sortByDesc('n_zeilen')
            ->values();
    }

    private function schreibeReport(string $pfad, bool $apply, array $zeilen, array $review, int $n): void
    {
        $md = "# Naturaleinheit-Gewichte per KI — ".date('Y-m-d H:i')."\n\n"
            .'Modus: '.($apply ? 'APPLY' : 'DRY-RUN')." · geschriebene Formen: {$n}\n\n"
            ."## Review — Einheit bleibt ohne Gewicht\n\n"
            ."Der Schätzer hat für die im Rezept BENUTZTE Einheit nichts geliefert. Das ist meist\n"
            ."kein fehlendes Gewicht, sondern eine falsche Einheit am Rezept (gewürfelte Ware in\n"
            ."„Stück\", Flüssigprodukt in „Stück\"). Einheit korrigieren, nicht Gewicht erfinden.\n\n"
            ."| Grundprodukt | Einheit fehlt weiter | Rezeptzeilen |\n|---|---|---|\n";
        foreach ($review as $r) {
            $md .= "| {$r['gp']} | {$r['fehlt']} | {$r['n_zeilen']} |\n";
        }
        $md .= "\n## Alle bearbeiteten Grundprodukte\n\n| Grundprodukt | benutzte Einheit(en) | Rezeptzeilen | gesetzte Formen |\n|---|---|---|---|\n";
        foreach ($zeilen as $r) {
            $md .= "| {$r['gp']} | {$r['einheiten']} | {$r['n_zeilen']} | {$r['formen']} |\n";
        }
        @file_put_contents($pfad, $md);
    }
}
