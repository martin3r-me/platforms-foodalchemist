<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** MCP-Steuerbarkeit · D8: Speisekarte mit einem CRM-Kunden verknüpfen/lösen. */
class SpeisekarteCustomerLinkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte.CUSTOMER_LINK';
    }

    public function getDescription(): string
    {
        return 'Verknüpft eine team-eigene Speisekarte mit einer CRM-Firma (+ optional Kontakt). company_id null = lösen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Speisekarte-Id.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeisekarte::class, $id, 'Speisekarte')) !== null) {
            return $guard;
        }

        try {
            $karte = app(SpeisekarteService::class)->verknuepfeKunde(
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
            'crm_company_id' => $karte->crm_company_id !== null ? (int) $karte->crm_company_id : null,
            'crm_contact_id' => $karte->crm_contact_id !== null ? (int) $karte->crm_contact_id : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'crm', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speisekarten.PUT'],
            'examples' => ['Verknüpfe Speisekarte 3 mit CRM-Firma 42.'],
        ];
    }
}
