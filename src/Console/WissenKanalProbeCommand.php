<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * KALIBRIERUNG DES RELEVANZ-BODENS (`semantic_search.discovery_floor`).
 *
 * Anlass (2026-09-07, Lauf 65 „Creme-Suppe: Tomate-Speck"): der Recall zog
 * `weltkueche_uruguayisch` und `ganache_kennwerte--1` in einen Tomaten-Speck-Prompt.
 * Beide kamen ueber die SEMANTIK — lexikalisch skoren sie 0, ihre Slug-Tokens stehen
 * nicht im Brief. Ursache war nicht ein falscher Treffer, sondern eine ZWANGS-QUOTE:
 * Kategorien mit `max_docs = 1` fuellen ihren Platz, solange irgendein Doc `min_score`
 * (0,30) schafft. Ein Kanal durfte nicht leer bleiben.
 *
 * Der Boden repariert das — aber sein Wert darf NICHT geraten werden. Dieser Probe
 * zeigt fuer eine echte Anfrage je Kategorie die Kandidaten MIT Cosine, sortiert. Man
 * liest ab, wo die fachlich richtigen und die fachlich falschen Dossiers auseinander
 * gehen, und setzt `FOODALCHEMIST_DISCOVERY_FLOOR` dazwischen.
 *
 * Ablesen, nicht rechnen: interessant ist der ABSTAND zwischen dem letzten guten und dem
 * ersten schlechten Treffer. Ist er klein, ist der Boden das falsche Werkzeug fuer diese
 * Kategorie — dann gehoert das Wissen in den Kanon (Aufgaben-Wissen findet die Semantik
 * nicht, weil der Brief die Frage nicht stellt).
 *
 * Read-only. Kosten: EINE Query-Einbettung je Lauf (der Recall-Pool wird geteilt).
 *
 * Beispiel:
 *   php artisan foodalchemist:wissen-kanal-probe --team=6 \
 *     --query="Ich brauche ein Basisrezept mit einer Tomatensuppe, die mit Speck im Ansatz ist, schoen cremig, mit ein bisschen Sahne, Olivenoel eingemixt und mit Basilikum." \
 *     --feature=ai_generate_recipe
 */
class WissenKanalProbeCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-kanal-probe
        {--team= : PFLICHT — die Suchpartitionen haengen daran (wie bei recall-probe)}
        {--query= : PFLICHT — der Anfragetext (idealerweise ein echter Brief)}
        {--feature= : nur die Kategorien, die dieses Feature per discovery laedt}
        {--kategorie=* : explizite Kategorien (schlaegt --feature)}
        {--top=8 : Kandidaten je Kategorie}
        {--min-score= : Untergrenze der Suche (Default: Service-Wert)}
        {--json : Ergebnis als JSON (fuer vor/nach-Vergleich)}';

    protected $description = 'Zeigt je Wissens-Kategorie die semantischen Kandidaten MIT Cosine — Kalibrierung des Relevanz-Bodens';

    public function handle(KnowledgeEmbeddingService $emb): int
    {
        if (! $emb->isProviderAvailable()) {
            $this->error('Kein Embedding-Provider verfuegbar.');

            return self::FAILURE;
        }
        $query = trim((string) $this->option('query'));
        if ($query === '') {
            $this->error('--query="…" ist Pflicht.');

            return self::INVALID;
        }
        // Partitions-Kontext herstellen: ohne angemeldeten Nutzer sucht der Service NUR in
        // der globalen Partition und jede Zahl waere wertlos (s. WissenRecallProbeCommand).
        $teamId = (int) $this->option('team');
        if ($teamId <= 0) {
            $this->error('--team=<id> ist Pflicht — ohne Team misst die Suche nur die globale Partition.');

            return self::INVALID;
        }
        $team = Team::find($teamId);
        $nutzer = $team?->users()->first();
        if ($team === null || $nutzer === null) {
            $this->error("Team {$teamId} nicht gefunden oder ohne Nutzer.");

            return self::INVALID;
        }
        Auth::login($nutzer);

        $kategorien = array_values(array_filter((array) $this->option('kategorie')));
        if ($kategorien === []) {
            $feature = trim((string) $this->option('feature'));
            $q = DB::table('foodalchemist_knowledge_routings')->where('mode', 'discovery');
            if ($feature !== '') {
                $q->where('feature', $feature);
            }
            $kategorien = $q->distinct()->orderBy('category')->pluck('category')
                ->map(static fn ($c) => (string) $c)->all();
        }
        if ($kategorien === []) {
            $this->error('Keine Kategorien — --feature ohne discovery-Routings? Dann --kategorie=… setzen.');

            return self::INVALID;
        }

        $top = max(1, (int) $this->option('top'));
        $minScore = $this->option('min-score') !== null && $this->option('min-score') !== ''
            ? (float) $this->option('min-score') : null;
        $bodenIst = (float) config('foodalchemist.semantic_search.discovery_floor', 0.0);

        $this->line(sprintf('  Team %d (%s) · %d Kategorien · Boden aktuell: %s',
            $team->id, (string) $team->name, count($kategorien),
            $bodenIst > 0.0 ? number_format($bodenIst, 3) : 'AUS (= min_score)'));
        $this->line('  Anfrage: '.mb_strimwidth($query, 0, 110, '…'));
        $this->newLine();

        // Die je Feature erlaubte Doc-Zahl daneben stellen: erst sie macht sichtbar, welche
        // Kandidaten die Quote tatsaechlich BELEGEN — und damit, welcher Wert etwas aendert.
        $maxDocs = DB::table('foodalchemist_knowledge_routings')->where('mode', 'discovery')
            ->when(trim((string) $this->option('feature')) !== '',
                fn ($q) => $q->where('feature', trim((string) $this->option('feature'))))
            ->get(['category', 'max_docs'])->groupBy('category')
            ->map(static fn ($g) => (int) ($g->first()->max_docs ?: 0));

        $ergebnis = [];
        foreach ($kategorien as $kategorie) {
            $treffer = $emb->searchScoredSlugs($query, [$kategorie], $top, $minScore);
            $quote = (int) ($maxDocs[$kategorie] ?? 0);
            $ergebnis[$kategorie] = ['quote' => $quote, 'treffer' => $treffer];

            if ($this->option('json')) {
                continue;
            }
            $this->line(sprintf('<info>%s</info>  (Quote: %s)',
                $kategorie, $quote > 0 ? $quote.' Doc(s)' : 'Service-Default'));
            if ($treffer === []) {
                $this->line('    — kein semantischer Treffer über min_score');
                $this->newLine();

                continue;
            }
            $i = 0;
            foreach ($treffer as $slug => $score) {
                $i++;
                // Marker: belegt dieser Treffer heute einen Platz im Prompt?
                $imPrompt = $quote > 0 && $i <= $quote;
                $this->line(sprintf('    %s %-7s %s',
                    $imPrompt ? '▶' : ' ',
                    number_format((float) $score, 4),
                    $slug,
                ));
            }
            $this->newLine();
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'team' => $team->id,
                'query' => $query,
                'floor_ist' => $bodenIst,
                'kanaele' => $ergebnis,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('  ▶ = belegt heute einen Platz im Prompt.');
        $this->line('  Boden setzen: FOODALCHEMIST_DISCOVERY_FLOOR zwischen den letzten fachlich');
        $this->line('  richtigen und den ersten fachlich falschen Treffer legen. Liegen die beiden');
        $this->line('  dicht beieinander, ist der Boden das falsche Werkzeug — dann Kanon prüfen.');

        return self::SUCCESS;
    }
}
