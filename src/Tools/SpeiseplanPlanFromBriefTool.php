<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * MCP-Einstieg „Speiseplan aus Brief" — spiegelt {@see \Platform\FoodAlchemist\Livewire\Planung\Index::speiseplanAusBrief}.
 * Legt einen GV-Speiseplan an (oder lädt via speiseplan_id) — `create()` legt GV-Standard-Linien + Zyklus +
 * Start-Montag an (kein Gänge-Gerüst) — und startet die Voll-Kaskade (owner_type=speiseplan, eager): je leerer
 * Zyklus-Zelle ein VK-Gericht (gedeckelt). Der Session-Brief fließt als Kontext in jede Zell-Generierung.
 * Tenancy: Writes isOwnedBy.
 */
class SpeiseplanPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant einen GV-Speiseplan aus einem Brief in der Leitstelle: legt einen Speiseplan an (oder '
            . 'lädt via speiseplan_id ein bestehendes, team-eigenes; GV-Standard-Linien + Zyklus werden angelegt, '
            . 'Zyklus-Wochen ggf. aus dem Brief „N Wochen") und startet die Voll-Kaskade (owner_type=speiseplan, '
            . 'eager): je Zyklus-Zelle ein VK-Gericht. Liefert speiseplan_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Saison, Linien, Zyklus z.B. „4 Wochen", Pax/DGE …).'],
                'speiseplan_id' => ['type' => 'integer', 'description' => 'Optional: einen bestehenden, team-eigenen Speiseplan bebriefen (sonst neu anlegen).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für einen neuen Speiseplan (Default „Speiseplan aus Brief"). Ignoriert bei speiseplan_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert), z.B. level, sektor, diaet_hart, allergen_nogo[], '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, saison, ziel_we_pct, menue_quote_vegetarisch_pct, ki_bilder, complete_coverage.',
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
        $speiseplaene = app(SpeiseplanService::class);

        // 1. Speiseplan: bestehender (team-EIGEN) ODER neue Hülle (GV-Standard-Linien + Zyklus via create()).
        if (! empty($arguments['speiseplan_id'])) {
            $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->find((int) $arguments['speiseplan_id']);
            if ($plan === null) {
                return ToolResult::error('Speiseplan nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            if (! $plan->isOwnedBy($team)) {
                return ToolResult::error('Geerbter Speiseplan — nur das Besitzer-Team darf planen.', 'INHERITED');
            }
        } else {
            $in = ['name' => trim((string) ($arguments['label'] ?? '')) ?: 'Speiseplan aus Brief'];
            // Zyklus aus dem Brief ableiten (z.B. „4 Wochen"); sonst GV-Default aus create().
            if (preg_match('/(\d+)\s*[- ]?woche/iu', $brief, $m)) {
                $in['cycle_weeks'] = max(1, (int) $m[1]);
            }
            $plan = $speiseplaene->create($team, $in);
        }

        $mode = in_array($arguments['creative_mode'] ?? '', ['voll_kreativ', 'hybrid', 'datenbank'], true)
            ? (string) $arguments['creative_mode'] : 'voll_kreativ';

        try {
            $sessions = app(PlanningSessionService::class);
            $session = $sessions->create($team, [
                'title' => 'Speiseplan aus Brief: ' . ($plan->name ?? ('#' . $plan->id)),
                'brief' => $brief,
                'creative_mode' => $mode,
                'created_via' => 'mcp_speiseplan_brief',
            ]);
            if (! empty($arguments['leitplanken']) && is_array($arguments['leitplanken'])) {
                $sessions->setGenerationParams($team, (int) $session->id, $arguments['leitplanken']);
            }
            $run = app(PlanningCascadeService::class)->starteKaskade($team, 'vollkaskade', $session, $mode, [
                'owner_type' => 'speiseplan', 'owner_id' => (int) $plan->id, 'created_via' => 'mcp_speiseplan_brief',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success([
            'speiseplan_id' => (int) $plan->id,
            'session_id' => (int) $session->id,
            'run' => $status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'speiseplan', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe', 'gv'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.speisekarte.PLAN_FROM_BRIEF', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane einen 4-Wochen-GV-Speiseplan aus diesem Brief: Betriebsrestaurant, 3 Linien, ausgewogen.'],
        ];
    }
}
