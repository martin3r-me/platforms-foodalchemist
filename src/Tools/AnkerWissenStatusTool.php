<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenFreigabe;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 60 · P8: Anker-Wissen freigeben oder verwerfen — anker-weise oder je Eintrag.
 * Anker-Wissen ist global; schreiben darf nur der Kurator ({@see TeamScope::isMaster}).
 */
class AnkerWissenStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.anker_wissen.STATUS';
    }

    public function getDescription(): string
    {
        return 'Status von Anker-Wissen setzen (Spec 60): geprueft = freigegeben (Aussagen tragen dann „aus Dossier, geprüft"), '
            . 'verworfen = zählt nicht mehr (kein Bedarf, kein Kontrast, kein Konflikt), entwurf = zurück zur Prüfung. '
            . 'Ohne bereich/eintrag_id gilt der Status für das ganze Dossier-Wissen des Ankers; mit beiden nur für einen '
            . 'Eintrag (IDs aus foodalchemist.anker_wissen.GET). Danach werden Kontrast-Kanten und die Aromenprofile der '
            . 'betroffenen Rezepte neu gerechnet. Nur der Kurator des globalen Wissens darf schreiben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'anker_id' => ['type' => 'integer', 'description' => 'ID aus dem Aroma-Anker-Vokabular'],
                'status' => ['type' => 'string', 'enum' => ['geprueft', 'verworfen', 'entwurf']],
                'bereich' => ['type' => 'string', 'enum' => array_keys(AnkerWissenFreigabe::BEREICHE), 'description' => 'nur mit eintrag_id'],
                'eintrag_id' => ['type' => 'integer', 'description' => 'nur mit bereich'],
            ],
            'required' => ['anker_id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! TeamScope::isMaster($team)) {
            return ToolResult::error('Anker-Wissen ist global — nur der Kurator (Master-Team) gibt es frei.', 'ACCESS_DENIED');
        }
        $status = WissensStatus::tryFrom((string) ($arguments['status'] ?? ''));
        if ($status === null) {
            return ToolResult::error('status muss geprueft|verworfen|entwurf sein.', 'VALIDATION_ERROR');
        }
        $freigabe = app(AnkerWissenFreigabe::class);
        $ankerId = (int) ($arguments['anker_id'] ?? 0);
        if ($freigabe->ansicht($ankerId) === null) {
            return ToolResult::error('Anker nicht gefunden.', 'NOT_FOUND');
        }
        try {
            $erg = $freigabe->setze($ankerId, $status, $arguments['bereich'] ?? null,
                isset($arguments['eintrag_id']) ? (int) $arguments['eintrag_id'] : null);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($erg);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'pairing', 'anker', 'wissen', 'freigabe', 'kuration', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.anker_wissen.GET', 'foodalchemist.kombination.GET'],
            'examples' => ['Gib das Anker-Wissen von Kürbis frei.', 'Verwirf den Konflikt-Eintrag beziehungen#12 von Anker 88.'],
        ];
    }
}
