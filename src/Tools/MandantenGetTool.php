<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MandantenStammblattService;

/** Spec 82 §9/§13 B3 + G · Mandanten (Verwaltungsdaten): Liste oder ein Mandant. Nie Inhalte. */
class MandantenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandanten.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne team_id: alle Mandanten (Kunden-Hauptteams neben dem Master-Team) mit Status (projekt|aktiv|beendet), Modus (kuratiert|autark), CRM-Firma und Notiz. '
            .'Mit team_id: ein Mandant. Mit stammblatt=true das Stammblatt (§13 C1): zusätzlich gebuchte Bereiche, Stamm-Paket, Abos, Unter-Teams, Kontingente, Gebühren. Es sind reine Verwaltungsdaten, nie Rezepte, Preise, Foodbooks, DNA oder Teamprofile (Spec 82 §13 G). '
            .'Nur Plattform-Admin oder Admin im Master-Team, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer'], 'stammblatt' => ['type' => 'boolean', 'description' => 'Mit Bereichen, Stamm-Paket, Abos, Unter-Teams, Kontingenten und Gebühren.']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MandantenService::class);
            $svc->pruefeVerwaltung($context->user, 'Mandanten einsehen');
            $blatt = (bool) ($arguments['stammblatt'] ?? false);
            if (empty($arguments['team_id'])) {
                return $blatt ? ['mandanten' => app(MandantenStammblattService::class)->alle($context->user)] : ['mandanten' => $svc->uebersicht()];
            }
            $mandant = Team::findOrFail((int) $arguments['team_id']);

            return $blatt ? ['mandant' => app(MandantenStammblattService::class)->stammblatt($mandant, $context->user)] : ['mandant' => $svc->zeige($mandant)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['mandanten'], ['Welche Mandanten gibt es, und welche sind kuratiert?'], ['foodalchemist.mandanten.PUT']), [
            'tags' => ['foodalchemist', 'mandanten', 'plattform'],
        ]);
    }
}
