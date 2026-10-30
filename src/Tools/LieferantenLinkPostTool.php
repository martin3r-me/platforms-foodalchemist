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

/** Lieferanten-Link erzeugen (Token nur in dieser Antwort). */
class LieferantenLinkPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_link.POST';
    }

    public function getDescription(): string
    {
        return "Erzeugt einen persönlichen Link für einen Lieferanten (supplier_id), ohne Login: Preisliste, Stammdaten, Datenblätter und Artikelformular einreichen — alles landet in der Prüfliste, nie direkt im Bestand. tage (Standard 30, höchstens 90), bereiche (preisliste | stammdaten | dokumente | formular, Standard alle), optional kontakt_name/kontakt_email. Die url steht NUR in dieser Antwort (gespeichert wird nur ein Hash). Eigene Lieferanten des Teams; globale Stamm-Lieferanten nur das Master-Team. Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.";
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer'], 'tage' => ['type' => 'integer'],
            'bereiche' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Link::BEREICHE)]],
            'kontakt_name' => ['type' => 'string'], 'kontakt_email' => ['type' => 'string'],
        ], 'required' => ['supplier_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(LieferantenLinkService::class);
            $lieferant = FoodAlchemistSupplier::visibleToTeam($team)->find((int) ($arguments['supplier_id'] ?? 0)) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $r = $svc->erzeugen($team, $context->user, $lieferant, $arguments);
            $l = $r['link'];

            return ['link' => ['id' => $l->id, 'lieferant_id' => $l->supplier_id, 'zustand' => $l->zustand(), 'token_hinweis' => $l->token_hinweis, 'kontakt_name' => $l->kontakt_name, 'kontakt_email' => $l->kontakt_email, 'bereiche' => $l->bereiche, 'gueltig_bis' => $l->expires_at->toIso8601String(), 'zuletzt_benutzt' => $l->last_used_at?->toIso8601String(), 'uploads' => $l->upload_count], 'url' => $r['url'], 'hinweis' => 'Die url wird nur jetzt angezeigt — bitte direkt an den Lieferanten geben. Widerruf: lieferanten_link.DELETE.'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['lieferanten_link', 'lieferant', 'portal'], ['Erstelle einen Link für Hanos, 14 Tage gültig.'], ['foodalchemist.lieferanten_link.GET', 'foodalchemist.lieferanten_link.DELETE']), [
            'tags' => ['foodalchemist', 'lieferanten_link', 'lieferant', 'einkauf', 'write'],
        ]);
    }
}
