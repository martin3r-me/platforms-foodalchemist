<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** Phase C: Gerichte-Konzept anlegen — immer status=draft (Aktivierung menschlich). */
class ConceptsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Gerichte-Konzept als ENTWURF an (status=draft). Slots (Gerichte/Pakete) danach '
            . 'via foodalchemist.concept_slots.POST. brief = KI-Arbeitsauftrag ans Konzept; zielpreis_pro_person '
            . 'für die Kalkulations-Leitplanke.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'occasion' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'class' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'brief' => ['type' => 'string', 'description' => 'KI-Brief: was soll das Konzept leisten'],
                'target_price_per_person' => ['type' => 'number'],
                'season' => ['type' => 'string', 'description' => 'Freitext-Saison als KI-Brief-Hinweis (NICHT die Saison-Facette)'],
                'target_group' => ['type' => 'string'],
                'diet_requirement' => ['type' => 'string'],
                // Umbau-Spec Phase 4: flache Facetten-Dimensionen (koppeln Slot-Darreichungs-Auflösung).
                'serving_form' => ['type' => 'string', 'description' => 'Servierform-Code/Label (z. B. buffet, flying, teller) — steuert die Slot-Darreichung'],
                'event_type' => ['type' => 'string', 'description' => 'Eventtyp-Name (Vokabular)'],
                'service_moments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Einsatzmomente (Namen, mehrfach)'],
                'seasons' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Saison-Facetten (Namen, mehrfach) — nicht die season-Freitextangabe'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ConceptService::class);

        try {
            $c = $svc->create($team, [
                'name' => (string) $arguments['name'],
                'occasion' => $arguments['occasion'] ?? null,
                'level' => $arguments['level'] ?? null,
                'class' => $arguments['class'] ?? null,
                'status' => 'draft',
            ]);
            $extras = array_intersect_key($arguments, array_flip([
                'description', 'brief', 'target_price_per_person', 'season', 'target_group', 'diet_requirement',
            ]));
            // Facetten (Phase 4): Slug/Name → id.
            if (($arguments['serving_form'] ?? '') !== '') {
                $extras['serving_form_id'] = $this->resolveServierformId($team, (string) $arguments['serving_form']);
            }
            if (($arguments['event_type'] ?? '') !== '') {
                $extras['event_type_id'] = $this->resolveFacetId($team, 'foodalchemist_event_types', (string) $arguments['event_type']);
            }
            if ($extras !== []) {
                $c = $svc->update($team, $c->id, $extras);
            }
            if (! empty($arguments['service_moments'])) {
                $svc->syncEinsatzmomente($team, $c->id, $this->resolveFacetIds($team, 'foodalchemist_service_moments', (array) $arguments['service_moments']));
            }
            if (! empty($arguments['seasons'])) {
                $svc->syncSaisons($team, $c->id, $this->resolveFacetIds($team, 'foodalchemist_seasons', (array) $arguments['seasons']));
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'concept' => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'serving_form_id' => $c->serving_form_id],
            'note' => 'Entwurf: aktiv setzen macht ein Mensch im Concepter. Servierform steuert die Slot-Darreichungs-Auflösung.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concept_slots.POST', 'foodalchemist.concepts.GET'],
            'examples' => ['Lege ein Konzept "Streetfood-Hochzeit" mit Zielpreis 45 € p. P. an'],
        ];
    }
}
