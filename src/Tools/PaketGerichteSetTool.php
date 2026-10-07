<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * MCP-Steuerbarkeit · D5d: Gericht-Positionen eines Pakets setzen (Vollersatz) — so fügt auch die UI
 * hinzu/entfernt (ganze Liste). Danach Preis-Recompute im Auto-Modus.
 */
class PaketGerichteSetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.SET';
    }

    public function getDescription(): string
    {
        return 'Setzt die Gericht-Positionen eines team-eigenen Pakets (Vollersatz der Liste). '
            . 'items: [{sales_recipe_id, quantity?, unit_vocab_id?, presentation_id?}]. Zum Hinzufügen/Entfernen die ganze Ziel-Liste übergeben. '
            . 'Bestehende Positionen bleiben erhalten (Menge, Einheit, Darreichung, Geschirr), solange das Item das Feld nicht mitbringt. '
            . 'presentation_id = Darreichung des Gerichts (0 = Standard); Preis und Produktion folgen ihr.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Ziel-Liste der Positionen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sales_recipe_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'number'],
                            'unit_vocab_id' => ['type' => 'integer'],
                            'presentation_id' => ['type' => 'integer', 'description' => 'Darreichung des Gerichts (0 = Standard, weglassen = unverändert).'],
                        ],
                        'required' => ['sales_recipe_id'],
                    ],
                ],
            ],
            'required' => ['paket_id', 'items'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_array($arguments['items'] ?? null)) {
            return ToolResult::error('items muss ein Array sein.', 'VALIDATION_ERROR');
        }
        $paketId = (int) ($arguments['paket_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $paketId, 'Paket')) !== null) {
            return $guard;
        }

        // Gericht-FKs team-scoped re-autorisieren (nur sichtbare Gerichte) — Batch, wirft bei fremd.
        $items = [];
        $recipeIds = [];
        foreach ($arguments['items'] as $row) {
            $rid = (int) ($row['sales_recipe_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            $recipeIds[] = $rid;
            // Nur mitgebrachte Felder setzen — fehlende bleiben an der bestehenden Position erhalten.
            $item = ['sales_recipe_id' => $rid];
            if (array_key_exists('quantity', $row)) {
                $item['quantity'] = $row['quantity'] !== null ? (float) $row['quantity'] : null;
            }
            if (array_key_exists('unit_vocab_id', $row)) {
                $item['unit_vocab_id'] = $row['unit_vocab_id'] !== null ? (int) $row['unit_vocab_id'] : null;
            }
            if (array_key_exists('presentation_id', $row)) {
                $item['presentation_id'] = (int) ($row['presentation_id'] ?? 0) > 0 ? (int) $row['presentation_id'] : null;
            }
            $items[] = $item;
        }
        try {
            if ($recipeIds !== []) {
                TeamScope::referenzen(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class, $recipeIds, $team, 'sales_recipe_id');
            }
            $paket = app(PaketService::class)->syncGerichte($team, $paketId, $items);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        }

        return ToolResult::success(['paket' => $this->paketPayload($paket, true)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'gericht', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes', 'updates'],
            'related_tools' => ['foodalchemist.paket_gerichte.MENGE', 'foodalchemist.paket_gerichte.REORDER'],
            'examples' => ['Setze für Paket 12 die Gerichte 4, 7 und 9.'],
        ];
    }
}
