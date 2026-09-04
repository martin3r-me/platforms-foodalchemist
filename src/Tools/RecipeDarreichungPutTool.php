<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/**
 * MCP-Steuerbarkeit · D3: Darreichungsform bearbeiten (Menge/Einheit/Preisklasse/Preis-Modus/VK/Notiz).
 * Der Service filtert auf die Darreichungs-Whitelist. Nur team-eigene Gerichte.
 */
class RecipeDarreichungPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine Darreichungsform. attrs: serving_form_id (wechselt die Servierform — '
            . 'der Weg aus dem Review-Zustand "unbestimmt"), quantity_per_unit_g, unit_count, markup_class_id, '
            . 'price_mode (auto|manuell), sales_net, vat_profile_key, tableware_item_id, note (nur Whitelist wirkt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.'],
                'attrs' => ['type' => 'object', 'description' => 'Zu schreibende Darreichungs-Felder.'],
            ],
            'required' => ['presentation_id', 'attrs'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $attrs = $arguments['attrs'] ?? null;
        if (! is_array($attrs) || $attrs === []) {
            return ToolResult::error('attrs muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }

        // 2026-09-04: der Formwechsel ist ein eigener Übergang (Kollisionsprüfung gegen die
        // schon vergebenen Formen), kein Attribut-Update — deshalb aus `attrs` herausgezogen.
        // Ohne diesen Weg könnte die UI mehr als das Tool.
        $formId = $attrs['serving_form_id'] ?? null;
        unset($attrs['serving_form_id']);

        $svc = app(DarreichungService::class);
        try {
            $dar = ($formId !== null && $formId !== '')
                ? $svc->setzeServierform($team, $id, (int) $formId)
                : null;
            if ($attrs !== []) {
                $dar = $svc->aktualisieren($team, $id, $attrs);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        if ($dar === null) {
            return ToolResult::error('attrs enthält kein schreibbares Feld.', 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'presentation_id' => (int) $dar->id,
            'updated' => true,
            'serving_form_id' => (int) $dar->serving_form_id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'preis', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.POST', 'foodalchemist.recipe_darreichung_delta.PUT'],
            'examples' => [
                'Setze bei Darreichung 88 den Preis-Modus auf manuell und sales_net 14.50.',
                'Stelle Darreichung 88 von "unbestimmt" auf die Servierform Teller (serving_form_id).',
            ],
        ];
    }
}
