<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * Planungs-/Kreativ-Session (Doppel-Diamant, Spec 08): CRUD + Trend-Einstieg + Lineage.
 *
 * Die Session ist der owner-lose Container der Divergenz-Phase (Analyse + Skizzen + Planung).
 * Sie erdet NICHTS — das „Go" (Livewire, P3) ruft die bestehenden Erzeugungs-Services und meldet
 * das Ergebnis hier über {@see verknuepfeArtefakt} zurück (Lineage: Trend → Entwurf). Team-lokal
 * (D1: visibleToTeam/isOwnedBy übers Model-Trait).
 */
class PlanningSessionService
{
    public function create(Team $team, array $in): FoodAlchemistPlanningSession
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Planungs-Titel ist Pflicht.');
        }
        $mode = (string) ($in['creative_mode'] ?? 'voll_kreativ');

        return FoodAlchemistPlanningSession::create([
            'team_id' => $team->id,
            'title' => $title,
            'brief' => $this->clean($in['brief'] ?? null),
            'analysis' => $this->clean($in['analysis'] ?? null),
            'source_knowledge_document_id' => isset($in['source_knowledge_document_id']) ? (int) $in['source_knowledge_document_id'] : null,
            'creative_mode' => in_array($mode, FoodAlchemistPlanningSession::CREATIVE_MODES, true) ? $mode : 'voll_kreativ',
            'status' => 'divergenz',
            'created_via' => (string) ($in['created_via'] ?? 'ui'),
        ]);
    }

    /**
     * Session aus einem Trend eröffnen — der Trend-Kontext wandert mit: Titel, ein Start-Brief,
     * und der Trend-Inhalt als Analyse-Text (der User liest/plant daran). Setzt die Herkunft
     * (`source_knowledge_document_id`), die beim „Go" an die Artefakte durchgereicht wird.
     */
    public function ausTrend(Team $team, int $knowledgeDocumentId): FoodAlchemistPlanningSession
    {
        $doc = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')
                ->where('category', 'trend')->where('active', 1)->whereNull('deleted_at'),
            'team_id', $team
        )->where('id', $knowledgeDocumentId)->first(['id', 'title', 'content_md']);

        if ($doc === null) {
            throw new RuntimeException('Trend nicht gefunden oder nicht sichtbar.');
        }

        $fm = app(KnowledgeContextService::class)->frontmatterOf((string) $doc->content_md);
        $body = $this->bodyAuszug((string) $doc->content_md, 1200);
        $quellen = is_array($fm['quellen'] ?? null) ? implode("\n", array_map(fn ($q) => '- ' . $q, $fm['quellen'])) : '';

        $analyse = trim($body . ($quellen !== '' ? "\n\nQuellen:\n" . $quellen : ''));

        return $this->create($team, [
            'title' => (string) $doc->title,
            'brief' => "Aus diesem Food-Trend ein Konzept/Gericht/Basisrezept entwickeln: {$doc->title}.",
            'analysis' => $analyse,
            'source_knowledge_document_id' => (int) $doc->id,
            'created_via' => 'trend',
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistPlanningSession
    {
        $session = $this->ownedSession($team, $id);
        $patch = [];
        foreach (['title', 'brief', 'analysis'] as $feld) {
            if (array_key_exists($feld, $in)) {
                $wert = $feld === 'title' ? trim((string) $in[$feld]) : $this->clean($in[$feld]);
                if ($feld === 'title' && $wert === '') {
                    throw new RuntimeException('Titel darf nicht leer sein.');
                }
                $patch[$feld] = $wert;
            }
        }
        if ($patch !== []) {
            $session->update($patch);
        }

        return $session->refresh();
    }

    public function setStatus(Team $team, int $id, string $status): FoodAlchemistPlanningSession
    {
        if (! in_array($status, FoodAlchemistPlanningSession::STATUSES, true)) {
            throw new RuntimeException("Ungültiger Status «{$status}».");
        }
        $session = $this->ownedSession($team, $id);
        $session->update(['status' => $status]);

        return $session->refresh();
    }

    public function setCreativeMode(Team $team, int $id, string $mode): FoodAlchemistPlanningSession
    {
        if (! in_array($mode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            throw new RuntimeException("Ungültiger Kreativ-Modus «{$mode}».");
        }
        $session = $this->ownedSession($team, $id);
        $session->update(['creative_mode' => $mode]);

        return $session->refresh();
    }

    /**
     * Richtungs-Regler (Leitplanken) der Session setzen — gesetzt am Planung-Go,
     * vererbt in den Kaskaden-Fan-out (siehe PlanningCascadeService). Gefiltert gegen
     * ALLOWED_GENERATION_PARAMS (kein beliebiges JSON); leere/leerwertige Auswahl → null
     * (kein leeres {} persistieren, damit der Fan-out sauber auf „keine Regler" fällt).
     */
    public function setGenerationParams(Team $team, int $id, array $params): FoodAlchemistPlanningSession
    {
        $session = $this->ownedSession($team, $id);
        $session->update(['generation_params' => $this->filterGenerationParams($params)]);

        return $session->refresh();
    }

    /** Whitelist + Leerwert-Filter für die Richtungs-Regler. @return array|null */
    public function filterGenerationParams(array $params): ?array
    {
        $gefiltert = array_intersect_key(
            $params,
            array_flip(FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS)
        );
        $gefiltert = array_filter($gefiltert, static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $gefiltert === [] ? null : $gefiltert;
    }

    /**
     * Lineage nach dem „Go": das erzeugte Artefakt bekommt die Trend-Herkunft (first-class FK),
     * eine ggf. materialisierte Skizze wird verknüpft, die Session geht auf „konvergenz".
     * Setzt NICHTS anderes am Artefakt (Erzeugung selbst macht der jeweilige Service, draft).
     */
    public function verknuepfeArtefakt(FoodAlchemistPlanningSession $session, string $art, int $artefaktId, ?int $ideaId = null): void
    {
        $trendId = $session->source_knowledge_document_id;

        if ($art === 'recipe') {
            FoodAlchemistRecipe::whereKey($artefaktId)->update([
                'source_knowledge_document_id' => $trendId,
                'created_via' => 'plan_go',
            ]);
            if ($ideaId !== null) {
                FoodAlchemistDishIdea::whereKey($ideaId)->update([
                    'generated_recipe_id' => $artefaktId,
                    'generation_status' => 'erstellt',
                    'materialized_at' => now(),
                    'materialized_ref' => ['recipe_id' => $artefaktId],
                    'status' => 'freigegeben',
                ]);
            }
        } elseif ($art === 'concept') {
            // Concept trägt created_via schon aus generiereAusBrief (…_plan_go) — nur die Herkunft-FK.
            FoodAlchemistConcept::whereKey($artefaktId)->update([
                'source_knowledge_document_id' => $trendId,
            ]);
            if ($ideaId !== null) {
                FoodAlchemistDishIdea::whereKey($ideaId)->update([
                    'materialized_concept_id' => $artefaktId,
                    'materialized_at' => now(),
                    'materialized_ref' => ['concept_id' => $artefaktId],
                    'status' => 'freigegeben',
                ]);
            }
        } else {
            throw new RuntimeException("Unbekannter Artefakt-Typ «{$art}».");
        }

        if ($session->status === 'divergenz') {
            $session->update(['status' => 'konvergenz']);
        }
    }

    /** Team-sichtbare Sessions (neueste zuerst) — für MCP/Listen. */
    public function list(Team $team): \Illuminate\Support\Collection
    {
        return FoodAlchemistPlanningSession::visibleToTeam($team)->orderByDesc('updated_at')->get();
    }

    /** Eine team-sichtbare Session (oder null). */
    public function get(Team $team, int $id): ?FoodAlchemistPlanningSession
    {
        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($id);
    }

    private function ownedSession(Team $team, int $id): FoodAlchemistPlanningSession
    {
        $session = FoodAlchemistPlanningSession::visibleToTeam($team)->findOrFail($id);
        if (! $session->isOwnedBy($team)) {
            throw new RuntimeException('Geerbte Planungs-Session — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $session;
    }

    /** Body ohne YAML-Frontmatter, auf ~$max Zeichen gekürzt (für den Analyse-Prefill). */
    private function bodyAuszug(string $md, int $max): string
    {
        $body = preg_replace('/\A\x{FEFF}?\s*---\R.*?\R---\R?/su', '', $md) ?? $md;

        return mb_substr(trim($body), 0, $max);
    }

    private function clean(mixed $wert): ?string
    {
        $s = trim((string) ($wert ?? ''));

        return $s === '' ? null : $s;
    }
}
