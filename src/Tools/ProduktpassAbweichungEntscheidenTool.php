<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassAbweichungService;

/** Necta-Abweichung an einem Produktpass-Feld entscheiden: Pass behalten oder Necta übernehmen — per MCP nur für FA-Admins. */
class ProduktpassAbweichungEntscheidenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.ABWEICHUNG_ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Entscheidet eine Necta-Abweichung an einem Produktpass-Feld (Allergene, Unterarten, Zusatzstoffe, Nährwerte, Zutatenliste; id aus '
            .'foodalchemist.produktpass.GET → necta_abweichungen). aktion=behalten: der Pass-Wert bleibt, dieselbe Necta-Angabe wird nicht wieder gemeldet. '
            .'aktion=necta: der Necta-Wert wird eingetragen, der Pass schützt das Feld danach nicht mehr. Ist der Pass veraltet: neuen hochladen '
            .'(foodalchemist.produktpass.POST). Nur FA-Admins; Kennzeichnung ist sicherheitsrelevant.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Abweichung (necta_abweichungen[].id)'],
                'aktion' => ['type' => 'string', 'enum' => ['behalten', 'necta']],
            ],
            'required' => ['id', 'aktion'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $aktion = (string) ($arguments['aktion'] ?? '');
        if (! in_array($aktion, ['behalten', 'necta'], true)) {
            return ToolResult::error('aktion muss behalten oder necta sein.', 'VALIDATION_ERROR');
        }
        $svc = app(ProduktpassAbweichungService::class);
        try {
            $aktion === 'behalten'
                ? $svc->behalten($team, (int) ($arguments['id'] ?? 0), $context->user?->id, FaRolle::Admin)
                : $svc->uebernehmen($team, (int) ($arguments['id'] ?? 0), $context->user?->id, FaRolle::Admin);
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $arguments['id'], 'status' => $aktion === 'behalten' ? 'verworfen' : 'uebernommen']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'produktpass', 'necta', 'allergene', 'kennzeichnung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.produktpass.GET', 'foodalchemist.produktpass.POST'],
            'examples' => ['Behalte bei Artikel 4711 den Produktpass-Wert für Milch.'],
        ];
    }
}
