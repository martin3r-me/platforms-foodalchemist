<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;

/**
 * Spec 75a · gemeinsame Fehlerabbildung der Wareneingangs-Tools: fehlendes Recht = FORBIDDEN,
 * nicht gefunden = NOT_FOUND, fachliche Ablehnung = VALIDATION_ERROR. Die Rechte prüft der Service.
 */
abstract class WareneingangTool extends FoodAlchemistTool
{
    /** @param  callable(Team, ?int): array<string,mixed>  $fn */
    protected function ausfuehren(ToolContext $context, callable $fn): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            return ToolResult::success($fn($team, $context->user?->id));
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (ModelNotFoundException) {
            return ToolResult::error('Lieferschein, Bestellung oder Grundprodukt nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    /** @return array<string,mixed> */
    protected function metadaten(bool $schreibt, array $tags, array $beispiele, array $related = []): array
    {
        return [
            'category' => $schreibt ? 'action' : 'query',
            'tags' => array_merge(['foodalchemist', 'wareneingang'], $tags),
            'read_only' => ! $schreibt, 'idempotent' => ! $schreibt, 'risk_level' => $schreibt ? 'write' : 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => $schreibt ? ['creates', 'updates'] : [],
            'related_tools' => $related,
            'examples' => $beispiele,
        ];
    }

    /** Positions-Schema für Lieferschein-Zeilen (mit Bestellzeile ODER ohne Bestellung). */
    protected function zeilenSchema(): array
    {
        return [
            'type' => 'array',
            'description' => 'Positionen. Mit Bestellung: {order_line_id, qty_packs (gelieferte Gebinde), abweichung_grund?, note?}. '
                .'Ohne Bestellung: {gp_id, menge (kg/l/Stk), designation?, note?}. abweichung_grund: fehlt | zu_wenig | zu_viel | '
                .'ersatzartikel | beschaedigt | qualitaet | sonstiges.',
            'items' => ['type' => 'object', 'properties' => [
                'order_line_id' => ['type' => 'integer'], 'qty_packs' => ['type' => ['number', 'string']],
                'gp_id' => ['type' => 'integer'], 'menge' => ['type' => ['number', 'string']],
                'designation' => ['type' => 'string'], 'abweichung_grund' => ['type' => 'string'], 'note' => ['type' => 'string'],
            ]],
        ];
    }
}
