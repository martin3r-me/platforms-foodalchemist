<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MandantenService;

/** Spec 82 §9/§13 B3 + G · Mandant pflegen: Status, Modus, CRM-Firma, Notiz. Der Modus nur durch Plattform-Admin. */
class MandantenPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandanten.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt die Verwaltungsdaten eines Mandanten (Kunden-Hauptteam): status (projekt|aktiv|beendet), modus (kuratiert|autark, Default autark), '
            .'crm_company_id (CRM-Firma aus dem CRM des Master-Teams, null = lösen), notiz. Nicht übergebene Felder bleiben. '
            .'ki_deckel_eur (Euro je Monat, null = Standard) und ki_deckel_unbegrenzt setzen den KI-Monatsdeckel des Mandanten; ohne Mandanten-Zeile bleibt der Status unverändert. '
            .'Den Modus schaltet NUR ein Plattform-Admin um (protokolliert); Status, CRM-Firma, Notiz und KI-Deckel auch ein Admin im Master-Team. Sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'team_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['projekt', 'aktiv', 'beendet']],
            'modus' => ['type' => 'string', 'enum' => ['kuratiert', 'autark']],
            'crm_company_id' => ['description' => 'Ganzzahl oder null (null hebt auf)'],
            'notiz' => ['type' => 'string'],
            'ki_deckel_eur' => ['description' => 'KI-Monatsdeckel in Euro (Zahl ≥ 0) oder null = Standard (Projekt/ohne Zeile: foodalchemist.ai.projekt_deckel_eur, aktiv: ungedeckelt)'],
            'ki_deckel_unbegrenzt' => ['type' => 'boolean', 'description' => 'true = kein KI-Deckel für diesen Mandanten (auch im Projekt-Status)'],
        ], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $mandant = Team::findOrFail((int) ($arguments['team_id'] ?? 0));
            $felder = array_intersect_key($arguments, array_flip(['status', 'modus', 'crm_company_id', 'notiz', 'ki_deckel_eur', 'ki_deckel_unbegrenzt']));

            return ['mandant' => app(MandantenService::class)->setze($mandant, $felder, $context->user)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['mandanten'], ['Soulkitchen ist ein Projekt und autark.'], ['foodalchemist.mandanten.GET']), [
            'tags' => ['foodalchemist', 'mandanten', 'plattform', 'write'],
        ]);
    }
}
