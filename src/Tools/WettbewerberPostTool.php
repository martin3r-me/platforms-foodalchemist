<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class WettbewerberPostTool extends WettbewerberTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.wettbewerber.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Wettbewerberprofil mit Google-News-RSS-Suche an. name Pflicht; aliases=Marken/Namensvarianten, suchbegriffe=zusätzlicher Kontext, ausschliessen, branchen, region=DE|AT|CH, website, presse_feed optional. rss_aktiv standardmäßig false; kein Abruf und keine KI beim Anlegen. Kuratieren erforderlich.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => $this->felder(), 'required' => ['name']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn ($svc, $team) => ['profil' => $svc->speichern($team, $arguments, null, $context->user?->id)->toArray()]);
    }
}
