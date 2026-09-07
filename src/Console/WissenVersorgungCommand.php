<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;

/**
 * Spec 52 · A2 — der Versorgungs-Bericht: was bekommt jeder Prompt-Key TATSÄCHLICH an Wissen?
 *
 * Diese Frage konnte bis jetzt **niemand** beantworten, und das ist die Ursache unter allen
 * Symptomen. Drei Steuertabellen antworten unabhängig voneinander:
 *   · `foodalchemist_knowledge_canon`    — „muss rein" (neu, Spec 50)
 *   · `foodalchemist_knowledge_routings` — „darf gesucht werden" (feature × category)
 *   · `foodalchemist_knowledge_bindings` — „ist angeheftet" (alt, #469, Fallback ohne Kanon)
 *
 * Dazu kommen zwei Schlüsselräume im selben Aufruf: der Gateway löst den Kanon über den
 * **Prompt-Key** auf (`recipe.generator`), das Routing wird aber mit einem hartkodierten
 * **Alt-Feature** gerufen (`RecipeGenerationContextService:88` übergibt `ai_generate_recipe`
 * für Basisrezept UND Gericht). Eine Routing-Zeile auf `vk.generator` würde also stumm ins
 * Leere schreiben — sichtbar wird das erst hier.
 *
 * **Gegen die Registry, nicht gegen die Doku.** Der Bericht läuft über
 * `config('foodalchemist.prompts')`. Die Funktionsmatrix in `docs/PLANUNG/26_*` nennt 22
 * `recipe.*` und 15 `vk.*`; real sind es 23 und 13, und `vk.behaelter` steht dort noch, obwohl
 * Spec 50 es abgeschafft hat. Wer gegen die Doku prüft, prüft einen alten Stand.
 *
 * Verdikt je Zeile:
 *   · `gesteuert`     — Kanon und/oder Routing greifen
 *   · `none`          — ausdrücklich leer geroutet (bewusste Entscheidung, z. B. ai_extract_recipe)
 *   · `nur-bindung`   — kein Kanon, kein Routing, aber eine Bindung hängt dran (Alt-Struktur)
 *   · `UNGESTEUERT`   — nichts von allem. Das ist ein **Befund**, keine Leerzeile.
 *
 * **Abgrenzung zu `foodalchemist:wissen-deckung` (W2-4).** Das prüft die KORPUS-Richtung:
 * nennt ein Prompt-Task einen §, muss der Korpus ein Dossier dazu haben (der §12-Fall). Dieser
 * Befehl prüft die VERSORGUNGS-Richtung: erreicht diesen Prompt überhaupt Wissen, und welches.
 * Zwei Richtungen desselben Problems, deshalb zwei Befehle — nicht einer mit zwei Modi.
 *
 * Rein lesend. Exit-Code 1, sobald ungesteuerte Keys existieren, damit ein Scheduler-Lauf
 * sichtbar fehlschlägt statt still durchzulaufen.
 */
class WissenVersorgungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-versorgung
        {--team=6 : Team-Kontext für die Kanon-Sichtbarkeit (global ∪ Ahnenkette)}
        {--praefix= : nur Keys mit diesem Bereichs-Präfix (z. B. recipe, vk, concept)}
        {--nur-befunde : nur ungesteuerte Keys ausgeben}
        {--json : Maschinenlesbar ausgeben (für Vorher/Nachher-Vergleiche)}';

    protected $description = 'Spec 52/A2: zeigt je Prompt-Key, welches Wissen ihn erreicht (Kanon, Routing, Bindung, Budget)';

    public function handle(KnowledgeCanonService $canon): int
    {
        $registry = config('foodalchemist.prompts', []);
        if (! is_array($registry) || $registry === []) {
            $this->error('config(foodalchemist.prompts) ist leer — falscher Kontext?');

            return 1;
        }

        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            // Ohne Nutzer greift nur die globale Partition — der Kanon sähe fast leer aus und
            // der Bericht behauptete eine Deckungslücke, die es nicht gibt. Lieber abbrechen.
            $this->error('Kein Team #'.$this->option('team').' — ohne Team-Kontext ist die Kanon-Sicht nicht aussagekräftig.');

            return 1;
        }

        $praefixFilter = (string) ($this->option('praefix') ?? '');
        $zeilen = [];

        foreach (array_keys($registry) as $promptKey) {
            $promptKey = (string) $promptKey;
            $bereich = str_contains($promptKey, '.') ? explode('.', $promptKey, 2)[0] : $promptKey;
            if ($praefixFilter !== '' && $bereich !== $praefixFilter) {
                continue;
            }

            $zeilen[] = $this->zeileFuer($promptKey, $bereich, $team, $canon);
        }

        $ungesteuert = array_values(array_filter($zeilen, fn ($z) => $z['verdikt'] === 'UNGESTEUERT'));
        $nurBindung = array_values(array_filter($zeilen, fn ($z) => $z['verdikt'] === 'nur-bindung'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'keys' => count($zeilen),
                'ungesteuert' => count($ungesteuert),
                'nur_bindung' => count($nurBindung),
                'zeilen' => $zeilen,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ungesteuert === [] ? 0 : 1;
        }

        $ausgabe = $this->option('nur-befunde') ? $ungesteuert : $zeilen;

        if ($ausgabe !== []) {
            $this->table(
                ['Prompt-Key', 'Routing-Schlüssel', 'Kanon', 'Routing', 'Bindung', 'Budget (bound/retr.)', 'Verdikt'],
                array_map(fn ($z) => [
                    $z['prompt_key'],
                    $z['routing_key'] === $z['prompt_key'] ? '=' : $z['routing_key'].' (alt)',
                    $z['kanon_docs'] > 0 ? $z['kanon_docs'].' · '.number_format($z['kanon_chars'], 0, ',', '.').' Z.' : '–',
                    $z['routing'] === [] ? '–' : implode(', ', array_map(
                        fn ($r) => $r['category'].':'.$r['mode'].($r['max_docs'] ? ' '.$r['max_docs'].'×'.$r['max_chars'] : ''),
                        $z['routing']
                    )),
                    $z['bindungen'] > 0 ? $z['bindungen'].($z['bindungen_tot'] > 0 ? " ({$z['bindungen_tot']} tot)" : '') : '–',
                    number_format($z['budget_bound'], 0, ',', '.').' / '.number_format($z['budget_retrieval'], 0, ',', '.'),
                    $z['verdikt'],
                ], $ausgabe)
            );
        }

        $this->newLine();
        $this->info(sprintf(
            'Registry: %d Prompt-Keys%s · gesteuert %d · bewusst none %d · nur Bindung %d · UNGESTEUERT %d',
            count($zeilen),
            $praefixFilter !== '' ? " (Präfix «{$praefixFilter}»)" : '',
            count(array_filter($zeilen, fn ($z) => $z['verdikt'] === 'gesteuert')),
            count(array_filter($zeilen, fn ($z) => $z['verdikt'] === 'none')),
            count($nurBindung),
            count($ungesteuert),
        ));

        if ($nurBindung !== []) {
            $this->warn('Nur über die ALT-Struktur versorgt (Bindung, kein Kanon/Routing) — Spec 52/F1:');
            foreach ($nurBindung as $z) {
                $this->line("  · {$z['prompt_key']} — {$z['bindungen']} Bindung(en)"
                    .($z['bindungen_tot'] > 0 ? ", davon {$z['bindungen_tot']} auf INAKTIVE Dossiers" : ''));
            }
        }

        if ($ungesteuert !== []) {
            $this->error(count($ungesteuert).' Prompt-Key(s) erhalten weder Kanon noch Routing noch Bindung:');
            foreach ($ungesteuert as $z) {
                // Eine Bindung auf ein deaktiviertes Dossier ist der SCHLIMMERE Fall: es sieht
                // im Browser nach Verdrahtung aus und liefert nichts. Genau so ist beim
                // 155-Originale-Cutover still Wissen verschwunden — deshalb eigene Zeile,
                // nicht nur eine Tabellenzelle.
                $totNote = $z['bindungen_tot'] > 0
                    ? " — ACHTUNG: {$z['bindungen_tot']} tot(e) Bindung(en) auf INAKTIVE Dossiers ("
                        .implode(', ', $z['bindungs_slugs']).')'
                    : '';
                $this->line("  · {$z['prompt_key']}{$totNote}");
            }
            $this->newLine();
            $this->line('Jede Zeile braucht eine Entscheidung: Kanon-Zeile, Routing-Zeile oder ausdrücklich `none`.');

            return 1;
        }

        $this->info('Kein ungesteuerter Prompt-Key.');

        return 0;
    }

    /** @return array<string, mixed> */
    private function zeileFuer(string $promptKey, string $bereich, Team $team, KnowledgeCanonService $canon): array
    {
        // Eine Auflösung für Bericht und Auskunft — siehe KnowledgeContextService::ROUTING_ALIAS.
        $routingKey = KnowledgeContextService::routingFeatureFuer($promptKey);

        // Kanon über den SERVICE, nicht per eigener Query: der Gateway löst genau
        // scope='prompt_key', role='root' auf (AiGatewayService:159), und die Tenancy-Regel
        // (global ∪ Ahnenkette, Team-Zeile gewinnt) lebt in KnowledgeCanonService. Eine zweite
        // Query hier wäre eine zweite Wahrheit — und würde beim nächsten Schema-Schritt
        // auseinanderlaufen, wie es die Dev-MySQL mit `knowledge_section_id` vorgemacht hat.
        $kanon = $canon->documentsFor('prompt_key', $promptKey, $team);

        $routing = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $routingKey)
            ->orderBy('category')
            ->get(['category', 'mode', 'max_docs', 'max_chars_per_doc']);

        // Bindung: Prompt-Key (fein) ODER Bereichs-Präfix (grob) — genau wie AiGatewayService:179.
        $bindungen = DB::table('foodalchemist_knowledge_bindings as b')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
            ->whereNull('b.deleted_at')->where('b.active', 1)
            ->where('b.binding_type', 'layer')
            ->whereIn('b.target_key', array_unique([$promptKey, $bereich]))
            ->whereNull('d.deleted_at')
            ->get(['d.slug', 'd.active as doc_active']);

        $budgetBound = app(AiGatewayService::class)->boundBudgetFuer($promptKey);
        $budgetRetrieval = app(KnowledgeContextService::class)->budgetFuer($routingKey);

        $routingListe = $routing->map(fn ($r) => [
            'category' => (string) $r->category,
            'mode' => (string) $r->mode,
            'max_docs' => $r->max_docs !== null ? (int) $r->max_docs : null,
            'max_chars' => $r->max_chars_per_doc !== null ? (int) $r->max_chars_per_doc : null,
        ])->all();

        $wirksamesRouting = array_values(array_filter($routingListe, fn ($r) => $r['mode'] !== 'none'));
        $bindungenTot = $bindungen->filter(fn ($b) => (int) $b->doc_active !== 1)->count();
        $bindungenLebend = $bindungen->count() - $bindungenTot;

        $verdikt = match (true) {
            $kanon->isNotEmpty() || $wirksamesRouting !== [] => 'gesteuert',
            $bindungenLebend > 0 => 'nur-bindung',
            $routingListe !== [] => 'none',
            default => 'UNGESTEUERT',
        };

        return [
            'prompt_key' => $promptKey,
            'bereich' => $bereich,
            'routing_key' => $routingKey,
            'kanon_docs' => $kanon->count(),
            'kanon_chars' => (int) $kanon->sum('char_count'),
            'kanon_pflicht' => $kanon->where('mode', 'pflicht')->count(),
            'kanon_slugs' => $kanon->pluck('slug')->all(),
            'routing' => $routingListe,
            'bindungen' => $bindungen->count(),
            'bindungen_tot' => $bindungenTot,
            'bindungs_slugs' => $bindungen->pluck('slug')->all(),
            'budget_bound' => (int) $budgetBound['total'],
            'budget_retrieval' => $budgetRetrieval,
            'verdikt' => $verdikt,
        ];
    }
}
