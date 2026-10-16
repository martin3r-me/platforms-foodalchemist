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

/** Kennzeichnungs-Vorschlag (Allergene UND Zusatzstoffe) eines Produktpasses bestätigen oder verwerfen — per MCP nur für FA-Admins. */
class ProduktpassKennzeichnungBestaetigenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.KENNZEICHNUNG_BESTAETIGEN';
    }

    public function getDescription(): string
    {
        return 'Bestätigt den Kennzeichnungs-Vorschlag einer Produktpass-Version (aktion=bestaetigen, Standard): die gelesenen Allergene und Unterarten UND Zusatzstoffe '
            .'werden zusammen am Artikel eingetragen (Quelle produktpass_ki), bis dahin gilt der alte Stand. aktion=verwerfen lässt den Vorschlag fallen. '
            .'Nur FA-Admins; Kennzeichnung ist sicherheitsrelevant — vorher mit foodalchemist.produktpass.GET prüfen (allergene + zusatzstoffe mit Zitat). '
            .'Alter Name: foodalchemist.produktpass.ALLERGENE_BESTAETIGEN (gleiche Wirkung).';
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
                ? $svc->kennzeichnungVerwerfen($team, $pass, $context->user?->id)
                : $svc->kennzeichnungBestaetigen($team, $pass, $context->user?->id, FaRolle::Admin);
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
            'category' => 'action', 'tags' => ['foodalchemist', 'produktpass', 'allergene', 'zusatzstoffe', 'kennzeichnung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.produktpass.GET'],
            'examples' => ['Bestätige die Kennzeichnung (Allergene und Zusatzstoffe) aus Produktpass 12.'],
        ];
    }
}
