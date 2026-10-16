<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupportFreigabe;
use Platform\FoodAlchemist\Services\SupportFreigabeService;

/** Spec 84 §4.4 (E3) · Support-Zugang sofort widerrufen (nur Owner/Admin des Hauptteams). */
class SupportZugangDeleteTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.support_zugang.DELETE';
    }

    public function getDescription(): string
    {
        return 'Widerruft eine Support-Freigabe des eigenen Kunden-Hauptteams sofort (id aus foodalchemist.support_zugang.GET). Nur Owner/Admin des Hauptteams.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => ['freigabe' => SupportZugangGetTool::zeile(
            app(SupportFreigabeService::class)->widerrufen($team, $context->user, (int) ($arguments['id'] ?? 0)),
        )]);
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['support', 'zugang'], ['Nimm dem Support den Zugang wieder weg.'],
            ['foodalchemist.support_zugang.GET']), ['tags' => ['foodalchemist', 'support', 'zugang', 'datenschutz']]);
    }
}
