<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Spec 42 (Folge) — ein Format als LIVE Format-Rubrik in eine Speisekarte einfügen (gleiche Logik wie
 * das Foodbook-Format-Kapitel, {@see FoodbookInsertFormatTool}). Legt eine Rubrik mit format_id an;
 * die Editionen rendern live im Dokument/der Vorschau. Status-Guard (archivierte Karten sind zu).
 */
class SpeisekarteInsertFormatTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_format_rubriken.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Format als LIVE Format-Rubrik in eine Speisekarte ein (Marken-Container mit '
            . 'Editionen). Identität wird aus dem Format geseedet, Editionen rendern live (Preis = Range, '
            . 'nicht additiv). Eigener Weg — NICHT der Gericht/Concept-Positions-Picker.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'format_id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer', 'description' => 'optionale Eltern-Rubrik'],
            ],
            'required' => ['speisekarte_id', 'format_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $rubrik = app(SpeisekarteService::class)->insertFormatRubrik(
                $team,
                (int) $arguments['speisekarte_id'],
                (int) $arguments['format_id'],
                isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null,
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Speisekarte oder Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'rubrik' => ['id' => $rubrik->id, 'title' => $rubrik->title, 'format_id' => $rubrik->format_id],
            'note' => 'Format-Rubrik angelegt — Editionen rendern live aus dem Format.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'format', 'rubrik', 'einfuegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.speisekarte_rubrik.POST', 'foodalchemist.foodbook_format_chapters.POST'],
            'examples' => ['Füge das Format CHEFS.CORNER als Rubrik in Speisekarte 7 ein'],
        ];
    }
}
