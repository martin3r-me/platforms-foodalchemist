<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * Spec 52/E: ein Ranking, unabhängig von Einstieg und Kontextbudget.
 * Der Aufrufer liefert die zulässige Dokumentmenge (Sichtbarkeit, Art, Scope).
 * Lexik und Semantik erzeugen unabhängige Ranglisten; RRF vereinigt sie vor Top-K.
 */
class KnowledgeSearchService
{
    public const CANDIDATE_LIMIT = 100;
    public const RRF_K = 60;

    public function __construct(
        private readonly KnowledgeTokenizer $tokenizer,
        private readonly TerminologyService $terminology,
    ) {}

    /**
     * @param  ?string  $preferredAspect  Spec 53/H Aufgabe A: bei Aspekt-Familien (Slug-Schema
     *                                    `<entitaet>--<aspekt>`, z. B. `zutat.passion_fruit--verwendung`)
     *                                    gewinnt je Entität DIESER Aspekt statt des Score-Siegers, sofern
     *                                    vorhanden — null (Browser-/MCP-Suche ohne Prompt-Key-Bezug) lässt
     *                                    den Score entscheiden. Bewusst hier statt im Aufrufer geprüft:
     *                                    der Aufrufer liefert nur den fertig aufgelösten String, `search()`
     *                                    bleibt frei von Prompt-Key-Wissen (s. Klassen-Docblock).
     * @return list<array<string, mixed>> Metadaten und nachvollziehbare Ranganteile, kein Volltext
     */
    public function search(Builder $base, string $query, int $limit, bool $semantic = true, ?Team $team = null, ?string $preferredAspect = null): array
    {
        $tokens = $this->tokenizer->tokenize($query);
        if (trim($query) === '' || $limit <= 0) {
            return [];
        }
        // Spec 53 Aufgabe 3 (Kompositum-Grenze): "tomatensuppe" ist EIN Token und matcht
        // "tomate"/"suppe"-Dossiers weder per Jaccard noch per (bis dahin einseitiger)
        // Substring-Regel. Decompoundierung mit demselben Baustein wie das Zutaten-Matching
        // (TerminologyService::decompoundPhrasesFor, s. IngredientMatchService) — ERWEITERT die
        // Query-Tokens, verdrängt keinen; ein Kompositum ohne bekannten Kopf (COMPOUND_HEADS)
        // bleibt unverändert.
        $decompoundTokens = [];
        foreach ($tokens as $token) {
            foreach ($this->terminology->decompoundPhrasesFor($token) as $phrase) {
                array_push($decompoundTokens, ...$this->tokenizer->tokenize($phrase));
            }
        }
        if ($decompoundTokens !== []) {
            $tokens = array_values(array_unique([...$tokens, ...$decompoundTokens]));
        }
        $columns = ['id', 'slug', 'title', 'category', 'active', 'version', 'char_count'];
        if (Schema::hasColumn('foodalchemist_knowledge_documents', 'art')) {
            $columns[] = 'art';
        }
        $docs = (clone $base)->reorder()->get($columns)->keyBy('id');
        if ($docs->isEmpty()) {
            return [];
        }
        $candidateLimit = max(1, min(1000, (int) config('foodalchemist.knowledge_search.candidate_limit', self::CANDIDATE_LIMIT)));
        $aliases = DB::table('foodalchemist_knowledge_aliases')->whereIn('knowledge_document_id', $docs->keys()->all())
            ->get(['knowledge_document_id', 'alias_slug'])->groupBy('knowledge_document_id');
        $lexical = [];
        foreach ($docs as $id => $doc) {
            $words = $this->tokenizer->tokenize($doc->slug.' '.$doc->title);
            $overlap = count(array_intersect($tokens, $words));
            $union = count(array_unique([...$tokens, ...$words]));
            // Beidseitig ab 5 Zeichen (Spec 53 Aufgabe 3): vorher nur $word CONTAINS $token — ein
            // kurzes Doc-Wort wie "tomate" (6) kann kein längeres Query-Kompositum wie
            // "tomatensuppe" (12) enthalten, obwohl die Decompoundierung genau das nicht immer
            // auflöst (unbekannte Köpfe). Symmetrisch behoben, mit derselben Mindestlänge auf
            // beiden Seiten, damit kein kurzes Funktionswort per Substring durchrutscht.
            $substringHits = count(array_filter($tokens, static fn ($token) => mb_strlen($token) >= 5
                && count(array_filter($words, static fn ($word) => mb_strlen($word) >= 5
                    && (str_contains($word, $token) || str_contains($token, $word)))) > 0));
            $alias = false;
            foreach ($aliases->get($id, collect()) as $row) {
                if (array_intersect($tokens, $this->tokenizer->tokenize($row->alias_slug)) !== []) {
                    $alias = true;
                    break;
                }
            }
            $score = ($union === 0 ? 0.0 : $overlap / $union) + 0.1 * $substringHits + ($alias ? 1.0 : 0.0);
            if ($score > 0 && $score >= (float) config('foodalchemist.knowledge_search.min_lexical_score', 0.05)) {
                $lexical[$id] = ['score' => $score, 'alias' => $alias];
            }
        }
        uksort($lexical, static fn ($a, $b) => ($lexical[$b]['score'] <=> $lexical[$a]['score'])
            ?: strcmp($docs[$a]->slug, $docs[$b]->slug));
        $lexical = array_slice($lexical, 0, $candidateLimit, true);
        $lexRanks = array_flip(array_keys($lexical));

        // Immer unabhängig ermitteln, auch wenn die Lexik bereits alle Endplätze füllt.
        $semanticIds = $semantic
            ? app(KnowledgeEmbeddingService::class)->searchEligibleDocIds($query, $docs->keys()->all(), $candidateLimit, $team)
            : [];
        $semRanks = array_flip($semanticIds);
        $hits = [];
        foreach (array_unique([...array_keys($lexical), ...$semanticIds]) as $id) {
            if (! isset($docs[$id])) {
                continue;
            }
            $lexRank = isset($lexRanks[$id]) ? $lexRanks[$id] + 1 : null;
            $semRank = isset($semRanks[$id]) ? $semRanks[$id] + 1 : null;
            $hits[] = (array) $docs[$id] + [
                'score' => ($lexRank === null ? 0.0 : 1 / (self::RRF_K + $lexRank))
                    + ($semRank === null ? 0.0 : 1 / (self::RRF_K + $semRank)),
                'lexical_score' => $lexical[$id]['score'] ?? null,
                'lexical_rank' => $lexRank,
                'semantic_rank' => $semRank,
                'via' => $lexRank !== null && $semRank !== null ? 'hybrid'
                    : ($semRank !== null ? 'semantic' : (($lexical[$id]['alias'] ?? false) ? 'alias' : 'lexical')),
                'candidate_limit' => $candidateLimit,
            ];
        }
        usort($hits, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['slug'], $b['slug']));

        // Spec 53/H Aufgabe A (Gruppen-Dedup): eine Aspekt-Familie (`zutat.passion_fruit--steckbrief`,
        // `--verwendung`, `--verhalten`, `--cave`, …) darf nicht mehrere Plätze derselben Entität
        // belegen und damit eine zweite Entität oder ein Technik-Dossier verdrängen — gemessen am
        // "Passionsfrucht-Gelee mit Acerola"-Brief: `acerola` belegte alle 4 Aspekte, `passion_fruit`
        // nur 2, das Gelée-Technik-Dossier fiel ganz raus. Entität = Slug-Präfix vor `--`; Docs ohne
        // `--` sind ihre eigene Entität (Singleton-Gruppe, unverändert im Ranking — alte
        // Domain-Dossiers ohne Aspekt-Schema betrifft das nicht). Innerhalb einer Gruppe gewinnt der
        // zu `$preferredAspect` passende Aspekt, sonst der Score-Sieger der Gruppe (mit
        // `aspekt_fallback: true`, damit die Herkunfts-Anzeige den Rückfall nicht verschweigt).
        // Ergänzt, ersetzt NICHT, den dedizierten Grounding-Block für `zutat` (Aufgabe B) — der lädt
        // pro Hauptzutat deterministisch genau ein Dossier; dieser Dedup hier ist der generische
        // Schutz im Discovery-Pfad für JEDE aktuelle oder künftige `--`-Aspekt-Familie und für den
        // Übergang, solange `zutat` noch discovery-geroutet sein sollte. Zwei Mechanismen, eine Rolle
        // jeweils — nicht als Doppelung ausbauen.
        $gruppen = [];
        foreach ($hits as $hit) {
            $entitaet = str_contains($hit['slug'], '--') ? strstr($hit['slug'], '--', true) : $hit['slug'];
            $gruppen[$entitaet][] = $hit;
        }
        $dedupliziert = [];
        foreach ($gruppen as $gruppe) {
            if (count($gruppe) === 1) {
                $dedupliziert[] = $gruppe[0];
                continue;
            }
            $vertreter = null;
            if ($preferredAspect !== null) {
                foreach ($gruppe as $hit) {
                    if (str_ends_with($hit['slug'], "--{$preferredAspect}")) {
                        $vertreter = $hit;
                        break;
                    }
                }
            }
            if ($vertreter === null) {
                // $gruppe[0] ist der Score-Sieger: die Gruppen entstehen aus dem bereits
                // score-sortierten $hits, die Gruppierung erhält diese Reihenfolge je Gruppe.
                $vertreter = $gruppe[0];
                if ($preferredAspect !== null) {
                    $vertreter['aspekt_fallback'] = true;
                }
            }
            $dedupliziert[] = $vertreter;
        }
        usort($dedupliziert, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['slug'], $b['slug']));

        return array_slice($dedupliziert, 0, $limit);
    }
}
