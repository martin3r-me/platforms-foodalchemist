<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * MCP-Einstieg „Speisekarte aus Brief" — spiegelt {@see \Platform\FoodAlchemist\Livewire\Planung\Index::speisekarteAusBrief}.
 * Legt eine Speisekarte an (oder lädt via speisekarte_id ein team-eigenes), baut das Gerüst aus dem Brief
 * (Rubriken entstehen pro Slot im Fan-out, KEIN strukturAusGeruest) und startet die Voll-Kaskade
 * (owner_type=speisekarte, eager). STANDARD füllt jede Rubrik mit einzelnen VK-Gerichten (gericht_ref);
 * fuellung='concepte' → je Rubrik ein Concept/Fix-Menü (menue_ref). Tenancy: Writes isOwnedBy.
 */
class SpeisekartePlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant eine ganze Speisekarte aus einem Brief in der Leitstelle: legt eine Speisekarte an '
            . '(oder lädt via speisekarte_id ein bestehendes, team-eigenes), baut das Gerüst aus dem Brief und '
            . 'startet die Voll-Kaskade (owner_type=speisekarte, eager, Sammel-Review). STANDARD füllt jede '
            . 'Rubrik mit einzelnen VK-Gerichten (fuellung=gerichte); fuellung=concepte füllt je Rubrik ein '
            . 'Concept/Fix-Menü. Anzahl Gerichte je Rubrik = KI-Vorgabe aus dem Brief. Liefert speisekarte_id '
            . '+ Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing (Anlass, Küche, Preisniveau, wie viele Gerichte je Rubrik …).'],
                'speisekarte_id' => ['type' => 'integer', 'description' => 'Optional: eine bestehende, team-eigene Speisekarte bebriefen (sonst neu anlegen).'],
                'label' => ['type' => 'string', 'description' => 'Optionaler Name für eine neue Speisekarte (Default „Speisekarte aus Brief"). Ignoriert bei speisekarte_id.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank = Bestand reusen).'],
                'fuellung' => ['type' => 'string', 'enum' => ['gerichte', 'concepte'], 'description' => 'gerichte (Default — je Rubrik einzelne VK-Gerichte) oder concepte (je Rubrik ein Concept/Fix-Menü).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken für die Kaskade (whitelist-gefiltert), z.B. level, sektor, '
                        . 'diaet_hart, allergen_nogo[], frische_erlaubt[], bio_pref, aroma_kueche, saison, ki_bilder, complete_coverage.',
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
        $speisekarten = app(SpeisekarteService::class);

        // 1. Speisekarte: bestehende (team-EIGEN) ODER neue Hülle.
        if (! empty($arguments['speisekarte_id'])) {
            $sk = FoodAlchemistSpeisekarte::visibleToTeam($team)->find((int) $arguments['speisekarte_id']);
            if ($sk === null) {
                return ToolResult::error('Speisekarte nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            if (! $sk->isOwnedBy($team)) {
                return ToolResult::error('Geerbte Speisekarte — nur das Besitzer-Team darf planen.', 'INHERITED');
            }
        } else {
            $label = trim((string) ($arguments['label'] ?? '')) ?: 'Speisekarte aus Brief';
            $sk = $speisekarten->create($team, ['name' => $label]);
        }

        $mode = in_array($arguments['creative_mode'] ?? '', ['voll_kreativ', 'hybrid', 'datenbank'], true)
            ? (string) $arguments['creative_mode'] : 'voll_kreativ';
        $fuellung = ($arguments['fuellung'] ?? 'gerichte') === 'concepte' ? 'concepte' : 'gerichte';

        try {
            // 2. Gerüst aus dem Brief (owner=speisekarte → gang/station-Slots mit target_count). KEIN
            //    strukturAusGeruest — die Rubriken entstehen pro Slot im Fan-out (rubrikFuerSlot).
            app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($team, 'speisekarte', (int) $sk->id, $brief, [
                'segment' => app(TeamSettingsService::class)->segment($team),
            ]);
            // 3. Review-Session + Leitplanken (inkl. Füllungs-Flag) + Voll-Kaskade.
            $sessions = app(PlanningSessionService::class);
            $session = $sessions->create($team, [
                'title' => 'Speisekarte aus Brief: ' . ($sk->name ?? ('#' . $sk->id)),
                'brief' => $brief,
                'creative_mode' => $mode,
                'created_via' => 'mcp_speisekarte_brief',
            ]);
            $leitplanken = is_array($arguments['leitplanken'] ?? null) ? $arguments['leitplanken'] : [];
            $leitplanken['speisekarte_fuellung'] = $fuellung;
            $sessions->setGenerationParams($team, (int) $session->id, $leitplanken);

            $run = app(PlanningCascadeService::class)->starteKaskade($team, 'vollkaskade', $session, $mode, [
                'owner_type' => 'speisekarte', 'owner_id' => (int) $sk->id, 'created_via' => 'mcp_speisekarte_brief',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success([
            'speisekarte_id' => (int) $sk->id,
            'fuellung' => $fuellung,
            'session_id' => (int) $session->id,
            'run' => $status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'speisekarte', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'ausgabe'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.planung_kaskade.GET', 'foodalchemist.speisekarten.POST'],
            'examples' => ['Plane eine Speisekarte aus diesem Brief: mediterrane à-la-carte-Karte, 4 Rubriken, je 4 Gerichte.'],
        ];
    }
}
