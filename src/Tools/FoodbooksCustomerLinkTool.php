<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Foodbook mit einem CRM-Kunden verknüpfen/lösen. */
class FoodbooksCustomerLinkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.CUSTOMER_LINK';
    }

    public function getDescription(): string
    {
        return 'Verknüpft ein team-eigenes Foodbook mit einer CRM-Firma (+ optional Kontakt). '
            . 'company_id null = Verknüpfung lösen. Firmen/Kontakte via CRM-Suche ermitteln.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'company_id' => ['type' => 'integer', 'description' => 'CRM-Firma-Id (null/weglassen = lösen).'],
                'contact_id' => ['type' => 'integer', 'description' => 'Optionaler CRM-Kontakt.'],
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

        try {
            $fb = app(FoodbookService::class)->verknuepfeKunde(
                $team,
                $id,
                isset($arguments['company_id']) ? (int) $arguments['company_id'] : null,
                isset($arguments['contact_id']) ? (int) $arguments['contact_id'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => $id,
            'crm_company_id' => $fb->crm_company_id !== null ? (int) $fb->crm_company_id : null,
            'crm_contact_id' => $fb->crm_contact_id !== null ? (int) $fb->crm_contact_id : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'crm', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbooks.PUT'],
            'examples' => ['Verknüpfe Foodbook 5 mit CRM-Firma 42.'],
        ];
    }
}
