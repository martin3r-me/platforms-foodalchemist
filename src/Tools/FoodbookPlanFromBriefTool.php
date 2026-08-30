<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 42 (F5) — MCP-Einstieg „Foodbook aus Brief". Plant ein ganzes Foodbook aus einem Brief in der
 * Leitstelle: der Rahmen (Gerüst/Struktur) entsteht in der Leitstelle, das Foodbook ist reine Ausgabe.
 * Spiegelt die Livewire-Aktion {@see \Platform\FoodAlchemist\Livewire\Planung\Index::foodbookAusBrief}:
 * Foodbook anlegen (oder via foodbook_id laden) → Gerüst aus Brief → Struktur anwenden → Voll-Kaskade
 * (owner_type=foodbook, gestufte Freigabe). Das Ergebnis dockt automatisch als Kapitel/Blöcke ins
 * Foodbook (attachToOutput). Dies ist der legitime vollkaskade-MCP-Weg — er liefert den Ausgabe-Owner
 * (foodbook), den {@see PlanungKaskadeStartPostTool} bewusst nicht kennt. Tenancy: Writes isOwnedBy.
 */
class FoodbookPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein ganzes Foodbook aus einem Brief in der Leitstelle: legt ein Foodbook an (oder '
            . 'lädt via foodbook_id ein bestehendes, team-eigenes), baut das Gerüst aus dem Brief, wendet '
            . 'die Struktur an und startet die Voll-Kaskade (owner_type=foodbook, gestufte Freigabe je '
            . 'Ebene). Das Ergebnis dockt automatisch als Kapitel/Blöcke ins Foodbook. Liefert '
            . 'foodbook_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Gäste, Saison, Niveau, Budget …).'],
                'foodbook_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Foodbook bebriefen (sonst wird ein neues angelegt).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für ein neues Foodbook (Default „Foodbook aus Brief"). Ignoriert bei foodbook_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken für die ganze Kaskade (whitelist-gefiltert). Die menue_*-'
                        . 'Achsen (menue_gaenge, menue_preis_min_pp/ziel_pp/max_pp, menue_quote_vegan_pct, menue_quote_vegetarisch_pct, '
                        . 'menue_balance) steuern die Menü-/Kapitel-Komposition; die übrigen (level, sektor, diaet_hart, allergen_nogo[], '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, saison, ki_bilder, complete_coverage, …) erben in die Gerichte/'
                        . 'Basisrezepte. Per planung_kaskade.GET als „leitplanken" prüfbar.',
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

        $foodbooks = app(FoodbookService::class);

        // 1. Foodbook: bestehendes (team-EIGEN) ODER neue Hülle.
        if (! empty($arguments['foodbook_id'])) {
            $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find((int) $arguments['foodbook_id']);
            if ($fb === null) {
                return ToolResult::error('Foodbook nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            if (! $fb->isOwnedBy($team)) {
                return ToolResult::error('Geerbtes Foodbook — nur das Besitzer-Team darf planen.', 'INHERITED');
            }
        } else {
            $label = trim((string) ($arguments['label'] ?? '')) ?: 'Foodbook aus Brief';
            $fb = $foodbooks->create($team, ['label' => $label]);
        }

        try {
            // 2. Gerüst aus dem Brief (owner-neutral, wie der Foodbook-Kickoff).
            app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($team, 'foodbook', (int) $fb->id, $brief, [
                'segment' => app(TeamSettingsService::class)->segment($team),
                'leitplanken' => $foodbooks->leitplanken($team, $fb),
                'marken_kontext' => app(CanvasService::class)->cascadeKontext($team, null, (int) $fb->id, null, $fb->crm_company_id)['marken_kontext'] ?? null,
            ]);
            // 3. Struktur anwenden (Slots → Kapitel).
            $foodbooks->strukturAusGeruest($team, (int) $fb->id);
            // 4. Review-Session + Voll-Kaskade.
            $mode = in_array($arguments['creative_mode'] ?? '', ['voll_kreativ', 'hybrid', 'datenbank'], true)
                ? (string) $arguments['creative_mode'] : 'voll_kreativ';
            $sessions = app(PlanningSessionService::class);
            $session = $sessions->create($team, [
                'title' => 'Foodbook aus Brief: ' . $fb->label,
                'brief' => $brief,
                'creative_mode' => $mode,
                'created_via' => 'mcp_foodbook_brief',
            ]);
            // Leitplanken der Kaskade setzen (whitelist-gefiltert) — sie steuern Komposition (menue_*) +
            // erben in Gerichte/Basisrezepte; ohne dies liefe die Kaskade mit leeren Reglern.
            if (! empty($arguments['leitplanken']) && is_array($arguments['leitplanken'])) {
                $sessions->setGenerationParams($team, (int) $session->id, $arguments['leitplanken']);
            }
            $run = app(PlanningCascadeService::class)->starteKaskade($team, 'vollkaskade', $session, $mode, [
                'owner_type' => 'foodbook', 'owner_id' => (int) $fb->id, 'created_via' => 'mcp_foodbook_brief',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success([
            'foodbook_id' => (int) $fb->id,
            'foodbook_label' => (string) $fb->label,
            'session_id' => (int) $session->id,
            'run' => $status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'foodbook', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.planung_kaskade.START', 'foodalchemist.planung_kaskade.GET', 'foodalchemist.foodbooks.POST'],
            'examples' => ['Plane ein Foodbook aus diesem Brief: Sommer-Galadinner für 80 Gäste, gehoben.', 'Foodbook 42 aus Brief bebriefen'],
        ];
    }
}
