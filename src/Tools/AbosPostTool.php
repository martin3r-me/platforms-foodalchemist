<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\AboService;

/** Spec 82 §8 / §13 B6 · Abo vergeben: Sammlung des Master-Teams an einen Mandanten (nur Master-Team, Rolle „Freigeben“). */
class AbosPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abos.POST';
    }

    public function getDescription(): string
    {
        return 'Vergibt ein Abo: eine Sammlung des Master-Teams wird für einen Mandanten (Kunden-Hauptteam, nie Unter-Team) live '
            .'und nur lesend sichtbar. Nur im Master-Team mit FA-Rolle „Freigeben“. Setzt ein gebuchtes Stamm-Paket des Mandanten '
            .'voraus. Sichtbar werden nur veröffentlichte Fassungen samt Unter-Rezepten. Idempotent (ein Abo je Mandant und Sammlung).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'mandant_team_id' => ['type' => 'integer'],
            'sammlung_id' => ['type' => 'integer', 'description' => 'Sammlung des Master-Teams.'],
        ], 'required' => ['mandant_team_id', 'sammlung_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $mandant = Team::findOrFail((int) ($arguments['mandant_team_id'] ?? 0));
            $abo = app(AboService::class)->abonnieren($team, $mandant, (int) ($arguments['sammlung_id'] ?? 0), $context->user);

            return ['id' => (int) $abo->id, 'mandant_team_id' => (int) $abo->mandant_team_id,
                'sammlung_id' => (int) $abo->sammlung_id, 'status' => $abo->status];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['abos'], ['Gib Soulkitchen die Sammlung „Basis“ als Abo.'], ['foodalchemist.abos.GET']), [
            'tags' => ['foodalchemist', 'abos', 'kurator', 'write'],
        ]);
    }
}
