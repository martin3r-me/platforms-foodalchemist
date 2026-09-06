<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Layout-Block (Header/Text/Spacer) an einem team-eigenen Konzept anlegen. */
class ConceptBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Layout-Block (type z.B. header/text/spacer) an einem team-eigenen Konzept an (felder optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'type' => ['type' => 'string', 'description' => 'Block-Typ.'],
                'felder' => ['type' => 'object', 'description' => 'Block-Felder. Erlaubt: `title` (die Überschrift — NUR sie wird im Kundendokument gerendert), `text_content` (Fließtext bei type=text), `height` (bei type=spacer: schmal|mittel|breit), `price_value` + `price_basis` (nur bei type=header_preis). Achtung: NICHT `label`/`text` — die werden stillschweigend nicht geschrieben.'],
            ],
            'required' => ['concept_id', 'type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $type = trim((string) ($arguments['type'] ?? ''));
        if ($type === '') {
            return ToolResult::error('type ist Pflicht.', 'VALIDATION_ERROR');
        }
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $conceptId, 'Konzept')) !== null) {
            return $guard;
        }

        $felder = is_array($arguments['felder'] ?? null) ? $arguments['felder'] : [];
        if (($fehler = self::pruefeFelder($felder)) !== null) {
            return ToolResult::error($fehler, 'VALIDATION_ERROR');
        }

        try {
            $slot = app(ConceptService::class)->addBlock($team, $conceptId, $type, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => $conceptId, 'slot_id' => (int) $slot->id, 'type' => $type]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'block', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concept_blocks.PUT'],
            'examples' => ['Füge Konzept 7 einen Header-Block hinzu.'],
        ];
    }

    /**
     * Spec 50 · A3 — unbekannte Feld-Keys melden statt schlucken.
     *
     * `ConceptService::addBlock`/`updateBlock` lesen genau diese fünf Keys; alles andere fällt
     * still auf den Boden. Die Tool-Beschreibung nannte bis 2026-09-05 `label`/`text` — ein
     * Agent, der ihr folgte, legte einen titellosen Block an und bekam trotzdem `success`.
     * Dieselbe Ergonomie wie die Einheiten-Auflösung in FoodAlchemistTool: Fehler MIT der
     * Liste des Erlaubten, statt einer stillen Nullwirkung.
     */
    public const BLOCK_FELDER = ['title', 'text_content', 'height', 'price_value', 'price_basis'];

    public static function pruefeFelder(array $felder): ?string
    {
        $unbekannt = array_diff(array_keys($felder), self::BLOCK_FELDER);
        if ($unbekannt === []) {
            return null;
        }

        return 'Unbekannte Block-Felder: ' . implode(', ', $unbekannt)
            . '. Erlaubt: ' . implode(', ', self::BLOCK_FELDER)
            . '. (Die Überschrift heisst `title`, nicht `label`; der Fliesstext `text_content`, nicht `text`.)';
    }
}
