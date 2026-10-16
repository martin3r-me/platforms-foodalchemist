<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;

/** Allergen-Vorschlag eines Produktpasses bestätigen (übernehmen) oder verwerfen — per MCP nur für FA-Admins. */
class ProduktpassAllergeneBestaetigenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.ALLERGENE_BESTAETIGEN';
    }

    public function getDescription(): string
    {
        return 'Bestätigt den Allergen-Vorschlag einer Produktpass-Version (aktion=bestaetigen, Standard): die gelesenen Allergene und Unterarten werden am '
            .'Artikel eingetragen (Quelle produktpass_ki), bis dahin gilt der alte Allergenstand. aktion=verwerfen lässt den Vorschlag fallen. '
            .'Nur FA-Admins; Kennzeichnung ist sicherheitsrelevant — vorher mit foodalchemist.produktpass.GET prüfen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'produktpass_id' => ['type' => 'integer'],
                'aktion' => ['type' => 'string', 'enum' => ['bestaetigen', 'verwerfen']],
            ],
            'required' => ['produktpass_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $pass = FoodAlchemistProduktpass::visibleToTeam($team)->find((int) ($arguments['produktpass_id'] ?? 0));
        if ($pass === null) {
            return ToolResult::error('Produktpass nicht gefunden.', 'NOT_FOUND');
        }
        $svc = app(ProduktpassService::class);
        try {
            $pass = ($arguments['aktion'] ?? 'bestaetigen') === 'verwerfen'
                ? $svc->allergeneVerwerfen($team, $pass, $context->user?->id)
                : $svc->allergeneBestaetigen($team, $pass, $context->user?->id, FaRolle::Admin);
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['produktpass_id' => $pass->id, 'allergene_status' => $pass->allergene_status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'produktpass', 'allergene', 'kennzeichnung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.produktpass.GET'],
            'examples' => ['Bestätige die Allergene aus Produktpass 12.'],
        ];
    }
}
