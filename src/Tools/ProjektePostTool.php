<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ProjektService;

/** Spec 82 §9 · Projekt anlegen (Agentur-Akte zu einer CRM-Firma des Master-Teams). Schreibt nichts ins CRM. */
class ProjektePostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.projekte.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Projekt (Agentur-Akte) im Master-Team an: crm_company_id (Firma aus dem CRM des Master-Teams, wird nur verknüpft), name, optional ziel. '
            .'Erzeugt KEINEN Mandanten und schreibt nichts ins CRM. Ab Rolle Kuratieren, nur im Master-Team.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'crm_company_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'ziel' => ['type' => 'string'],
        ], 'required' => ['crm_company_id', 'name']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $p = app(ProjektService::class)->anlegen($team, $context->user, (int) $arguments['crm_company_id'], (string) $arguments['name'], $arguments['ziel'] ?? null);

            return ['projekt' => ['id' => $p->id, 'name' => $p->name, 'phase' => $p->phase, 'status' => $p->status]];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['projekte'], ['Lege ein Projekt für Soulkitchen an.'], ['foodalchemist.projekte.GET', 'foodalchemist.projekte.PUT']), [
            'tags' => ['foodalchemist', 'projekte', 'agentur', 'write'],
        ]);
    }
}
