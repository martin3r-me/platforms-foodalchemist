<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class WettbewerberGetTool extends WettbewerberTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.wettbewerber.GET';
    }

    public function getDescription(): string
    {
        return 'Liest Wettbewerberprofile und mit id deren Artikelzuordnungen. Nur bestätigte Zuordnungen erscheinen im Observatorium. status=vorgeschlagen|bestaetigt|abgelehnt, seite für 20 Fundstellen je Seite. Keine KI und kein Abruf.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['', 'vorgeschlagen', 'bestaetigt', 'abgelehnt']], 'seite' => ['type' => 'integer', 'minimum' => 1]]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($e = $this->enumFehler($arguments)) {
            return $e;
        }

        return $this->ausfuehren($context, function ($svc, $team) use ($arguments, $context) {
            if (empty($arguments['id'])) {
                return ['profile' => $svc->profile($team, $context->user?->id)->toArray()];
            }
            $p = $svc->detail($team, (int) $arguments['id'], $context->user?->id);
            $f = $svc->funde($team, $p->id, $arguments['status'] ?? '', $context->user?->id, max(1, (int) ($arguments['seite'] ?? 1)));

            return ['profil' => $p->toArray(), 'funde' => $f->items(), 'seite' => $f->currentPage(), 'letzte_seite' => $f->lastPage(), 'gesamt' => $f->total()];
        });
    }
}
