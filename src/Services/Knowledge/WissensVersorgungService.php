<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/**
 * Spec 52 · A2 — WAS erreicht diesen Prompt-Key an Wissen? Eine Antwort, zwei Flächen.
 *
 * Die Rechnung lebt hier und nicht im Kommando, weil sie zwei Abnehmer hat: das Kommando
 * `foodalchemist:wissen-versorgung` (Mensch am Terminal) und das Tool
 * `foodalchemist.knowledge_versorgung.GET` (Agent, und der einzige Weg auf demo — dort gibt es
 * keine Shell). Zwei Implementierungen derselben Frage wären genau die Doppelung, die diese
 * Spec abbaut.
 *
 * Drei Steuertabellen antworten heute unabhängig voneinander:
 *   · `foodalchemist_knowledge_canon`    — „muss rein" (neu, Spec 50)
 *   · `foodalchemist_knowledge_routings` — „darf gesucht werden" (feature × category)
 *   · `foodalchemist_knowledge_bindings` — „ist angeheftet" (alt, #469, Fallback ohne Kanon)
 *
 * Dazu zwei Schlüsselräume im selben Aufruf: der Kanon wird über den **Prompt-Key** aufgelöst,
 * das Routing über ein hartkodiertes **Alt-Feature** ({@see KnowledgeContextService::ROUTING_ALIAS}).
 *
 * ⚠ **`ungesteuert` heisst „kein Dossier erreicht diesen Prompt", nicht „der Prompt hat keine
 * Regeln".** Ein Prompt-Task kann Regeln im Text tragen. Die Aussage ist: aus dem Wissens-Korpus
 * kommt nichts.
 */
class WissensVersorgungService
{
    /** Verdikte, in der Reihenfolge ihrer Schwere. */
    public const VERDIKTE = ['gesteuert', 'none', 'nur-bindung', 'UNGESTEUERT'];

    public function __construct(
        private readonly KnowledgeCanonService $canon,
        private readonly KnowledgeContextService $wissen,
        private readonly AiGatewayService $gateway,
    ) {}

    /**
     * Der ganze Bericht über die Prompt-Registry.
     *
     * @return array{keys: int, gesteuert: int, none: int, nur_bindung: int, ungesteuert: int,
     *   routing_features: array{ohne_prompt_key: list<string>, aufrufer_eigenname: list<string>,
     *   ohne_aufrufer_gemessen: list<string>}, zeilen: list<array<string, mixed>>}
     */
    public function bericht(Team $team, ?string $praefix = null): array
    {
        $registry = (array) config('foodalchemist.prompts', []);
        $zeilen = [];

        foreach (array_keys($registry) as $promptKey) {
            $promptKey = (string) $promptKey;
            $bereich = $this->bereich($promptKey);
            if ($praefix !== null && $praefix !== '' && $bereich !== $praefix) {
                continue;
            }
            $zeilen[] = $this->zeileFuer($promptKey, $team);
        }

        $zaehl = fn (string $v) => count(array_filter($zeilen, fn ($z) => $z['verdikt'] === $v));

        return [
            'keys' => count($zeilen),
            'gesteuert' => $zaehl('gesteuert'),
            'none' => $zaehl('none'),
            'nur_bindung' => $zaehl('nur-bindung'),
            'ungesteuert' => $zaehl('UNGESTEUERT'),
            'routing_features' => $this->routingFeaturesOhnePromptKey(),
            'zeilen' => $zeilen,
        ];
    }

    /**
     * Eine Zeile: was erreicht genau diesen Prompt-Key.
     *
     * @return array<string, mixed>
     */
    public function zeileFuer(string $promptKey, Team $team): array
    {
        $bereich = $this->bereich($promptKey);
        $routingKey = KnowledgeContextService::routingFeatureFuer($promptKey);

        // Kanon über den SERVICE, nicht per eigener Query: die Tenancy-Regel (global ∪
        // Ahnenkette, Team-Zeile gewinnt) lebt dort, und die Dev-MySQL hat vorgemacht, was eine
        // zweite Query kostet — sie trug noch das v1-Schema `knowledge_section_id`.
        $kanon = $this->canon->documentsFor('prompt_key', $promptKey, $team);

        $routing = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $routingKey)->orderBy('category')
            ->get(['category', 'mode', 'max_docs', 'max_chars_per_doc'])
            ->map(fn ($r) => [
                'category' => (string) $r->category,
                'mode' => (string) $r->mode,
                'max_docs' => $r->max_docs !== null ? (int) $r->max_docs : null,
                'max_chars_per_doc' => $r->max_chars_per_doc !== null ? (int) $r->max_chars_per_doc : null,
            ])->all();

        // Bindung: Prompt-Key (fein) ODER Bereichs-Präfix (grob) — genau wie AiGatewayService:179.
        $bindungen = DB::table('foodalchemist_knowledge_bindings as b')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
            ->whereNull('b.deleted_at')->where('b.active', 1)->where('b.binding_type', 'layer')
            ->whereIn('b.target_key', array_unique([$promptKey, $bereich]))
            ->whereNull('d.deleted_at')
            ->get(['d.slug', 'd.active as doc_active', 'b.target_key']);

        $wirksamesRouting = array_values(array_filter($routing, fn ($r) => $r['mode'] !== 'none'));
        $bindungenTot = $bindungen->filter(fn ($b) => (int) $b->doc_active !== 1)->count();
        $bindungenLebend = $bindungen->count() - $bindungenTot;

        // Rangfolge wie im Gateway: Kanon gewinnt; Bindungen sind Fallback und bei vorhandenem
        // Kanon STUMM (AiGatewayService:178). Eine Bindung an einem Key mit Kanon ist deshalb
        // keine Versorgung, sondern Ballast.
        $kanonDa = $kanon->isNotEmpty();
        $verdikt = match (true) {
            $kanonDa || $wirksamesRouting !== [] => 'gesteuert',
            $bindungenLebend > 0 => 'nur-bindung',
            $routing !== [] => 'none',
            default => 'UNGESTEUERT',
        };

        return [
            'prompt_key' => $promptKey,
            'bereich' => $bereich,
            'routing_key' => $routingKey,
            'alt_schluessel' => $routingKey !== $promptKey,
            'kanon_docs' => $kanon->count(),
            'kanon_chars' => (int) $kanon->sum('char_count'),
            'kanon_pflicht' => $kanon->where('mode', 'pflicht')->count(),
            'kanon_slugs' => $kanon->pluck('slug')->all(),
            'routing' => $routing,
            'bindungen' => $bindungen->count(),
            'bindungen_tot' => $bindungenTot,
            'bindungen_stumm' => $kanonDa ? $bindungenLebend : 0,
            'bindungs_slugs' => $bindungen->pluck('slug')->all(),
            'budget_bound' => (int) $this->gateway->boundBudgetFuer($promptKey)['total'],
            'budget_retrieval' => $this->wissen->budgetFuer($routingKey),
            'verdikt' => $verdikt,
        ];
    }

    /**
     * Routing-Features, die auf KEINEN Prompt-Key auflösen.
     *
     * ⚠ **Das heisst nicht „ohne Aufrufer".** Ob ein Feature wirklich niemand ruft, lässt sich
     * statisch nicht abschliessend feststellen: mehrere Stellen rufen mit einer Variablen
     * (`contextFor($team, $promptKey, …)`, `contextFor($this->team(), $prompt, …)`). Genau in
     * diese Falle ist meine erste Fassung gelaufen — sie meldete `foodbook.plan` als tot,
     * obwohl `IdeenService` es live benutzt; es ist bloss kein Registry-Key.
     *
     * Deshalb zwei getrennte Aussagen:
     *   · `ohne_prompt_key` — aus den Daten ableitbar: kein Registry-Key, kein bekannter Alias,
     *     kein dokumentierter Aufrufer-Eigenname.
     *   · `ohne_aufrufer_gemessen` — ein `grep`-Befund vom 2026-09-07, als Datum geführt
     *     ({@see KnowledgeContextService::OHNE_AUFRUFER_GEMESSEN}), nicht als Wahrheit.
     *
     * @return array{ohne_prompt_key: list<string>, aufrufer_eigenname: list<string>, ohne_aufrufer_gemessen: list<string>}
     */
    public function routingFeaturesOhnePromptKey(): array
    {
        $registry = array_map('strval', array_keys((array) config('foodalchemist.prompts', [])));
        $bekannt = $registry;
        foreach ($registry as $key) {
            $bekannt[] = KnowledgeContextService::routingFeatureFuer($key);
        }
        $eigennamen = array_keys(KnowledgeContextService::CONTEXT_ONLY_FEATURES);
        $bekannt = array_unique(array_merge($bekannt, $eigennamen));

        $features = DB::table('foodalchemist_knowledge_routings')
            ->distinct()->pluck('feature')->map(fn ($f) => (string) $f)->all();

        $ohneAufrufer = array_values(array_intersect($features, KnowledgeContextService::OHNE_AUFRUFER_GEMESSEN));

        return [
            // Nur die UNKLAREN: was schon als „ohne Aufrufer" gemessen ist, steht dort und muss
            // hier nicht doppelt erscheinen — zwei Listen mit denselben Namen sind Rauschen.
            'ohne_prompt_key' => array_values(array_diff($features, $bekannt, $ohneAufrufer)),
            'aufrufer_eigenname' => array_values(array_intersect($features, $eigennamen)),
            'ohne_aufrufer_gemessen' => $ohneAufrufer,
        ];
    }

    private function bereich(string $promptKey): string
    {
        return str_contains($promptKey, '.') ? explode('.', $promptKey, 2)[0] : $promptKey;
    }
}
