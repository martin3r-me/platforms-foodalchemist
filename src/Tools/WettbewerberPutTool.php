<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class WettbewerberPutTool extends WettbewerberTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.wettbewerber.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert ein eigenes Wettbewerberprofil über dieselben Felder wie POST. bestand_pruefen=true prüft den eigenen Eingang kostenlos auf Firmenbezug und erstellt nur Vorschläge; Bestätigungen/Ablehnungen bleiben erhalten. rss_aktiv aktiviert den vorhandenen Quellenzeitplan mit bestehendem KI-Budget. Keine Sofortabrufe.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'bestand_pruefen' => ['type' => 'boolean']] + $this->felder(), 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($svc, $team) use ($arguments, $context) {
            $id = (int) ($arguments['id'] ?? 0);
            if ($id <= 0) {
                abort(422);
            }
            $daten = array_diff_key($arguments, ['id' => true, 'bestand_pruefen' => true]);
            $p = $daten !== [] ? $svc->speichern($team, $daten, $id, $context->user?->id) : $svc->detail($team, $id, $context->user?->id);

            return ['profil' => $p->toArray(), 'neue_vorschlaege' => ! empty($arguments['bestand_pruefen']) ? $svc->bestandPruefen($team, $id, $context->user?->id) : 0];
        });
    }
}
