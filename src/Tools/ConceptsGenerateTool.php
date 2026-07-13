<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningFrameService;

/**
 * R6.1: Brief/Gerüst → fertiges Draft-Konzept mit Kohäsions-Beweis. Kern-Invariante:
 * ausschließlich echte VK-Gerichte — Slot ohne Treffer bleibt leer mit Begründung.
 */
class ConceptsGenerateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.GENERATE';
    }

    public function getDescription(): string
    {
        return 'Generiert ein Draft-Konzept AUSSCHLIESSLICH aus echten VK-Gerichten des Teams: entweder aus einem '
            . 'bestehenden Planungs-Gerüst (geruest_owner_type + geruest_owner_id — z. B. das Gerüst eines Foodbooks) '
            . 'oder aus einem Freitext-brief (KI baut erst das Gerüst, die Gericht-Auswahl bleibt deterministisch '
            . 'graph-gerankt). Slots ohne Treffer bleiben LEER mit Begründung (protokoll + slot.note) — nie erfundene '
            . 'Gerichte. Liefert Kohäsions-Score (Pairing-Graph über die Menüfolge) + R4.2-Coverage mit. '
            . 'Ergebnis IMMER status=draft + created_via — Freigabe bleibt menschlich.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string', 'description' => 'Freitext-Kunden-Brief (Anlass, Gäste, Budget p. P., Diät, No-Gos)'],
                'geruest_owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept'], 'description' => 'Owner eines bestehenden Gerüsts (statt brief)'],
                'geruest_owner_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Name des neuen Konzepts (optional)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ConceptGeneratorService::class);
        $name = isset($arguments['name']) && is_string($arguments['name']) ? $arguments['name'] : null;

        try {
            if (isset($arguments['geruest_owner_type'], $arguments['geruest_owner_id'])) {
                $frames = app(PlanningFrameService::class);
                $frames->resolveOwner($team, (string) $arguments['geruest_owner_type'], (int) $arguments['geruest_owner_id']);
                $frame = $frames->find((string) $arguments['geruest_owner_type'], (int) $arguments['geruest_owner_id']);
                if ($frame === null) {
                    return ToolResult::error('Kein Planungs-Gerüst an diesem Owner — erst foodalchemist.planning.PUT.', 'NOT_FOUND');
                }
                $ergebnis = $svc->generiereAusGeruest($team, $frame, $name, 'mcp');
            } elseif (isset($arguments['brief']) && trim((string) $arguments['brief']) !== '') {
                $ergebnis = $svc->generiereAusBrief($team, (string) $arguments['brief'], $name, 'mcp');
            } else {
                return ToolResult::error('Entweder brief ODER geruest_owner_type+geruest_owner_id angeben.', 'VALIDATION_ERROR');
            }
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            return ToolResult::error('KI ist für dieses Team deaktiviert — Brief-Pfad braucht sie. Gerüst-Pfad geht ohne KI.', 'KI_DEAKTIVIERT');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'concept_id' => $ergebnis['concept']->id,
            'name' => $ergebnis['concept']->name,
            'status' => $ergebnis['concept']->status,
            'created_via' => $ergebnis['concept']->created_via,
            'protokoll' => $ergebnis['protokoll'],
            'kohaesion' => [
                'score' => $ergebnis['kohaesion']['score'] ?? null,
                'coverage_pct' => $ergebnis['kohaesion']['coverage_pct'] ?? null,
                'weakest_pair' => $ergebnis['kohaesion']['weakest_pair'] ?? null,
            ],
            'coverage' => [
                'ampel_gesamt' => $ergebnis['coverage']['ampel_gesamt'] ?? null,
                'zusammenfassung' => $ergebnis['coverage']['zusammenfassung'] ?? [],
            ],
            'hinweis' => 'Draft — mit foodalchemist.coverage.GET nachmessen, Freigabe bleibt menschlich (R4.3-Gate).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concepter', 'generator', 'brief', 'kohaesion', 'r6'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'llm_call',
            'related_tools' => ['foodalchemist.planning.GET', 'foodalchemist.planning.PUT', 'foodalchemist.coverage.GET', 'foodalchemist.concepts.GET'],
            'examples' => ['Baue ein Konzept aus diesem Kunden-Brief: …', 'Generiere ein Konzept aus dem Gerüst von Foodbook 12'],
        ];
    }
}
