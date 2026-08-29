<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Foodbook-Branding setzen (Farben + Footer). Logo/Cover-Upload deferred (binär). */
class FoodbooksBrandingTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.BRANDING';
    }

    public function getDescription(): string
    {
        return 'Setzt das Branding eines team-eigenen Foodbooks: brand_color (#hex), band_color (#hex, leer=abgeleitet), footer_text. '
            . 'Logo/Cover-Upload ist ein separater (binärer) Kanal.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'brand_color' => ['type' => 'string', 'description' => 'Primärfarbe (#hex).'],
                'band_color' => ['type' => 'string', 'description' => 'Bandfarbe (#hex; leer = aus brand_color abgeleitet).'],
                'footer_text' => ['type' => 'string', 'description' => 'Footer-Text (leer = keiner).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFoodbook::class, $id, 'Foodbook')) !== null) {
            return $guard;
        }
        $in = array_intersect_key($arguments, array_flip(['brand_color', 'band_color', 'footer_text']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Branding-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(FoodbookService::class)->setBranding($team, $id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'branding' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'branding', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbooks.PUT'],
            'examples' => ['Setze bei Foodbook 5 die Markenfarbe #6d28d9.'],
        ];
    }
}
