<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;

/** Spec 82 §12 B3 · Vorschau „neuere Fassung übernehmen“: Allergen-Diff, Zwischenfassungen, betroffene Angebote, ob eine Bestätigung nötig ist. Schreibt nichts. */
class AngeboteUebernehmenGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.UEBERNEHMEN_GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt, was das Übernehmen einer neueren Fassung in ein eingefrorenes Angebot täte: neue Fassung mit Änderungsnotiz, Allergen-/Zusatzstoff-Diff (Endstand), Zwischenfassungen, '
            .'betroffene andere Angebote (eingefroren/live) und ob eine Bestätigung nötig ist (bei geändertem Allergenbild, nur Rolle Freigeben). zeile_id und familie_id stammen aus angebote.EINFRIEREN_GET (veraltet).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'zeile_id' => ['type' => 'integer'], 'familie_id' => ['type' => 'integer']], 'required' => ['id', 'zeile_id', 'familie_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find((int) ($arguments['id'] ?? 0));
        if ($angebot === null) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            return ToolResult::success(['vorschau' => app(AngebotEinfrierService::class)->uebernahmeVorschau($angebot, (int) $arguments['zeile_id'], (int) $arguments['familie_id'])]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'angebot', 'einfrieren', 'fassung', 'allergen', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.angebote.EINFRIEREN_GET', 'foodalchemist.angebote.UEBERNEHMEN_POST'],
            'examples' => ['Was passiert, wenn Angebot 5 die neue Jus-Fassung übernimmt?'],
        ];
    }
}
