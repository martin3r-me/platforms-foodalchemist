<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * MCP-Einstieg „Format aus Brief" — ein gebrandetes FOODKONZEPT (Chefs Corner / Taste & Fly / Lunchbuffet /
 * Dinner) in der Leitstelle planen. Legt ein Format an (oder lädt via format_id), baut aus dem Brief die
 * Marken-Identität (consumer_name/claim/story) + die eigenständigen Concepte/Veranstaltungen, die die Marke bündelt (Gerüst,
 * owner=format) und startet die Voll-Kaskade (eager, wie Angebot): je Slot ein Concept, das als Aufbau-Slot
 * (type=concept) ins Format referenziert wird ({@see FormatService::slotConceptEinfuegen}). Tenancy: Writes isOwnedBy.
 */
class FormatPlanFromBriefTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format.PLAN_FROM_BRIEF';
    }

    public function getDescription(): string
    {
        return 'Plant ein gebrandetes Foodkonzept (Format: z. B. Streetfood-Konzept, Lunchbuffet, Flying-Dinner) '
            . 'aus einem Brief in der Leitstelle: legt ein Format an (oder lädt via format_id ein bestehendes, '
            . 'team-eigenes), leitet Marken-Identität (consumer_name/claim/story) + aufeinander abgestimmte '
            . 'eigenständige Concepte/Veranstaltungen (ein Tag / Event / eine Menü-Variante) aus dem Brief ab und startet '
            . 'die Voll-Kaskade (owner_type=format, eager): je Slot ein ganzes Concept, das ins Format referenziert wird '
            . '(die Stationen/Gänge baut der Conceptor im Concept). Branding wird nur bei NEU angelegten '
            . 'Formaten geschrieben (bestehende Identität bleibt unangetastet). Bildwelt bleibt manuell '
            . '(format_images.*). Liefert format_id + Session + Lauf-Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Das Briefing des Foodkonzepts (Idee/Marke, Anlass, Service-Format, Niveau, Zielgruppe, Preis …).'],
                'format_id' => ['type' => 'integer', 'description' => 'Optional: ein bestehendes, team-eigenes Format bebriefen (sonst neu anlegen). Bei bestehenden bleibt die Marken-Identität unangetastet.'],
                'label' => ['type' => 'string', 'description' => 'Optionaler interner Name für ein neues Format (Default „Format aus Brief"; wird vom Gerüst-Namen überschrieben, falls die KI einen liefert). Ignoriert bei format_id.'],
                'origin' => ['type' => 'string', 'enum' => ['eigen', 'gruppe', 'kunde'], 'description' => 'Herkunft/IP eines NEUEN Formats (Default eigen). Kunden-IP wird nie fremd wiederverwendet.'],
                'creative_mode' => ['type' => 'string', 'enum' => ['voll_kreativ', 'hybrid', 'datenbank'], 'description' => 'Kreativ-Modus der Kaskade (Default voll_kreativ; datenbank/hybrid = Bestands-Concepts reusen wo sie passen).'],
                'leitplanken' => [
                    'type' => 'object',
                    'description' => 'Optional: Richtungs-Regler/Leitplanken (whitelist-gefiltert), z. B. level, sektor, diaet_hart, '
                        . 'frische_erlaubt[], bio_pref, aroma_kueche, menue_quote_*, ki_bilder, complete_coverage. Erben in die Concept-/Gericht-/Basisrezept-Ebene.',
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
        $formate = app(FormatService::class);

        // 1. Format: bestehendes (team-EIGEN) ODER neue Hülle.
        $neu = false;
        if (! empty($arguments['format_id'])) {
            $format = FoodAlchemistFormat::visibleToTeam($team)->find((int) $arguments['format_id']);
            if ($format === null) {
                return ToolResult::error('Format nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
            if (! $format->isOwnedBy($team)) {
                return ToolResult::error('Geerbtes Format — nur das Besitzer-Team darf planen.', 'INHERITED');
            }
        } else {
            $origin = in_array($arguments['origin'] ?? '', ['eigen', 'gruppe', 'kunde'], true) ? (string) $arguments['origin'] : 'eigen';
            $format = $formate->create($team, [
                'name' => trim((string) ($arguments['label'] ?? '')) ?: 'Format aus Brief',
                'origin' => $origin,
            ]);
            $neu = true;
        }

        $mode = in_array($arguments['creative_mode'] ?? '', ['voll_kreativ', 'hybrid', 'datenbank'], true)
            ? (string) $arguments['creative_mode'] : 'voll_kreativ';

        try {
            // 2. Gerüst aus dem Brief (owner=format): Marken-Identität + eigenständige Concepte (Veranstaltungen).
            $geruest = app(ConceptGeneratorService::class)->geruestAusBriefFuerOwner($team, 'format', (int) $format->id, $brief, [
                'segment' => app(TeamSettingsService::class)->segment($team),
            ]);
            // Nur bei NEU angelegtem Format: Name + Branding aus dem Gerüst schreiben (bestehende Identität bleibt).
            $brandingApplied = [];
            if ($neu) {
                $upd = [];
                if (is_string($geruest['name'] ?? null) && trim((string) $geruest['name']) !== '') {
                    $upd['name'] = trim((string) $geruest['name']);
                }
                foreach (['consumer_name', 'claim', 'story'] as $feld) {
                    $wert = $geruest['branding'][$feld] ?? null;
                    if (is_string($wert) && trim($wert) !== '') {
                        $upd[$feld] = trim($wert);
                    }
                }
                if ($upd !== []) {
                    $formate->update($team, (int) $format->id, $upd);
                    $brandingApplied = array_keys($upd);
                }
            }

            // 3. Review-Session + Leitplanken + Voll-Kaskade (je Slot ein ganzes Concept/Veranstaltung → ins Format referenziert).
            $sessions = app(PlanningSessionService::class);
            $session = $sessions->create($team, [
                'title' => 'Format aus Brief: ' . ($format->name ?? ('#' . $format->id)),
                'brief' => $brief,
                'creative_mode' => $mode,
                'created_via' => 'mcp_format_brief',
            ]);
            if (! empty($arguments['leitplanken']) && is_array($arguments['leitplanken'])) {
                $sessions->setGenerationParams($team, (int) $session->id, $arguments['leitplanken']);
            }
            $run = app(PlanningCascadeService::class)->starteKaskade($team, 'vollkaskade', $session, $mode, [
                'owner_type' => 'format', 'owner_id' => (int) $format->id, 'created_via' => 'mcp_format_brief',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PLAN_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success([
            'format_id' => (int) $format->id,
            'neu_angelegt' => $neu,
            'branding_gesetzt' => $brandingApplied,
            'session_id' => (int) $session->id,
            'run' => $status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'planung', 'leitstelle', 'vollkaskade', 'brief', 'branding'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm',
            'related_tools' => ['foodalchemist.foodbook.PLAN_FROM_BRIEF', 'foodalchemist.formats.GET', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Plane ein Format aus diesem Brief: „Taste & Fly" — ein Flying-Fingerfood-Konzept für Empfänge, verspielt-modern, 3 abgestimmte Stationen.'],
        ];
    }
}
