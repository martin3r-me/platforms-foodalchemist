<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Paket (kind=paket-Concept) als Entwurf anlegen; Gerichte danach via paket_gerichte.SET. */
class PaketePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.POST';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Legt ein team-eigenes Paket als ENTWURF an (name; optional class/level/consumer_name/price_mode). '
            . 'price_mode=fixed erfordert price_per_person + price_override_reason. Gerichte danach via paket_gerichte.SET, '
            . 'einhängen ins Konzept via concept_slots.POST(concept_id, package_id=<diese id>). Gleichwertig: concepts.POST(kind=paket).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Paket-Name (intern).'],
                'consumer_name' => ['type' => 'string', 'description' => 'Kundenseitiger Name (Stationszeile in Angebot/Präsentation).'],
                'class' => ['type' => 'string', 'description' => 'Klasse.'],
                'level' => ['type' => 'string', 'description' => 'Niveau.'],
                'description' => ['type' => 'string'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'fixed', 'manuell'], 'description' => 'Preis-Modus (Default auto = Summe der Gerichte).'],
                'price_per_person' => ['type' => 'number', 'description' => 'Preis/Person (bei fixed/manuell).'],
                'price_override_reason' => ['type' => 'string', 'description' => 'Begründung (bei fixed Pflicht).'],
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
        if (trim((string) ($arguments['name'] ?? '')) === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }
        if (array_key_exists('role', $arguments)) {
            // role gab es nur im Alt-Modell; Concepter-Pakete tragen die Rolle am Slot (concept_slots.POST role).
            unset($arguments['role']);
        }

        try {
            $svc = app(ConceptPaketService::class);
            $paket = $svc->create($team, $arguments);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $svc->payload($paket),
            'note' => 'Entwurf: aktiv setzen macht ein Mensch im Concepter (Reiter Pakete). Rolle/Gang setzt du beim Einhängen über concept_slots.POST(role).']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.paket_gerichte.SET', 'foodalchemist.concept_slots.POST', 'foodalchemist.pakete.PUT'],
            'examples' => ['Lege das Paket „NOSE TO TAIL" an.'],
        ];
    }
}
