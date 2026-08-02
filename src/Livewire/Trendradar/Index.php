<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\TrendRadarService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Trendradar (Feature #FA-Trendradar): kuratierte Sicht auf die geclusterten
 * Trend-Wissens-Docs (knowledge_documents category='trend' ⋈ trend_meta).
 *
 * READ-ONLY — der Radar erfasst keine Trends (das ist das Office-Projekt), er
 * konsumiert die importierten + geclusterten. Navigation über den Kategorie→Klasse-
 * Baum, Dashboard „Top-Trends", Facetten (Reifegrad/Relevanz/Hype), Semantiksuche.
 * Datenzugriff wie im Knowledge-Browser über rohe DB::table + TeamScope.
 */
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kat')]
    public string $category = '';

    #[Url(as: 'klasse')]
    public string $trendClass = '';

    #[Url(as: 'reife')]
    public string $maturity = '';

    #[Url(as: 'rel')]
    public string $relevance = '';

    #[Url(as: 'hype')]
    public bool $onlyHype = false;

    #[Url(as: 'sem')]
    public bool $semantic = false;

    #[Url(as: 'doc')]
    public ?string $selectedSlug = null;

    /** UI-Labels (deutsch) über den englischen Schema-Werten. */
    public const MATURITY_LABELS = [
        'niche' => 'Nische', 'emerging' => 'Im Kommen', 'mainstream' => 'Mainstream', 'declining' => 'Abklingend',
    ];

    public const RELEVANCE_LABELS = ['high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Niedrig'];

    public function select(string $slug): void
    {
        $this->selectedSlug = $slug;
    }

    public function deselect(): void
    {
        $this->selectedSlug = null;
    }

    /** Klick im Taxonomie-Baum → Kategorie/Klasse-Filter setzen. */
    public function filterAuf(string $category, ?string $trendClass = null): void
    {
        $this->category = $category;
        $this->trendClass = $trendClass ?? '';
        $this->selectedSlug = null;
    }

    public function resetFilter(): void
    {
        $this->reset(['search', 'category', 'trendClass', 'maturity', 'relevance', 'onlyHype', 'semantic']);
    }

    public function render(TrendRadarService $radar, KnowledgeContextService $knowledge)
    {
        $suche = trim($this->search);

        // Semantik-Recall (wie Knowledge-Browser): Embedding-IDs, sonst SQL-LIKE.
        $semanticNote = null;
        $semanticIds = null;
        $semanticAktiv = false;
        if ($this->semantic && $suche !== '') {
            $svc = app(KnowledgeEmbeddingService::class);
            if ($svc->isProviderAvailable()) {
                $semanticAktiv = true;
                $semanticIds = $svc->searchDocIds($suche, 60);
                if ($semanticIds === []) {
                    $semanticNote = 'Keine semantischen Treffer — evtl. ist der Korpus noch nicht indiziert '
                        . '(php artisan foodalchemist:knowledge-embed).';
                }
            } else {
                $semanticNote = 'Semantische Suche nicht verfügbar (kein Embedding-Provider) — Textsuche aktiv.';
            }
        }

        $query = $radar->sichtbareTrends()
            ->when($this->category !== '', fn ($q) => $q->where('m.category', $this->category))
            ->when($this->trendClass !== '', fn ($q) => $q->where('m.trend_class', $this->trendClass))
            ->when($this->maturity !== '', fn ($q) => $q->where('m.maturity', $this->maturity))
            ->when($this->relevance !== '', fn ($q) => $q->where('m.relevance', $this->relevance))
            ->when($this->onlyHype, fn ($q) => $q->where('m.is_hype', 1));

        if ($semanticAktiv) {
            $query->whereIn('d.id', $semanticIds ?: [-1]);
        } elseif ($suche !== '') {
            $s = '%' . $suche . '%';
            $query->where(fn ($w) => $w->where('d.title', 'like', $s)
                ->orWhere('d.slug', 'like', $s)->orWhere('d.content_md', 'like', $s));
        }

        $rows = $query->get([
            'd.id', 'd.slug', 'd.title', 'd.updated_at',
            'm.category', 'm.trend_class', 'm.cluster_id', 'm.maturity',
            'm.is_hype', 'm.relevance', 'm.status', 'm.confidence',
        ]);

        // Rangfolge in PHP: Relevanz × Cluster-Größe (deterministisch, DB-agnostisch).
        $clusterSizes = $radar->clusterSizes();
        $gewicht = ['high' => 3, 'medium' => 2, 'low' => 1];
        $docs = $rows->map(function ($r) use ($clusterSizes, $gewicht) {
            $r->cluster_size = $r->cluster_id !== null ? ($clusterSizes[$r->cluster_id] ?? 1) : 1;
            $r->score = ($gewicht[$r->relevance] ?? 1) * 10 + $r->cluster_size;

            return $r;
        })->sortByDesc(fn ($r) => [$r->score, (string) $r->updated_at])->values();

        // Detail (team-sichtbar, per slug).
        $selected = null;
        $selectedHtml = null;
        $selectedMeta = [];
        if ($this->selectedSlug !== null) {
            $selected = TeamScope::applyVisible(
                DB::table('foodalchemist_knowledge_documents as d')
                    ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 'd.id')
                    ->where('d.slug', $this->selectedSlug)->where('d.category', 'trend')->whereNull('d.deleted_at'),
                'd.team_id', Auth::user()?->currentTeamRelation
            )->first([
                'd.slug', 'd.title', 'd.content_md', 'd.version',
                'm.category', 'm.trend_class', 'm.maturity', 'm.is_hype', 'm.relevance', 'm.status',
            ]);
            if ($selected !== null) {
                $selectedMeta = $knowledge->frontmatterOf((string) $selected->content_md);
                $selectedHtml = $this->renderMarkdown((string) $selected->content_md);
            }
        }

        return view('foodalchemist::livewire.trendradar.index', [
            'topTrends' => $radar->topTrends(6),
            'tree' => $radar->taxonomieBaum(),
            'docs' => $docs,
            'selected' => $selected,
            'selectedHtml' => $selectedHtml,
            'selectedMeta' => $selectedMeta,
            'selectedQuellen' => is_array($selectedMeta['quellen'] ?? null) ? $selectedMeta['quellen'] : [],
            'semanticNote' => $semanticNote,
            'semanticAktiv' => $semanticAktiv,
        ])->layout('platform::layouts.app');
    }

    /** Markdown der Trend-Datei ohne YAML-Frontmatter, safe gerendert (wie Knowledge-Browser). */
    private function renderMarkdown(string $md): string
    {
        $body = preg_replace('/\A\x{FEFF}?\s*---\R.*?\R---\R?/su', '', $md) ?? $md;
        $body = trim($body);

        return $body === '' ? '' : Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
