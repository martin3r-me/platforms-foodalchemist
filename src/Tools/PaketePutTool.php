<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Stammfelder eines Concepter-Pakets (Entwurf) ändern. */
class PaketePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.PUT';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Bearbeitet ein team-eigenes Paket im Entwurf (felder: name/consumer_name/class/level/description/note/price_mode/price_per_person/price_override_reason). '
            . 'price_mode=auto leitet den Preis aus den Gerichten ab; fixed erfordert Preis + Begründung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $paket = $this->paketConcept($team, (int) ($arguments['id'] ?? 0));
        if ($paket instanceof ToolResult) {
            return $paket;
        }

        try {
            $svc = app(ConceptPaketService::class);
            $paket = $svc->update($team, $paket->id, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $svc->payload($paket)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.pakete.RECOMPUTE', 'foodalchemist.paket_gerichte.SET'],
            'examples' => ['Setze bei Paket 12 den Preis-Modus auf auto.'],
        ];
    }
}
