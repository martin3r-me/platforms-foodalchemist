<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\AboKuendigungService;

/** Spec 82 §12 D · Abo kündigen: ohne bestaetigt nur Vorschau (was wird kopiert, was verschwindet), mit bestaetigt ausführen. */
class AbosDeleteTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abos.DELETE';
    }

    public function getDescription(): string
    {
        return 'Kündigt ein Abo. Ohne bestaetigt=true nur die VORSCHAU: welche abonnierten Rezepte der Mandant nutzt (aktive Foodbooks, '
            .'Speisekarten, Speisepläne, offene Angebote, Konzepte, eigene Rezepte) und deshalb als eigene, tiefe Kopie bekommt, und welche '
            .'ungenutzten verschwinden. Mit bestaetigt=true wird kopiert, lebende Verweise werden umgehängt, das Abo endet. Archivierte '
            .'Ausgaben und historische Angebote behalten ihre Fassung. Darf das Master-Team (Rolle „Freigeben“) oder das Oberteam des Mandanten (Admin).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'abo_id' => ['type' => 'integer'],
            'bestaetigt' => ['type' => 'boolean', 'description' => 'Erst nach Prüfung der Vorschau auf true setzen.'],
        ], 'required' => ['abo_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(AboKuendigungService::class)
            ->kuendigen($team, (int) ($arguments['abo_id'] ?? 0), $context->user, (bool) ($arguments['bestaetigt'] ?? false)));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['abos'], ['Was passiert, wenn Broich das Abo „Basis“ kündigt?'], ['foodalchemist.abos.GET']), [
            'tags' => ['foodalchemist', 'abos', 'kurator', 'write'], 'side_effects' => ['creates', 'updates'],
        ]);
    }
}
