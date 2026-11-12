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
        return 'Legt ein Gerichte-Konzept ODER ein Concepter-Paket als ENTWURF an (status=draft). '
            . 'kind=konzept (Default) = ganzes Menü/Buffet; kind=paket = wiederverwendbares Bündel (Concepter-Reiter „Pakete“), '
            . 'das per concept_slots.POST(package_id=<id>) in ein Konzept eingehängt wird. Slots (Gerichte/Pakete) danach '
            . 'via foodalchemist.concept_slots.POST. brief = KI-Arbeitsauftrag ans Konzept; zielpreis_pro_person '
            . 'für die Kalkulations-Leitplanke.'
            . ' Ablauf, Soll-Aspekte und geltende Regelwerke vorher: foodalchemist.ablauf.GET(vorgang="konzept_anlegen").';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['konzept', 'paket'], 'description' => 'konzept (Default) oder paket. paket legt ein Concepter-Paket an (Reiter „Pakete“); geruest ist dann nicht erlaubt.'],
                'occasion' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'class' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'brief' => ['type' => 'string', 'description' => 'KI-Brief: was soll das Konzept leisten'],
                'target_price_per_person' => ['type' => 'number'],
                'season' => ['type' => 'string', 'description' => 'Freitext-Saison als KI-Brief-Hinweis (NICHT die Saison-Facette)'],
                'target_group' => ['type' => 'string'],
                'diet_requirement' => ['type' => 'string'],
                'price_display' => ['type' => 'string', 'enum' => ['gesamt', 'einzel'], 'description' => 'Preisdarstellung: gesamt (ein Summenpreis fürs Konzept, Default) | einzel (Preis je Gericht/eingebettetem Paket, kein Summenpreis — Auswahl à la carte)'],
                // Umbau-Spec Phase 4: flache Facetten-Dimensionen (koppeln Slot-Darreichungs-Auflösung).
                'serving_form' => ['type' => 'string', 'description' => 'Servierform-Code/Label (z. B. buffet, flying, teller) — steuert die Slot-Darreichung'],
                'event_type' => ['type' => 'string', 'description' => 'Eventtyp-Name (Vokabular)'],
                'service_moments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Einsatzmomente (Namen, mehrfach)'],
                'seasons' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Saison-Facetten (Namen, mehrfach) — nicht die season-Freitextangabe'],
                // Spec 50 C-1: kanonische Struktur ohne KI — Header (Gang/Station) + leere Positionen sofort da.
                'geruest' => [
                    'type' => 'object',
                    'description' => 'Optional: kanonisches Gerüst gleich anlegen (Regelwerk_Concept §4). typ=menue → Gänge mit Überschrift je Gang (gaenge 3–9, Default 3); typ=buffet → 6 Stations-Sektionen (Stationen ≥2 Positionen als Paket mit Header). Danach Positionen via foodalchemist.concept_slots.PUT/POST füllen; Header via concept_blocks.PUT betexten.',
                    'properties' => [
                        'typ' => ['type' => 'string', 'enum' => ['menue', 'buffet']],
                        'gaenge' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 9],
                    ],
                    'required' => ['typ'],
                ],
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
        $kind = strtolower(trim((string) ($arguments['kind'] ?? 'konzept')));
        $kind = $kind === 'concept' ? 'konzept' : $kind;
        if (! in_array($kind, ['konzept', 'paket'], true)) {
            return ToolResult::error('kind muss "konzept" oder "paket" sein.', 'VALIDATION_ERROR');
        }
        if ($kind === 'paket' && is_array($arguments['geruest'] ?? null)) {
            return ToolResult::error('geruest gibt es nur für kind=konzept. Ein Paket befüllst du mit paket_gerichte.SET oder concept_slots.POST.', 'VALIDATION_ERROR');
        }

        try {
            $basis = [
                'name' => (string) $arguments['name'],
                'occasion' => $arguments['occasion'] ?? null,
                'level' => $arguments['level'] ?? null,
                'class' => $arguments['class'] ?? null,
                'status' => 'draft',
            ];
            $c = $kind === 'paket' ? $svc->createPaket($team, $basis) : $svc->create($team, $basis);
            if ($kind === 'paket' && ($arguments['occasion'] ?? null) !== null) {
                $c->update(['occasion' => $arguments['occasion']]);
            }
            $extras = array_intersect_key($arguments, array_flip([
                'description', 'brief', 'target_price_per_person', 'season', 'target_group', 'diet_requirement',
                'price_display',
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
            $geruest = null;
            if (is_array($arguments['geruest'] ?? null) && ($arguments['geruest']['typ'] ?? '') !== '') {
                $g = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class)->kanonischesGeruest(
                    $team, $c->refresh(), (string) $arguments['geruest']['typ'],
                    isset($arguments['geruest']['gaenge']) ? (int) $arguments['geruest']['gaenge'] : null,
                );
                $geruest = [
                    'typ' => (string) $arguments['geruest']['typ'],
                    'positionen_leer' => $g['slots'],
                    'header' => $g['header'],
                    'struktur' => $c->slots()->orderBy('position')->get(['id', 'type', 'role', 'title', 'embedded_concept_id'])
                        ->map(fn ($s) => ['slot_id' => (int) $s->id, 'type' => $s->type, 'role' => $s->role, 'title' => $s->title, 'paket_concept_id' => $s->embedded_concept_id])
                        ->all(),
                ];
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(array_filter([
            'concept' => ['id' => $c->id, 'kind' => $kind, 'name' => $c->name, 'status' => $c->status, 'serving_form_id' => $c->serving_form_id],
            'geruest' => $geruest,
            'note' => 'Entwurf: aktiv setzen macht ein Mensch im Concepter. Servierform steuert die Slot-Darreichungs-Auflösung.'
                . ($kind === 'paket' ? ' Paket: Gerichte via paket_gerichte.SET, einhängen via concept_slots.POST(concept_id, package_id=' . $c->id . ').' : '')
                . ($geruest !== null ? ' Gerüst steht: leere Positionen (type=gericht) per foodalchemist.concept_slots.PUT mit sales_recipe_id füllen, Header-Titel per concept_blocks.PUT.' : ''),
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concept_slots.POST', 'foodalchemist.concept_slots.PUT', 'foodalchemist.concept_blocks.PUT', 'foodalchemist.concepts.GET', 'foodalchemist.concepts.ENRICH'],
            'examples' => ['Lege ein Konzept "Streetfood-Hochzeit" mit Zielpreis 45 € p. P. an'],
        ];
    }
}
