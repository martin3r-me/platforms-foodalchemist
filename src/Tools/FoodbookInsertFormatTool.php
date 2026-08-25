<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Format-Umbau F5: ein Format als Kapitel in ein Foodbook buchen — WIE EIN CONCEPT,
 * aus live-referenzierten Blöcken (concept_ref je Edition + header/text/spacer), NICHT
 * über den entfernten Live-Format-Sonderweg (kein `format_id`, kein ist_format-Zweig).
 * Kunden-IP- und Status-Guard (versendete/archivierte Bücher sind zu).
 */
class FoodbookInsertFormatTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_format_chapters.POST';
    }

    public function getDescription(): string
    {
        return 'Bucht ein Format als Kapitel in ein Foodbook aus live concept_ref-Blöcken (kein '
            . 'Live-Format-Sonderweg). Das Format wird sein eigenes Kapitel (Titel/Kundentitel/Hinführung '
            . 'aus dem Format); die Editionen werden concept_ref-Blöcke, die Struktur header_frei/text/spacer '
            . '— alles live über die Kaskade. Kunden-IP-Guard: fremdes Kunden-Format wird abgelehnt.';
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
            $kapitel = app(FoodbookService::class)->insertFormatAlsKapitel(
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
            'chapter' => ['id' => $kapitel->id, 'title' => $kapitel->title, 'consumer_title' => $kapitel->consumer_title],
            'note' => 'Format als Kapitel gebucht — Editionen als live concept_ref-Blöcke (kein Format-Sonderweg).',
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
