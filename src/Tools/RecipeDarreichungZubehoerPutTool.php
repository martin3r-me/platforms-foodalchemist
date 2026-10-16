<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/**
 * Zubehör & Verpackung einer Darreichung setzen/entfernen: ein Non-Food-Grundprodukt (Warengruppe 16) je Form, Menge je Einheit der Darreichung.
 * Wirkt NUR auf den EK der Darreichung (und damit VK/Marge), nie auf Allergene, Nährwerte, Profil oder Yield.
 */
class RecipeDarreichungZubehoerPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung_zubehoer.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt/entfernt Zubehör & Verpackung (z. B. Schraubglas, Box, Deko) an einer Darreichungsform. action=set mit gp_id eines '
            .'Non-Food-Grundprodukts (Warengruppe 16) und quantity (Stück je Einheit der Darreichung, Standard 1); action=remove entfernt es. '
            .'Die Kosten laufen in den EK der Darreichung ein (ek_portion, VK/Marge). Allergene, Nährwerte, Profil und Yield bleiben unberührt. '
            .'Lebensmittel gehören in die Zutaten, nicht hierher.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.'],
            'gp_id' => ['type' => 'integer', 'description' => 'Non-Food-Grundprodukt (Warengruppe 16).'],
            'quantity' => ['type' => 'number', 'description' => 'Stück je Einheit der Darreichung (bei set, Standard 1).'],
            'note' => ['type' => 'string'],
            'action' => ['type' => 'string', 'enum' => ['set', 'remove']],
        ], 'required' => ['presentation_id', 'gp_id', 'action']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, ['set', 'remove'], true)) {
            return ToolResult::error('action muss set|remove sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }
        $gpId = (int) ($arguments['gp_id'] ?? 0);
        $svc = app(DarreichungService::class);

        try {
            if ($action === 'set') {
                $svc->setzeZubehoer($team, $id, $gpId, (float) ($arguments['quantity'] ?? 1), isset($arguments['note']) ? (string) $arguments['note'] : null);
            } else {
                $svc->entferneZubehoer($team, $id, $gpId);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $kosten = $svc->zubehoerKosten(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::findOrFail($id), $team);

        return ToolResult::success(['presentation_id' => $id, 'gp_id' => $gpId, 'action' => $action, 'zubehoer' => $kosten['zeilen'], 'zubehoer_ek_je_einheit' => $kosten['summe'], 'vollstaendig' => $kosten['vollstaendig']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'darreichung', 'zubehoer', 'verpackung', 'nonfood', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'], 'related_tools' => ['foodalchemist.recipe_darreichung_delta.PUT', 'foodalchemist.nonfood.PRUEFLISTE'],
            'examples' => ['Salat im Schraubglas: Schraubglas 212 ml ×1 je Portion als Zubehör.', 'Entferne die Box aus der Darreichung 42.'],
        ];
    }
}
