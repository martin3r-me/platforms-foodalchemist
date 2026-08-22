<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Format-Modul (Phase C): ein Format als LIVE Format-Kapitel ins Foodbook einfügen.
 * Legt ein Kapitel mit format_id an; Editionen + Bildwelt rendern live. Kunden-IP-
 * und Status-Guard (versendete/archivierte Bücher sind zu). Kein Recompute.
 */
class FoodbookInsertFormatTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_format_chapters.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Format als LIVE Format-Kapitel in ein Foodbook ein (Marken-Container mit '
            . 'Editionen als Showcase). Identität wird aus dem Format geseedet, Editionen/Bilder rendern '
            . 'live. Preis = Range (nicht additiv). Kunden-IP-Guard: fremdes Kunden-Format wird abgelehnt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'foodbook_id' => ['type' => 'integer'],
                'format_id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer', 'description' => 'optionales Eltern-Kapitel'],
            ],
            'required' => ['foodbook_id', 'format_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $kapitel = app(FoodbookService::class)->insertFormatChapter(
                $team,
                (int) $arguments['foodbook_id'],
                (int) $arguments['format_id'],
                isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null,
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Foodbook oder Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'chapter' => ['id' => $kapitel->id, 'title' => $kapitel->title, 'format_id' => $kapitel->format_id],
            'note' => 'Format-Kapitel angelegt — Editionen/Bilder rendern live aus dem Format.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'format', 'kapitel', 'einfuegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.foodbook_kapitel.POST'],
            'examples' => ['Füge das Format CHEFS.CORNER als Kapitel in Foodbook 12 ein'],
        ];
    }
}
