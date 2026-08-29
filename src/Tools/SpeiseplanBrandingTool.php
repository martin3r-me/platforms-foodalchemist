<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Speiseplan-Branding setzen (Farben + Footer). Logo/Cover-Upload deferred. */
class SpeiseplanBrandingTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.BRANDING';
    }

    public function getDescription(): string
    {
        return 'Setzt das Branding eines team-eigenen Speiseplans: brand_color (#hex), band_color (#hex), footer_text.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'brand_color' => ['type' => 'string', 'description' => 'Primärfarbe (#hex).'],
                'band_color' => ['type' => 'string', 'description' => 'Bandfarbe (#hex).'],
                'footer_text' => ['type' => 'string', 'description' => 'Footer-Text.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $id, 'Speiseplan')) !== null) {
            return $guard;
        }
        $in = array_intersect_key($arguments, array_flip(['brand_color', 'band_color', 'footer_text']));
        if ($in === []) {
            return ToolResult::error('Mindestens ein Branding-Feld angeben.', 'VALIDATION_ERROR');
        }

        try {
            app(SpeiseplanService::class)->setBranding($team, $id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'branding' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'branding', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplaene.PUT'],
            'examples' => ['Setze bei Speiseplan 3 die Markenfarbe #6d28d9.'],
        ];
    }
}
