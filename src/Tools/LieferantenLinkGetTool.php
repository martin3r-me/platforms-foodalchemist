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

/** Lieferanten-Link: Links eines Lieferanten (ohne Token) und ihr Protokoll. */
class LieferantenLinkGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_link.GET';
    }

    public function getDescription(): string
    {
        return "Listet die Lieferanten-Links des eigenen Teams (optional nur eines Lieferanten: supplier_id) mit Zustand (aktiv | abgelaufen | widerrufen), Gültigkeit, letzter Nutzung und Anzahl Uploads. Der Token wird NIE zurückgegeben (nur ein Hinweis zur Wiedererkennung). Mit link_id zusätzlich das Protokoll (erzeugt, geöffnet, Upload, abgelehnt, widerrufen). Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.";
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer'], 'link_id' => ['type' => 'integer', 'description' => 'Mit Protokoll'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(LieferantenLinkService::class);
            $links = $svc->liste($team, $context->user, isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null);
            $out = ['links' => $links->map(fn ($l) => ['id' => $l->id, 'lieferant_id' => $l->supplier_id, 'zustand' => $l->zustand(), 'token_hinweis' => $l->token_hinweis, 'kontakt_name' => $l->kontakt_name, 'kontakt_email' => $l->kontakt_email, 'bereiche' => $l->bereiche, 'gueltig_bis' => $l->expires_at->toIso8601String(), 'zuletzt_benutzt' => $l->last_used_at?->toIso8601String(), 'uploads' => $l->upload_count])->values()->all()];
            if (! empty($arguments['link_id'])) {
                $l = $svc->link($team, (int) $arguments['link_id']) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
                $out['protokoll'] = $svc->protokoll($team, $l)->map(fn ($e) => ['am' => $e->created_at->toIso8601String(), 'art' => $e->art, 'detail' => $e->detail])->values()->all();
            }

            return $out;
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['lieferanten_link', 'lieferant', 'portal'], ['Welche Lieferanten-Links sind noch aktiv?'], ['foodalchemist.lieferanten_link.POST']), [
            'tags' => ['foodalchemist', 'lieferanten_link', 'lieferant', 'einkauf'],
        ]);
    }
}
