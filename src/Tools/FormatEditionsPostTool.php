<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: bestehendes Konzept als Edition einem Format zuordnen. */
class FormatEditionsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_editions.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Konzept (Zusammenstellung) als Aufbau-Position (Referenz) in ein Format ein — ENTWEDER ein bestehendes '
            . '(concept_id) ODER eine NEUE Edition (neu={name, geruest}). neu.geruest={typ: menue|buffet, gaenge} legt die '
            . 'kanonische Struktur wie concepts.POST geruest an (Header + leere Positionen, Entwurf → per concept_slots.PUT füllen); '
            . 'ohne geruest entsteht die UI-Standard-Edition (aktiv, Sektions-Header Amuse/Vorspeise/Hauptgang/Dessert). '
            . 'F2-Referenz-Modell: ein Konzept kann in mehreren Formaten stehen (kein format_id-Besitz mehr). '
            . 'Optional direkt hinter einer Ziel-Position (after_slot_id). Guardet das Format (team-eigen). Kein Recompute.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer', 'description' => 'bestehendes Konzept referenzieren (XOR mit neu)'],
                'neu' => [
                    'type' => 'object',
                    'description' => 'NEUE Edition anlegen und einfügen (XOR mit concept_id). Ohne geruest: UI-Standard (aktiv, 4 Sektions-Header). Mit geruest: kanonisch wie concepts.POST geruest (Entwurf, Header + leere Positionen).',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'geruest' => [
                            'type' => 'object',
                            'properties' => [
                                'typ' => ['type' => 'string', 'enum' => ['menue', 'buffet']],
                                'gaenge' => ['type' => 'integer', 'description' => 'nur menue: Gangzahl (Default Regelwerk)'],
                            ],
                            'required' => ['typ'],
                        ],
                    ],
                ],
                'after_slot_id' => ['type' => 'integer', 'description' => 'optional: neue Position direkt hinter diesem Slot einsortieren (sonst ans Ende)'],
            ],
            'required' => ['format_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $conceptId = isset($arguments['concept_id']) ? (int) $arguments['concept_id'] : null;
        $neu = is_array($arguments['neu'] ?? null) ? $arguments['neu'] : null;
        if (($conceptId === null) === ($neu === null)) {
            return ToolResult::error('Genau eines von concept_id (bestehend) oder neu={name, geruest} (neue Edition) angeben.', 'VALIDATION_ERROR');
        }
        $after = isset($arguments['after_slot_id']) ? (int) $arguments['after_slot_id'] : null;

        $geruest = null;
        try {
            if ($conceptId !== null) {
                $slot = app(FormatService::class)->slotConceptEinfuegen($team, (int) $arguments['format_id'], $conceptId, $after);
            } else {
                // C-5: neue Edition mit derselben Struktur wie die UI (oder kanonisch wie concepts.POST geruest).
                $g = is_array($neu['geruest'] ?? null) ? $neu['geruest'] : null;
                $e = app(FormatService::class)->neueEdition(
                    $team, (int) $arguments['format_id'],
                    isset($neu['name']) ? (string) $neu['name'] : null, $after, $g,
                );
                $slot = $e['slot'];
                $geruest = [
                    'typ' => $g !== null ? (string) ($g['typ'] ?? '') : 'sektionen',
                    'status' => (string) $e['concept']->status,
                    'header' => $e['header'],
                    'positionen_leer' => $e['positionen_leer'],
                ];
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Format oder Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(array_filter([
            'edition' => [
                'slot_id' => $slot->id, 'concept_id' => (int) $slot->concept_id,
                'format_id' => (int) $slot->format_id, 'position' => (int) $slot->position,
            ],
            'geruest' => $geruest,
            'note' => $geruest === null ? null : ($geruest['typ'] === 'sektionen'
                ? 'Edition aktiv mit Sektions-Headern — Gerichte per foodalchemist.concept_blocks/concept_slots.PUT ergänzen.'
                : 'Entwurf mit kanonischem Gerüst — leere Positionen per foodalchemist.concept_slots.PUT mit sales_recipe_id füllen, dann aktiv setzen (Mensch).'),
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'edition', 'zuordnen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.DELETE', 'foodalchemist.formats.GET', 'foodalchemist.concepts.POST', 'foodalchemist.concept_slots.PUT'],
            'examples' => ['Ordne Konzept 12 dem Format 3 zu', 'Lege im Format 3 eine neue Edition „Herbst" als 4-Gang-Menü-Gerüst an'],
        ];
    }
}
