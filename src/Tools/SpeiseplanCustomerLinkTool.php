<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Speiseplan mit einem CRM-Kunden verknüpfen/lösen. */
class SpeiseplanCustomerLinkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.CUSTOMER_LINK';
    }

    public function getDescription(): string
    {
        return 'Verknüpft einen team-eigenen Speiseplan mit einer CRM-Firma (+ optional Kontakt). company_id null = lösen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'company_id' => ['type' => 'integer', 'description' => 'CRM-Firma (null/weglassen = lösen).'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $id, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            $plan = app(SpeiseplanService::class)->verknuepfeKunde(
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
            'crm_company_id' => $plan->crm_company_id !== null ? (int) $plan->crm_company_id : null,
            'crm_contact_id' => $plan->crm_contact_id !== null ? (int) $plan->crm_contact_id : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'crm', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplaene.PUT'],
            'examples' => ['Verknüpfe Speiseplan 3 mit CRM-Firma 42.'],
        ];
    }
}
