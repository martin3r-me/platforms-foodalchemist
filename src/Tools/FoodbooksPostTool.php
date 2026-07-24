<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Phase B: Foodbook-Anlage aus dem LLM-Pfad — nativ FA (Architektur-
 * Entscheidung 2026-07-01: Foodbook/Konzepte leben NUR hier, kein
 * WaWi-Konflikt). Entsteht immer als status=draft.
 *
 * Spec 19 E3.5: trägt optional die Bedarf-Defaults (Eventtyp/Servierform/
 * Ziel-Wareneinsatz/Toleranz) + 1–n Default-Zielgruppen + 1–n Einsatzmomente,
 * die als Boden in die Kapitel-Kaskade (leitplanken()) fallen. Alle IDs
 * referenzieren team-sichtbares Vokabular (FK-Pflicht, Entscheidung 6).
 */
class FoodbooksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein neues Foodbook als ENTWURF an (status=draft), optional direkt mit Kapitel-Gerüst '
            . '(kapitel: Liste von Titeln). Inhalte danach: foodalchemist.foodbook_kapitel.POST für weitere/'
            . 'verschachtelte Kapitel, foodalchemist.foodbook_blocks.POST für Gerichte/Texte/Header pro Kapitel.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'description' => 'Name des Foodbooks, z. B. "Sommerhochzeiten 2027"'],
                'jahr' => ['type' => 'integer'],
                'customer' => ['type' => 'string', 'description' => 'Kunden-Name (Freitext; CRM-Link macht der Editor)'],
                'personen' => ['type' => 'integer', 'description' => 'Default-Pax für Preis-Kalkulationen'],
                'description' => ['type' => 'string'],
                'kapitel' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optionales Kapitel-Gerüst (Titel in Reihenfolge), z. B. ["Empfang", "Vorspeisen", "Hauptgänge"]',
                ],
                // Spec 19 E3.5 — Bedarf-Defaults (kaskadieren als Boden in die Kapitel)
                'default_event_type_id' => ['type' => 'integer', 'description' => 'Default-Eventtyp (Vokabular; via foodalchemist.reference.GET)'],
                'default_serving_form_id' => ['type' => 'integer', 'description' => 'Default-Servierform (Vokabular; Scharnier zur Darreichungs-Auflösung)'],
                'target_food_cost_pct' => ['type' => 'number', 'description' => 'Ziel-Wareneinsatz in % (WE-Ampel-SOLL)'],
                'food_cost_tolerance_pp' => ['type' => 'number', 'description' => 'Toleranz in Prozentpunkten (Default 5,0 im Code)'],
                'zielgruppen' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'IDs der Default-Zielgruppen (1–n; via foodalchemist.zielgruppen.GET)',
                ],
                'einsatzmomente' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'IDs der Einsatzmomente/Tagesablauf (1–n; Vokabular)',
                ],
            ],
            'required' => ['label'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(FoodbookService::class);

        // Bedarf-Defaults: FK-Vokabular VOR dem Write auf Team-Sichtbarkeit prüfen (Entscheidung 6).
        if (isset($arguments['default_event_type_id'])
            && ! FoodAlchemistEventtyp::visibleToTeam($team)->whereKey((int) $arguments['default_event_type_id'])->exists()) {
            return ToolResult::error('default_event_type_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (isset($arguments['default_serving_form_id'])
            && ! FoodAlchemistServierform::visibleToTeam($team)->whereKey((int) $arguments['default_serving_form_id'])->exists()) {
            return ToolResult::error('default_serving_form_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $zielgruppenIds = array_map('intval', (array) ($arguments['zielgruppen'] ?? []));
        if ($zielgruppenIds !== []
            && FoodAlchemistTargetGroup::visibleToTeam($team)->whereKey($zielgruppenIds)->count() !== count(array_unique($zielgruppenIds))) {
            return ToolResult::error('Mindestens eine zielgruppen-ID ist nicht sichtbar/vorhanden — via foodalchemist.zielgruppen.GET ermitteln.', 'NOT_FOUND');
        }
        $einsatzmomentIds = array_map('intval', (array) ($arguments['einsatzmomente'] ?? []));
        if ($einsatzmomentIds !== []
            && FoodAlchemistEinsatzmoment::visibleToTeam($team)->whereKey($einsatzmomentIds)->count() !== count(array_unique($einsatzmomentIds))) {
            return ToolResult::error('Mindestens eine einsatzmomente-ID ist nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $fb = $svc->create($team, [
                'label' => (string) $arguments['label'],
                'jahr' => $arguments['jahr'] ?? null,
                'customer' => $arguments['customer'] ?? null,
                'personen' => $arguments['personen'] ?? null,
                'description' => $arguments['description'] ?? null,
                'status' => 'draft',
            ]);
            // Dimension-Defaults durchs FELDER-Update (create() setzt nur den Kern).
            $defaults = array_intersect_key($arguments, array_flip([
                'default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp',
            ]));
            if ($defaults !== []) {
                $svc->update($team, $fb->id, $defaults);
            }
            foreach (array_unique($zielgruppenIds) as $zgId) {
                $svc->toggleZielgruppe($team, $fb->id, $zgId);
            }
            foreach (array_unique($einsatzmomentIds) as $emId) {
                $svc->toggleEinsatzmoment($team, $fb->id, $emId);
            }
            $fb->refresh();
            $kapitel = [];
            foreach (array_values((array) ($arguments['kapitel'] ?? [])) as $titel) {
                $k = $svc->addKapitel($team, $fb->id, ['title' => (string) $titel]);
                $kapitel[] = ['id' => $k->id, 'title' => $k->title];
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'foodbook' => [
                'id' => $fb->id, 'label' => $fb->label, 'status' => $fb->status, 'jahr' => $fb->jahr,
                'default_event_type_id' => $fb->default_event_type_id !== null ? (int) $fb->default_event_type_id : null,
                'default_serving_form_id' => $fb->default_serving_form_id !== null ? (int) $fb->default_serving_form_id : null,
                'target_food_cost_pct' => $fb->target_food_cost_pct,
                'food_cost_tolerance_pp' => $fb->food_cost_tolerance_pp,
                'zielgruppen_ids' => array_values(array_unique($zielgruppenIds)),
                'service_moment_ids' => array_values(array_unique($einsatzmomentIds)),
            ],
            'kapitel' => $kapitel,
            'note' => 'Entwurf: Freigabe/Kunden-Verknüpfung (CRM) macht ein Mensch im Editor.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'anlegen', 'draft', 'kapitel'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook_kapitel.POST', 'foodalchemist.foodbook_blocks.POST', 'foodalchemist.foodbook.GET', 'foodalchemist.zielgruppen.GET'],
            'examples' => ['Lege ein Foodbook "Sommerhochzeiten 2027" mit Kapiteln Empfang/Vorspeisen/Hauptgänge an'],
        ];
    }
}
