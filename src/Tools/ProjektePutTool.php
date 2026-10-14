<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\ProjektService;

/** Spec 82 §9 · Projekt pflegen (Kopf, Phase, Status) und seine Kunden-Standorte (anlegen, Pilot, entfernen). */
class ProjektePutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.projekte.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt ein Projekt: name, ziel, phase, status, planner_projekt_id (nur Verweis), pilot_standort_id. Nicht übergebene Felder bleiben. '
            .'Gate (hart): struktur_freigeben_kontakt_id (CRM-Kontakt der Person, braucht Paketstufe, Anlass und Musterseite im Projekt-Canvas) gibt Struktur + Musterseite frei, struktur_zuruecknehmen=true nimmt zurück; foodbook_anlegen=true legt das vorbefüllte Foodbook im Master-Team an (nur nach Freigabe). Standorte: standort_neu (Name) legt einen an (der erste wird Pilot), standort_entfernen (ID) löscht einen. Ab Rolle Kuratieren, nur im Master-Team.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'ziel' => ['type' => 'string'],
            'phase' => ['type' => 'string', 'enum' => array_keys(FoodAlchemistProjekt::PHASEN)],
            'status' => ['type' => 'string', 'enum' => FoodAlchemistProjekt::STATUS],
            'planner_projekt_id' => ['description' => 'Ganzzahl oder null (null hebt auf)'], 'pilot_standort_id' => ['description' => 'Ganzzahl oder null (null hebt auf)'],
            'struktur_freigeben_kontakt_id' => ['type' => 'integer'], 'struktur_zuruecknehmen' => ['type' => 'boolean'], 'foodbook_anlegen' => ['type' => 'boolean'],
            'standort_neu' => ['type' => 'string'], 'standort_entfernen' => ['type' => 'integer'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(ProjektService::class);
            $id = (int) $arguments['id'];
            if (! empty($arguments['standort_neu'])) {
                $svc->standortAnlegen($team, $context->user, $id, (string) $arguments['standort_neu']);
            }
            if (! empty($arguments['standort_entfernen'])) {
                $svc->standortLoeschen($team, $context->user, $id, (int) $arguments['standort_entfernen']);
            }
            if (! empty($arguments['struktur_zuruecknehmen'])) {
                $svc->strukturZuruecknehmen($team, $context->user, $id);
            }
            if (! empty($arguments['struktur_freigeben_kontakt_id'])) {
                $svc->strukturFreigeben($team, $context->user, $id, (int) $arguments['struktur_freigeben_kontakt_id']);
            }
            $foodbookId = ! empty($arguments['foodbook_anlegen']) ? $svc->foodbookAnlegen($team, $context->user, $id)->id : null;
            $felder = array_intersect_key($arguments, array_flip(['name', 'ziel', 'phase', 'status', 'planner_projekt_id', 'pilot_standort_id']));
            $p = $felder === [] ? $svc->finde($team, $id) : $svc->setze($team, $context->user, $id, $felder);

            return ['projekt' => ['id' => $p->id, 'name' => $p->name, 'phase' => $p->phase, 'status' => $p->status, 'pilot_standort_id' => $p->pilot_standort_id,
                'struktur_freigegeben' => $p->strukturFreigegeben(), 'struktur_freigegeben_von' => $p->struktur_freigegeben_von_name], 'foodbook_id' => $foodbookId];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['projekte'], ['Setze das Projekt auf Phase Paketlogik.'], ['foodalchemist.projekte.GET']), [
            'tags' => ['foodalchemist', 'projekte', 'agentur', 'write'],
        ]);
    }
}
