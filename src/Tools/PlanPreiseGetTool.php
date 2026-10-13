<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PlanNutzungService;
use Platform\FoodAlchemist\Services\PlanPreisService;

/** Spec 82 §13 D/B5 · Gebühren-Preise: aktuelle Monatspreise je Baustein, Historie, optional Auswirkung eines neuen Preises (nur Plattform-Admin). */
class PlanPreiseGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.plan_preise.GET';
    }

    public function getDescription(): string
    {
        return 'Gebühren-Bausteine (basis, user, unterteam, stamm_paket, master_abo) mit aktuellem Monatspreis netto und Historie. '
            .'Mit baustein + neuer_betrag: Auswirkung auf die aktiven Mandanten (Mehr-/Minderbetrag je Monat, nur Zählung). Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'baustein' => ['type' => 'string', 'enum' => ['basis', 'user', 'unterteam', 'stamm_paket', 'master_abo']],
            'neuer_betrag' => ['type' => 'number', 'description' => 'Nur mit baustein: Auswirkung dieses Monatsbetrags vorrechnen.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $preise = app(PlanPreisService::class);
            $preise->nurPlattformAdminOeffentlich($context->user, 'Gebühren-Preise einsehen');
            $baustein = $arguments['baustein'] ?? null;
            $r = ['aktuell' => $preise->aktuell(), 'historie' => $preise->historie($baustein)];
            if ($baustein !== null && isset($arguments['neuer_betrag'])) {
                $r['auswirkung'] = app(PlanNutzungService::class)->auswirkung($baustein, $arguments['neuer_betrag']);
            }

            return $r;
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['gebuehren'], ['Was kostet ein weiterer User, und wie wirkt 21 € statt 19 €?'], ['foodalchemist.plan_preise.POST', 'foodalchemist.plan_nutzung.GET']), [
            'tags' => ['foodalchemist', 'gebuehren', 'plattform'],
        ]);
    }
}
