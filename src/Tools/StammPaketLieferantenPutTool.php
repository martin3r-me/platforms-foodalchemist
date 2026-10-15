<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\StammPaketService;

/** Spec 82 · Lieferanten-Freischaltung eines Mandanten setzen (exakt diese Menge; nur Plattform-Admin). */
class StammPaketLieferantenPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.stamm_paket_lieferanten.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die freigeschalteten Stamm-Lieferanten eines Mandanten auf EXAKT die übergebene Menge: lieferant_ids (Liste) oder alle=true oder keine=true. '
            .'Artikel, Preise und Allergen-Sichten der Mandanten folgen sofort (auch neue Artikel freigeschalteter Lieferanten). Voraussetzung: Stamm-Paket gebucht. Wird protokolliert. '
            .'Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'team_id' => ['type' => 'integer'],
            'lieferant_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'alle' => ['type' => 'boolean'], 'keine' => ['type' => 'boolean'],
        ], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $auswahl = match (true) {
                ! empty($arguments['alle']) => 'alle',
                ! empty($arguments['keine']) => 'keine',
                isset($arguments['lieferant_ids']) && is_array($arguments['lieferant_ids']) => $arguments['lieferant_ids'],
                default => throw new \RuntimeException('lieferant_ids, alle=true oder keine=true angeben.'),
            };

            return app(StammPaketService::class)->setzeLieferanten(Team::findOrFail((int) ($arguments['team_id'] ?? 0)), $auswahl, $context->user);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['stamm_paket'], ['Schalte Hanos für Soulkitchen frei.'], ['foodalchemist.stamm_paket_lieferanten.GET']), [
            'tags' => ['foodalchemist', 'stamm_paket', 'plattform', 'lieferanten', 'write'],
        ]);
    }
}
