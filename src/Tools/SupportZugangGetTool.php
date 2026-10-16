<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupportFreigabe;
use Platform\FoodAlchemist\Services\SupportFreigabeService;

/** Spec 84 §4.4 (E3) · Support-Freigaben des eigenen Hauptteams ansehen (nur Owner/Admin). */
class SupportZugangGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.support_zugang.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Support-Freigaben des eigenen Kunden-Hauptteams (neueste zuerst): für wen (fuer_user_id, null = alle Plattform-Admins), '
            .'gültig bis, widerrufen, Grund, aktiv. Ohne Freigabe sieht kein Plattform-Admin Inhalte des Teams (Spec 82 §13 G). Nur Owner/Admin des Hauptteams.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => [
            'freigaben' => app(SupportFreigabeService::class)->liste($team, $context->user)->map(fn ($f) => self::zeile($f))->values()->all(),
        ]);
    }

    public static function zeile(FoodAlchemistSupportFreigabe $f): array
    {
        return [
            'id' => (int) $f->id, 'fuer_user_id' => $f->fuer_user_id !== null ? (int) $f->fuer_user_id : null,
            'erteilt_von' => (int) $f->erteilt_von, 'gueltig_bis' => $f->gueltig_bis?->toIso8601String(),
            'widerrufen_am' => $f->widerrufen_am?->toIso8601String(), 'grund' => $f->grund, 'aktiv' => $f->istAktiv(),
        ];
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['support', 'zugang'], ['Hat gerade jemand vom Support Zugang zu unseren Inhalten?'],
            ['foodalchemist.support_zugang.POST', 'foodalchemist.support_zugang.DELETE']), ['tags' => ['foodalchemist', 'support', 'zugang', 'datenschutz']]);
    }
}
