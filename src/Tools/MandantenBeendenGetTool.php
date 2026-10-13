<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MandantBeendenService;

/**
 * Spec 82 §13 C3 · „Mandant beenden“: Vorschau (lesend). Die fünf Schritte in der festen Reihenfolge — Abos kündigen →
 * Stamm-Paket abbestellen → Datenexport → Bereiche aus → Team sperren — mit Zustand, Wirkung und Details. Nur Verwaltungsdaten
 * und Zählungen (Manifest), nie Inhalte des Mandanten (§13 G).
 */
class MandantenBeendenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandanten_beenden.GET';
    }

    public function getDescription(): string
    {
        return 'Vorschau „Mandant beenden“ für team_id (Kunden-Hauptteam): die fünf Schritte in fester Reihenfolge (abos, stamm_paket, export, '
            .'bereiche, sperren) mit zustand (offen|erledigt|entfaellt), Wirkung und Details, dazu Status, Modus, Unter-Teams, Mitglieder und '
            .'ein evtl. offener Lauf. Der Export zeigt nur eine ZÄHLUNG je Bestandteil (Manifest), nie Inhalte (Spec 82 §13 G). '
            .'Löschen der Daten gehört nicht zum Ablauf. Nur lesend; nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer', 'description' => 'Mandant (Kunden-Hauptteam).']], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            return app(MandantBeendenService::class)->vorschau(Team::findOrFail((int) ($arguments['team_id'] ?? 0)), $context->user);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['mandanten', 'beenden'], ['Was passiert, wenn wir Soulkitchen beenden?'], ['foodalchemist.mandanten.GET', 'foodalchemist.stamm_paket.GET']), [
            'tags' => ['foodalchemist', 'mandanten', 'plattform', 'beenden'],
        ]);
    }
}
