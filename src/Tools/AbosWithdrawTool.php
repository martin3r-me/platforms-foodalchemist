<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\AboKuendigungService;

/** Spec 82 §8 / §12 D · Master zieht ein Rezept aus einer abonnierten Sammlung zurück (Löschen ist gesperrt). */
class AbosWithdrawTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abos.WITHDRAW';
    }

    public function getDescription(): string
    {
        return 'Zieht ein Master-Rezept, -Paket oder -Konzept (typ, Standard recipe) aus einer abonnierten Sammlung zurück (ein abonniertes Objekt lässt sich nicht löschen). Jeder '
            .'betroffene Mandant läuft denselben Ablauf wie bei einer Kündigung: genutzte Objekte bekommt er samt Kette als tiefe Kopie, ungenutzte '
            .'verschwinden. Ohne bestaetigt=true nur die Vorschau je Mandant. Nur Master-Team, Rolle „Freigeben“.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'sammlung_id' => ['type' => 'integer'],
            'recipe_id' => ['type' => 'integer', 'description' => 'Arbeitszeile des Master-Objekts (so steht es in der Sammlung); bei typ paket/concept die Paket-/Konzept-ID.'],
            'typ' => ['type' => 'string', 'enum' => ['recipe', 'paket', 'concept'], 'description' => 'Art des Objekts, Standard recipe. concept schließt Paket-Konzepte (kind=paket) ein.'],
            'bestaetigt' => ['type' => 'boolean'],
        ], 'required' => ['sammlung_id', 'recipe_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(AboKuendigungService::class)->zurueckziehen($team,
            (int) ($arguments['sammlung_id'] ?? 0), (int) ($arguments['recipe_id'] ?? 0), $context->user, (bool) ($arguments['bestaetigt'] ?? false),
            in_array(($arguments['typ'] ?? 'recipe'), ['recipe', 'paket', 'concept'], true) ? (string) ($arguments['typ'] ?? 'recipe') : 'recipe'));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['abos'], ['Zieh den Kalbsjus aus der Sammlung „Basis“ zurück.'], ['foodalchemist.abos.DELETE']), [
            'tags' => ['foodalchemist', 'abos', 'kurator', 'write'], 'side_effects' => ['creates', 'updates', 'deletes'],
        ]);
    }
}
