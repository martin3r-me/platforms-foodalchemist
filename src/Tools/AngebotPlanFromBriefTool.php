<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * MCP-Einstieg „Angebot aus Brief" — spiegelt {@see \Platform\FoodAlchemist\Livewire\Planung\Index::angebotAusBrief}.
 * Legt ein Angebot an (oder lädt via angebot_id), baut das Gerüst aus dem Brief (owner-neutral) und startet die
 * Voll-Kaskade (owner_type=offer, eager): je Slot ein Concept, das via GenerateConceptJob direkt ans Angebot
 * referenziert wird (Pivot foodalchemist_offer_concept, KEIN Zwischen-Container). Tenancy: Writes isOwnedBy.
 */
class AngebotPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein Angebot aus einem Brief in der Leitstelle: legt ein Angebot an (oder lädt via '
            . 'angebot_id ein bestehendes, team-eigenes), baut das Gerüst aus dem Brief und startet die '
            . 'Voll-Kaskade (owner_type=offer, eager): je Slot ein Concept, das ans Angebot referenziert wird. '
            . 'Liefert angebot_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Gäste, Saison, Niveau, Budget …).'],
                'angebot_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Angebot bebriefen (sonst neu anlegen).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für ein neues Angebot (Default „Angebot aus Brief"). Ignoriert bei angebot_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert). menue_*-Achsen (menue_gaenge, '
                        . 'menue_preis_*_pp, menue_quote_*, menue_balance) steuern die Menü-Zusammenstellung; die übrigen (level, '
                        . 'sektor, diaet_hart, frische_erlaubt[], bio_pref, aroma_kueche, ki_bilder, complete_coverage) erben in Gerichte/Basisrezepte.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['brief'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $brief = trim((string) ($arguments['brief'] ?? ''));
        if ($brief === '') {
            return ToolResult::error('brief ist Pflicht.', 'VALIDATION_ERROR');
        }
        $angebote = app(AngebotService::class);

        // 1. Angebot: bestehendes (team-EIGEN) ODER neue Hülle.
        if (! empty($arguments['angebot_id'])) {
            $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find((int) $arguments['angebot_id']);
            if ($angebot === null) {
                return ToolResult::error('Angebot nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            if (! $angebot->isOwnedBy($team)) {
                return ToolResult::error('Geerbtes Angebot — nur das Besitzer-Team darf planen.', 'INHERITED');
            }
        } else {
            $name = trim((string) ($arguments['label'] ?? '')) ?: 'Angebot aus Brief';
            $angebot = $angebote->create($team, ['name' => $name]);
        }

        $mode = in_array($arguments['creative_mode'] ?? '', ['voll_kreativ', 'hybrid', 'datenbank'], true)
            ? (string) $arguments['creative_mode'] : 'voll_kreativ';

        try {
            // 2. Gerüst aus dem Brief (owner-neutral). marken_kontext aus dem CRM-Kunden, falls verknüpft.
            app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($team, 'offer', (int) $angebot->id, $brief, [
                'segment' => app(TeamSettingsService::class)->segment($team),
                'marken_kontext' => app(CanvasService::class)->cascadeKontext($team, null, null, null, $angebot->crm_company_id ?? null)['marken_kontext'] ?? null,
            ]);
            // 3. Review-Session + Leitplanken + Voll-Kaskade (1 Concept je Slot → ans Angebot referenziert).
            $sessions = app(PlanningSessionService::class);
            $session = $sessions->create($team, [
                'title' => 'Angebot aus Brief: ' . ($angebot->name ?? ('#' . $angebot->id)),
                'brief' => $brief,
                'creative_mode' => $mode,
                'created_via' => 'mcp_offer_brief',
            ]);
            if (! empty($arguments['leitplanken']) && is_array($arguments['leitplanken'])) {
                $sessions->setGenerationParams($team, (int) $session->id, $arguments['leitplanken']);
            }
            $run = app(PlanningCascadeService::class)->starteKaskade($team, 'vollkaskade', $session, $mode, [
                'owner_type' => 'offer', 'owner_id' => (int) $angebot->id, 'created_via' => 'mcp_offer_brief',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success([
            'angebot_id' => (int) $angebot->id,
            'session_id' => (int) $session->id,
            'run' => $status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'angebot', 'offer', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.speisekarte.PLAN_FROM_BRIEF', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane ein Angebot aus diesem Brief: Sommer-Gartenfest für 80 Gäste, 3-Gänge-Menü, gehoben.'],
        ];
    }
}
