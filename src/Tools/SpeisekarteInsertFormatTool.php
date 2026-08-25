<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Format-Umbau F5: ein Format als Rubrik in eine Speisekarte buchen — WIE EIN CONCEPT,
 * aus live-referenzierten Positionen (menue_ref je Edition + header/text/spacer), NICHT
 * über den entfernten Live-Format-Sonderweg (kein `format_id`, kein ist_format-Zweig).
 * Status-Guard (archivierte Karten sind zu). Spiegelt {@see FoodbookInsertFormatTool}.
 */
class SpeisekarteInsertFormatTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_format_rubriken.POST';
    }

    public function getDescription(): string
    {
        return 'Bucht ein Format als Rubrik in eine Speisekarte aus live menue_ref-Positionen (kein '
            . 'Live-Format-Sonderweg). Das Format wird seine eigene Rubrik (Titel/Kundentitel/Claim/Hinführung '
            . 'aus dem Format); die Editionen werden menue_ref-Positionen, die Struktur header/text/spacer '
            . '— alles live über die Kaskade.';
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
            $rubrik = app(SpeisekarteService::class)->insertFormatAlsRubrik(
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
            'rubrik' => ['id' => $rubrik->id, 'title' => $rubrik->title, 'consumer_title' => $rubrik->consumer_title],
            'note' => 'Format als Rubrik gebucht — Editionen als live menue_ref-Positionen (kein Format-Sonderweg).',
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
