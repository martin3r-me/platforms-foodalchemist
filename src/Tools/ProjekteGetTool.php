<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\ProjektService;

/** Spec 82 §9 · Projekte (Agentur-Akten im Master-Team) lesen: Liste oder ein Projekt mit Standorten. */
class ProjekteGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.projekte.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne id: alle Projekte des Master-Teams (optional status aktiv|pausiert|abgeschlossen). Mit id: ein Projekt mit Kunden-Standorten. '
            .'Ein Projekt ist eine Agentur-Akte zu einer CRM-Firma; es gibt Projekte nur im Master-Team. Den Projekt-Canvas (Zielbild, Rollen, Termine …) liest canvas.GET mit type=projekt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => FoodAlchemistProjekt::STATUS],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $svc = app(ProjektService::class);
            if (! empty($arguments['id'])) {
                return ['projekt' => $this->zeile($svc->finde($team, (int) $arguments['id'])->load('standorte'), true)];
            }

            return ['projekte' => $svc->liste($team, $arguments['status'] ?? null)->map(fn ($p) => $this->zeile($p, false))->all(), 'phasen' => FoodAlchemistProjekt::PHASEN];
        });
    }

    private function zeile(FoodAlchemistProjekt $p, bool $mitStandorten): array
    {
        $z = ['id' => $p->id, 'name' => $p->name, 'crm_company_id' => $p->crm_company_id, 'phase' => $p->phase, 'status' => $p->status,
            'ziel' => $p->ziel, 'pilot_standort_id' => $p->pilot_standort_id, 'planner_projekt_id' => $p->planner_projekt_id];
        if ($mitStandorten) {
            $z['standorte'] = $p->standorte->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'ist_pilot' => (bool) $s->ist_pilot, 'notiz' => $s->notiz])->all();
        }

        return $z;
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['projekte'], ['Welche Projekte laufen?'], ['foodalchemist.projekte.POST', 'foodalchemist.canvas.GET']), [
            'tags' => ['foodalchemist', 'projekte', 'agentur', 'read'],
        ]);
    }
}
