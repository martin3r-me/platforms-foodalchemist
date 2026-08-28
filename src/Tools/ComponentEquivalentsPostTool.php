<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent as Equiv;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;

/**
 * MCP-Steuerbarkeit · D1: Äquivalenz (Ersatz) anlegen/aktualisieren — polymorph GP↔GP/GP↔Rezept/Rezept↔Rezept.
 * Team-weit kuratiert (dedupe je team+Seiten-Paar). Beide Seiten werden team-scoped re-autorisiert.
 */
class ComponentEquivalentsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const KINDS = [Equiv::KIND_GP, Equiv::KIND_RECIPE];

    public function getName(): string
    {
        return 'foodalchemist.component_equivalents.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Ersatz-/Äquivalenz-Beziehung an (GP oder Rezept ↔ GP oder Rezept), team-weit kuratiert. '
            . 'umrechnungsfaktor skaliert die Menge beim Tausch. Beide Seiten müssen im Team sichtbar sein.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_kind' => ['type' => 'string', 'enum' => self::KINDS, 'description' => 'Art der Quelle: gp|recipe.'],
                'source_id' => ['type' => 'integer', 'description' => 'Id der Quelle.'],
                'alt_kind' => ['type' => 'string', 'enum' => self::KINDS, 'description' => 'Art der Alternative: gp|recipe.'],
                'alt_id' => ['type' => 'integer', 'description' => 'Id der Alternative.'],
                'umrechnungsfaktor' => ['type' => 'number', 'description' => 'Mengen-Faktor Quelle→Alternative (Default 1.0).'],
                'standard_seite' => ['type' => 'string', 'enum' => [Equiv::SEITE_SOURCE, Equiv::SEITE_ALT], 'description' => 'Welche Seite ist die Standard-Realisierung.'],
                'notes' => ['type' => 'string', 'description' => 'Notiz (optional).'],
            ],
            'required' => ['source_kind', 'source_id', 'alt_kind', 'alt_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        foreach ([['source_kind', 'source_id'], ['alt_kind', 'alt_id']] as [$kf, $idf]) {
            $kind = (string) ($arguments[$kf] ?? '');
            $id = (int) ($arguments[$idf] ?? 0);
            if (! in_array($kind, self::KINDS, true) || $id <= 0) {
                return ToolResult::error("{$kf}/{$idf} ungültig (kind=gp|recipe, id>0).", 'VALIDATION_ERROR');
            }
            $sichtbar = $kind === Equiv::KIND_GP
                ? FoodAlchemistGp::visibleToTeam($team)->whereKey($id)->exists()
                : FoodAlchemistRecipe::visibleToTeam($team)->whereKey($id)->exists();
            if (! $sichtbar) {
                return ToolResult::error("{$kf}={$kind} #{$id} nicht sichtbar/vorhanden.", 'NOT_FOUND');
            }
        }

        try {
            $eq = app(ComponentEquivalentService::class)->verknuepfe(
                $team,
                (string) $arguments['source_kind'], (int) $arguments['source_id'],
                (string) $arguments['alt_kind'], (int) $arguments['alt_id'],
                (float) ($arguments['umrechnungsfaktor'] ?? 1.0),
                (string) ($arguments['standard_seite'] ?? Equiv::SEITE_SOURCE),
                isset($arguments['notes']) ? (string) $arguments['notes'] : null,
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $eq->id,
            'source_kind' => $eq->source_kind, 'source_id' => (int) $eq->source_id,
            'alt_kind' => $eq->alt_kind, 'alt_id' => (int) $eq->alt_id,
            'umrechnungsfaktor' => (float) $eq->umrechnungsfaktor,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'ersatz', 'aequivalenz', 'substitution', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.component_equivalents.DELETE'],
            'examples' => ['Lege eine Äquivalenz GP 12 ↔ GP 34 (Faktor 1.0) an.'],
        ];
    }
}
