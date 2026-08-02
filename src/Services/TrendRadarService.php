<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Trendradar — Lese-Service über den geclusterten Trend-Bestand
 * (knowledge_documents category='trend' ⋈ foodalchemist_trend_meta).
 *
 * Zentrale Rangfolge „Top-Trends": geteilt zwischen UI-Dashboard und der
 * 08:00-Automatisierung, damit beide dieselben Trends als „heiß" sehen.
 * Score = Relevanz × Cluster-Größe (viele verwandte Signale = starker Trend),
 * Tiebreak Aktualität. Trend-Docs sind global (team_id=NULL).
 */
class TrendRadarService
{
    /** Frontmatter-/Meta-Relevanz → Gewicht. */
    private const RELEVANCE_WEIGHT = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * Top-N Trends nach Score (Relevanz × Cluster-Größe). Liefert flache Objekte
     * inkl. content_md (für die Brief-Erzeugung der Automatisierung).
     *
     * @return list<object>
     */
    public function topTrends(int $limit = 5): array
    {
        $limit = max(1, $limit);
        $clusterSize = $this->clusterSizes();

        $rows = DB::table('foodalchemist_knowledge_documents as d')
            ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 'd.id')
            ->where('d.category', 'trend')->where('d.active', 1)->whereNull('d.deleted_at')
            ->get([
                'd.id', 'd.slug', 'd.title', 'd.content_md', 'd.updated_at',
                'm.category', 'm.trend_class', 'm.cluster_id', 'm.maturity',
                'm.is_hype', 'm.relevance', 'm.confidence',
            ]);

        return $rows
            ->map(function ($r) use ($clusterSize) {
                $r->cluster_size = $r->cluster_id !== null ? ($clusterSize[$r->cluster_id] ?? 1) : 1;
                $r->score = (self::RELEVANCE_WEIGHT[$r->relevance] ?? 1) * 10 + $r->cluster_size;

                return $r;
            })
            ->sortByDesc(fn ($r) => [$r->score, (string) $r->updated_at])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Cluster-Größen (Docs je cluster_id) — als „Hitze"-Signal fürs Dashboard
     * und für die Top-Trend-Rangfolge.
     *
     * @return array<string,int>
     */
    public function clusterSizes(): array
    {
        return DB::table('foodalchemist_trend_meta')
            ->whereNotNull('cluster_id')
            ->select('cluster_id', DB::raw('COUNT(*) as n'))
            ->groupBy('cluster_id')
            ->pluck('n', 'cluster_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Basis-Query der team-sichtbaren Trend-Docs (global + eigene Kette) mit Meta —
     * gemeinsame Wurzel für die UI-Liste. TeamScope schließt globale Docs ein.
     */
    public function sichtbareTrends(): \Illuminate\Database\Query\Builder
    {
        return TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents as d')
                ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 'd.id')
                ->where('d.category', 'trend')->where('d.active', 1)->whereNull('d.deleted_at'),
            'd.team_id', Auth::user()?->currentTeamRelation
        );
    }

    /**
     * Taxonomie-Baum: Kategorien (Ebene 1) mit ihren Klassen (Ebene 2) + Doc-Zählern.
     *
     * @return Collection<int,object>
     */
    public function taxonomieBaum(): Collection
    {
        $counts = DB::table('foodalchemist_trend_meta')
            ->whereNotNull('trend_class')
            ->select('category', 'trend_class', DB::raw('COUNT(*) as n'))
            ->groupBy('category', 'trend_class')
            ->get();

        return DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('deleted_at')->where('active', 1)
            ->orderBy('category')->orderBy('sort_order')
            ->get(['category', 'trend_class', 'slug', 'status', 'description'])
            ->map(function ($row) use ($counts) {
                $row->doc_count = $row->trend_class === null
                    ? (int) $counts->where('category', $row->category)->sum('n')
                    : (int) ($counts->first(fn ($c) => $c->category === $row->category && $c->trend_class === $row->trend_class)->n ?? 0);

                return $row;
            });
    }
}
