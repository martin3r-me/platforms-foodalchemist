<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ObservatoriumPinnwandService;

/** An die Team-Pinnwand heften: Meldung (eingang_id) oder eigene Idee (titel, notiz, link); mit pin_id nur die Notiz ändern. */
class ObservatoriumPinnwandPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.observatorium_pinnwand.POST';
    }

    public function getDescription(): string
    {
        return 'Heftet etwas an die Team-Pinnwand im Observatorium (ab Rolle Kuratieren, eigenes Team): eine Meldung per eingang_id '
            .'(aus dem Observatorium, ein zweites Anpinnen ergänzt nur die Notiz) oder eine eigene Idee per titel (+ notiz, link http/https). '
            .'Mit pin_id wird nur die Notiz eines vorhandenen Eintrags gesetzt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'eingang_id' => ['type' => 'integer', 'description' => 'Meldung aus dem Observatorium anpinnen'],
            'titel' => ['type' => 'string', 'description' => 'Eigene Idee: Titel (höchstens 200 Zeichen)'],
            'notiz' => ['type' => 'string', 'description' => 'Notiz (höchstens 1000 Zeichen)'],
            'link' => ['type' => 'string', 'description' => 'Eigene Idee: Link (http/https)'],
            'pin_id' => ['type' => 'integer', 'description' => 'Nur Notiz eines vorhandenen Eintrags ändern'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ObservatoriumPinnwandService::class);
        $uid = $context->user?->id;
        try {
            $pin = match (true) {
                isset($arguments['pin_id']) => $svc->notizSetzen($team, (int) $arguments['pin_id'], $arguments['notiz'] ?? null),
                isset($arguments['eingang_id']) => $svc->meldungAnpinnen($team, (int) $arguments['eingang_id'], $arguments['notiz'] ?? null, $uid),
                isset($arguments['titel']) => $svc->ideeAnpinnen($team, (string) $arguments['titel'], $arguments['notiz'] ?? null, $arguments['link'] ?? null, $uid),
                default => throw new \RuntimeException('eingang_id, titel oder pin_id angeben.'),
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return ToolResult::error('Eintrag oder Meldung nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['pin_id' => (int) $pin->id]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'observatorium', 'pinnwand', 'write'], 'read_only' => false,
            'idempotent' => false, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['Eintrag an der Team-Pinnwand anlegen oder Notiz ändern'],
            'related_tools' => ['foodalchemist.observatorium_pinnwand.GET', 'foodalchemist.observatorium_pinnwand.DELETE']];
    }
}
