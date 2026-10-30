<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\LieferantenLinkService;

/** Lieferanten-Link widerrufen. */
class LieferantenLinkDeleteTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_link.DELETE';
    }

    public function getDescription(): string
    {
        return "Widerruft einen Lieferanten-Link (link_id) sofort; der Lieferant kommt damit nicht mehr hinein. Idempotent, wird protokolliert. Bereits hochgeladene Dateien bleiben in der Prüfliste. Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.";
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['link_id' => ['type' => 'integer']], 'required' => ['link_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(LieferantenLinkService::class);
            $l = $svc->link($team, (int) ($arguments['link_id'] ?? 0)) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $l = $svc->widerrufen($team, $context->user, $l);

            return ['link' => ['id' => $l->id, 'lieferant_id' => $l->supplier_id, 'zustand' => $l->zustand(), 'token_hinweis' => $l->token_hinweis, 'kontakt_name' => $l->kontakt_name, 'kontakt_email' => $l->kontakt_email, 'bereiche' => $l->bereiche, 'gueltig_bis' => $l->expires_at->toIso8601String(), 'zuletzt_benutzt' => $l->last_used_at?->toIso8601String(), 'uploads' => $l->upload_count]];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['lieferanten_link', 'lieferant', 'portal'], ['Widerruf den Link von Hanos.'], ['foodalchemist.lieferanten_link.GET']), [
            'tags' => ['foodalchemist', 'lieferanten_link', 'lieferant', 'einkauf', 'write'],
        ]);
    }
}
