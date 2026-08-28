<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/**
 * Spec 43 (write): Aktualisiert ein eigenes Präsentations-Design (Name/Layout/Tokens).
 * Geerbte/globale Designs sind read-only (isOwnedBy).
 */
class PresentationDesignsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert ein eigenes Präsentations-Design (Tokens/Layout/custom_css/output_types). '
            . 'Nur team-eigene Designs; geerbte/globale sind read-only. Teilfelder: nur mitgeschickte Felder werden geändert. '
            . 'Freies CSS via custom_css ist möglich (siehe Feld-Beschreibung).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'base_slug' => ['type' => 'string', 'enum' => ['editorial', 'menu', 'kiosk', 'navigator']],
                'output_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['foodbook', 'speisekarte', 'speiseplan']], 'description' => 'Für welche Ausgabeformen das Design im Picker erscheint (leer = alle).'],
                'layout_json' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => PresentationDesignService::layoutVocabDoc()],
                'tokens_json' => ['type' => 'object', 'description' => PresentationDesignService::tokensVocabDoc()],
                'custom_css' => ['type' => 'string', 'description' => PresentationDesignService::customCssDoc() . ' Leerer String = CSS löschen.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $data = array_filter([
            'name' => $arguments['name'] ?? null,
            'base_slug' => $arguments['base_slug'] ?? null,
            'output_types' => $arguments['output_types'] ?? null,
            'layout_json' => $arguments['layout_json'] ?? null,
            'tokens_json' => $arguments['tokens_json'] ?? null,
            'custom_css' => $arguments['custom_css'] ?? null,
        ], fn ($v) => $v !== null);
        try {
            $d = app(PresentationDesignService::class)->update($team, (int) $arguments['id'], $data);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Design nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $d->id, 'name' => $d->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'praesentation', 'design', 'aendern'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.presentation_designs.GET'],
            'examples' => ['Ändere im Design 5 die Primärfarbe.'],
        ];
    }
}
