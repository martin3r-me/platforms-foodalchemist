<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Paket anlegen (Stamm; Gerichte danach via paket_gerichte.SET). */
class PaketePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein team-eigenes Paket an (name; optional role/class/level/price_mode). '
            . 'price_mode=fixed erfordert price_per_person + price_override_reason. Gerichte danach via paket_gerichte.SET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Paket-Name.'],
                'role' => ['type' => 'string', 'description' => 'Rolle/Gang.'],
                'class' => ['type' => 'string', 'description' => 'Klasse.'],
                'level' => ['type' => 'string', 'description' => 'Niveau.'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'fixed', 'manuell'], 'description' => 'Preis-Modus (Default auto).'],
                'price_per_person' => ['type' => 'number', 'description' => 'Preis/Person (bei fixed/manuell).'],
                'price_override_reason' => ['type' => 'string', 'description' => 'Begründung (bei fixed Pflicht).'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }
        $in = ['name' => $name];
        foreach (['role', 'class', 'level', 'price_mode', 'price_per_person', 'price_override_reason'] as $k) {
            if (array_key_exists($k, $arguments)) {
                $in[$k] = $arguments[$k];
            }
        }

        try {
            $paket = app(PaketService::class)->create($team, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $this->paketPayload($paket)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.paket_gerichte.SET', 'foodalchemist.pakete.PUT'],
            'examples' => ['Lege das Paket „Grill-Buffet" (Rolle Buffet) an.'],
        ];
    }
}
